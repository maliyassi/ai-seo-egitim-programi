"""
Flask Application - Plesk Log Analyzer
Main entry point with all API endpoints.
"""

import os
import threading
from flask import Flask, jsonify, request, render_template, send_from_directory
from log_parser import parse_logs, get_parse_status, query_db
import gsc_api
import sqlite3
import requests
import xml.etree.ElementTree as ET
from datetime import datetime

# ─── APP DATABASE FOR HISTORY ──────────────────────────────────────────────────
import os
APP_DB_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'app.db')

def init_app_db():
    conn = sqlite3.connect(APP_DB_PATH)
    conn.execute('''
        CREATE TABLE IF NOT EXISTS scan_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_url TEXT NOT NULL,
            db_path TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ''')
    conn.commit()
    conn.close()

init_app_db()

def parse_sitemap(url, visited=None):
    """Recursively parse an XML sitemap or sitemap index to extract all URLs."""
    if visited is None:
        visited = set()
    if url in visited:
        return []
    visited.add(url)
    
    try:
        headers = {'User-Agent': 'Mozilla/5.0 (compatible; LogAnalyzer/1.0)'}
        resp = requests.get(url, timeout=15, headers=headers)
        resp.raise_for_status()
        
        root = ET.fromstring(resp.content)
        
        # Find namespace
        ns = ''
        if '}' in root.tag:
            ns = root.tag.split('}')[0] + '}'
            
        urls = []
        if root.tag.endswith('sitemapindex'):
            for sitemap in root.findall(f'.//{ns}sitemap'):
                loc = sitemap.find(f'{ns}loc')
                if loc is not None and loc.text:
                    urls.extend(parse_sitemap(loc.text.strip(), visited))
        elif root.tag.endswith('urlset'):
            for url_tag in root.findall(f'.//{ns}url'):
                loc = url_tag.find(f'{ns}loc')
                if loc is not None and loc.text:
                    urls.append(loc.text.strip())
        return urls
    except Exception as e:
        print(f"Error parsing sitemap {url}: {e}")
        return []

app = Flask(__name__, 
            static_folder='static',
            template_folder='templates')

# ─── Pages ───────────────────────────────────────────────────────────────────

@app.route('/')
def index():
    return render_template('index.html')


# ─── GSC API ─────────────────────────────────────────────────────────────────

@app.route('/api/sites')
def api_sites():
    """Get list of GSC sites."""
    try:
        sites = gsc_api.get_sites()
        return jsonify({'success': True, 'sites': sites})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500


# ─── Log Parsing ─────────────────────────────────────────────────────────────

@app.route('/api/parse-logs', methods=['POST'])
def api_parse_logs():
    """Start parsing logs in background thread."""
    data = request.get_json() or {}
    gmt_offset = data.get('gmt_offset', 3)  # Default +3 for Turkey
    site_url = data.get('site_url', 'Unknown Site')
    
    # Check if already parsing
    status = get_parse_status()
    if status.get('status') == 'parsing':
        return jsonify({'success': False, 'error': 'Parsing already in progress'}), 400
        
    # Generate new db filename
    timestamp = int(datetime.now().timestamp())
    db_filename = f"scan_{timestamp}.db"
    db_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'scans', db_filename)
    
    import log_parser
    log_parser.set_active_db(db_path)
    
    # Insert history
    conn = sqlite3.connect(APP_DB_PATH)
    conn.execute("INSERT INTO scan_history (site_url, db_path) VALUES (?, ?)", (site_url, db_filename))
    conn.commit()
    conn.close()
    
    # Write a flag file so polling knows parsing has started (avoids DB lock race)
    flag_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.parsing')
    with open(flag_path, 'w') as f:
        f.write('parsing')
    
    def do_parse():
        try:
            parse_logs(gmt_offset=gmt_offset)
        finally:
            # Remove flag file when done
            try:
                os.unlink(flag_path)
            except:
                pass
    
    thread = threading.Thread(target=do_parse)
    thread.daemon = True
    thread.start()
    
    return jsonify({'success': True, 'message': 'Parsing started'})


@app.route('/api/parse-status')
def api_parse_status():
    """Get current parsing status."""
    status = get_parse_status()
    return jsonify({'success': True, **status})


@app.route('/api/history')
def api_history():
    """Get history of past scans."""
    try:
        conn = sqlite3.connect(APP_DB_PATH)
        conn.row_factory = sqlite3.Row
        c = conn.cursor()
        c.execute("SELECT * FROM scan_history ORDER BY created_at DESC")
        history = [dict(row) for row in c.fetchall()]
        conn.close()
        return jsonify({'success': True, 'history': history})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500


@app.route('/api/load-session', methods=['POST'])
def api_load_session():
    """Load a past scan database into active context."""
    data = request.get_json() or {}
    db_filename = data.get('db_file')
    if not db_filename:
        return jsonify({'success': False, 'error': 'db_file required'}), 400
        
    db_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'scans', db_filename)
    if not os.path.exists(db_path):
        return jsonify({'success': False, 'error': 'Database file not found'}), 404
        
    import log_parser
    log_parser.set_active_db(db_path)
    return jsonify({'success': True})


# ─── Bot Stats ───────────────────────────────────────────────────────────────

@app.route('/api/bot-stats')
def api_bot_stats():
    """Get bot crawl statistics with optional filters."""
    bot_type = request.args.get('bot_type', '')
    date_from = request.args.get('date_from', '')
    date_to = request.args.get('date_to', '')
    
    # Base conditions
    conditions = []
    params = []
    
    if bot_type:
        if bot_type == 'googlebot_all':
            conditions.append("bot_type LIKE 'googlebot%'")
        else:
            conditions.append("bot_type = ?")
            params.append(bot_type)
    else:
        conditions.append("bot_type LIKE 'googlebot%'")
    
    if date_from:
        conditions.append("date >= ?")
        params.append(date_from)
    if date_to:
        conditions.append("date <= ?")
        params.append(date_to)
    
    where = " AND ".join(conditions) if conditions else "1=1"
    
    # Daily crawl volume
    daily = query_db(f'''
        SELECT date, bot_type, COUNT(*) as count
        FROM log_entries
        WHERE {where}
        GROUP BY date, bot_type
        ORDER BY date
    ''', tuple(params))
    
    # Status code breakdown
    status_codes = query_db(f'''
        SELECT status, COUNT(*) as count
        FROM log_entries
        WHERE {where}
        GROUP BY status
        ORDER BY count DESC
    ''', tuple(params))
    
    # Bot type totals
    bot_totals = query_db(f'''
        SELECT bot_type, COUNT(*) as count
        FROM log_entries
        WHERE {where}
        GROUP BY bot_type
        ORDER BY count DESC
    ''', tuple(params))
    
    # Total count
    total = query_db(f'''
        SELECT COUNT(*) as total FROM log_entries WHERE {where}
    ''', tuple(params))
    
    return jsonify({
        'success': True,
        'daily': daily,
        'status_codes': status_codes,
        'bot_totals': bot_totals,
        'total': total[0]['total'] if total else 0
    })


@app.route('/api/bot-crawl-detail')
def api_bot_crawl_detail():
    """Get detailed crawl data for specific bot type."""
    bot_type = request.args.get('bot_type', '')
    date_from = request.args.get('date_from', '')
    date_to = request.args.get('date_to', '')
    status_filter = request.args.get('status', '')
    page = int(request.args.get('page', 1))
    per_page = int(request.args.get('per_page', 50))
    sort_by = request.args.get('sort_by', 'count')
    sort_dir = request.args.get('sort_dir', 'desc')
    
    conditions = []
    params = []
    
    if bot_type:
        if bot_type == 'googlebot_all':
            conditions.append("bot_type LIKE 'googlebot%'")
        else:
            conditions.append("bot_type = ?")
            params.append(bot_type)
    else:
        conditions.append("bot_type LIKE 'googlebot%'")
    
    if date_from:
        conditions.append("date >= ?")
        params.append(date_from)
    if date_to:
        conditions.append("date <= ?")
        params.append(date_to)
    if status_filter:
        conditions.append("status = ?")
        params.append(int(status_filter))
    
    where = " AND ".join(conditions) if conditions else "1=1"
    
    # Validate sort column
    valid_sorts = {'url': 'url', 'count': 'count', 'last_crawl': 'last_crawl', 'status': 'most_common_status'}
    sort_col = valid_sorts.get(sort_by, 'count')
    sort_direction = 'DESC' if sort_dir == 'desc' else 'ASC'
    
    offset = (page - 1) * per_page
    
    # Top crawled URLs (simplified – no correlated subquery)
    urls = query_db(f'''
        SELECT url,
               COUNT(*) as count,
               MAX(datetime) as last_crawl,
               GROUP_CONCAT(DISTINCT status) as status_codes
        FROM log_entries
        WHERE {where}
        GROUP BY url
        ORDER BY {sort_col} {sort_direction}
        LIMIT ? OFFSET ?
    ''', tuple(params) + (per_page, offset))
    
    # Total unique URLs
    count_result = query_db(f'''
        SELECT COUNT(DISTINCT url) as total FROM log_entries WHERE {where}
    ''', tuple(params))
    
    total_urls = count_result[0]['total'] if count_result else 0
    
    return jsonify({
        'success': True,
        'urls': urls,
        'total': total_urls,
        'page': page,
        'per_page': per_page,
        'total_pages': (total_urls + per_page - 1) // per_page
    })


# ─── 404 Decline Report ─────────────────────────────────────────────────────

@app.route('/api/404-decline', methods=['POST'])
def api_404_decline():
    """Find pages with GSC impressions that are returning 404 in logs."""
    data = request.get_json() or {}
    site_url = data.get('site_url', '')
    months = data.get('months', 6)
    bot_type = data.get('bot_type', 'googlebot_all')
    
    if not site_url:
        return jsonify({'success': False, 'error': 'site_url is required'}), 400
    
    # Get 404 URLs from logs (Google bots only)
    conditions = ["status = 404"]
    if bot_type == 'googlebot_all':
        conditions.append("bot_type LIKE 'googlebot%'")
    else:
        conditions.append(f"bot_type = '{bot_type}'")
    
    where = " AND ".join(conditions)
    
    log_404s = query_db(f'''
        SELECT url, 
               COUNT(*) as count,
               MIN(datetime) as first_seen,
               MAX(datetime) as last_seen
        FROM log_entries
        WHERE {where}
        GROUP BY url
        ORDER BY count DESC
    ''')
    
    if not log_404s:
        return jsonify({
            'success': True,
            'results': [],
            'message': 'No persistent 404 URLs found in Google bot logs'
        })
    
    # Cross-reference with GSC
    try:
        results = gsc_api.find_declining_404_pages(
            site_url=site_url,
            log_404_urls=log_404s,
            months=months
        )
        
        return jsonify({
            'success': True,
            'results': results,
            'total_log_404s': len(log_404s),
            'matched_with_gsc': len(results)
        })
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)}), 500


# ─── Orphan Pages ────────────────────────────────────────────────────────────

@app.route('/api/orphan-pages', methods=['POST'])
def api_orphan_pages():
    """Find pages from sitemaps that Google hasn't crawled recently."""
    data = request.get_json() or {}
    sitemap_urls_input = data.get('sitemap_urls', [])
    bot_type = data.get('bot_type', 'googlebot_all')
    
    extracted_urls = []
    for sitemap_url in sitemap_urls_input:
        if sitemap_url:
            parsed = parse_sitemap(sitemap_url)
            if parsed:
                extracted_urls.extend(parsed)
                
    if not extracted_urls:
        return jsonify({'success': False, 'error': 'No valid URLs could be extracted from the provided sitemaps'}), 400
    
    # Normalize sitemap URLs to paths
    from urllib.parse import urlparse
    url_map = {}
    for url in extracted_urls:
        url = url.strip()
        if not url:
            continue
        parsed = urlparse(url)
        path = parsed.path
        if not path.endswith('/'):
            path += '/'
        # Store both with and without trailing slash
        url_map[path] = url
        url_map[path.rstrip('/')] = url
    
    if not url_map:
        return jsonify({'success': False, 'error': 'No valid URLs provided'}), 400
    
    # Bot filter
    if bot_type == 'googlebot_all':
        bot_condition = "bot_type LIKE 'googlebot%'"
    else:
        bot_condition = f"bot_type = '{bot_type}'"
    
    # Get crawl data for all known paths in chunks to avoid SQLite's variable limit
    paths = list(url_map.keys())
    chunk_size = 900
    crawled = []
    
    for i in range(0, len(paths), chunk_size):
        chunk = paths[i:i + chunk_size]
        placeholders = ','.join('?' * len(chunk))
        
        chunk_crawled = query_db(f'''
            SELECT url,
                   COUNT(*) as crawl_count,
                   MAX(datetime) as last_crawl,
                   MIN(datetime) as first_crawl,
                   GROUP_CONCAT(DISTINCT status) as status_codes
            FROM log_entries
            WHERE {bot_condition} AND url IN ({placeholders})
            GROUP BY url
        ''', tuple(chunk))
        
        crawled.extend(chunk_crawled)
    
    # Build crawled lookup
    crawled_lookup = {}
    for c in crawled:
        crawled_lookup[c['url']] = c
    
    # Find orphan pages (not crawled)
    results = []
    seen_urls = set()
    
    for path, full_url in url_map.items():
        if full_url in seen_urls:
            continue
        
        crawl_data = crawled_lookup.get(path) or crawled_lookup.get(path.rstrip('/')) or crawled_lookup.get(path + '/')
        
        if crawl_data:
            results.append({
                'url': full_url,
                'path': path,
                'status': 'crawled',
                'crawl_count': crawl_data['crawl_count'],
                'last_crawl': crawl_data['last_crawl'],
                'first_crawl': crawl_data['first_crawl'],
                'status_codes': crawl_data['status_codes']
            })
        else:
            results.append({
                'url': full_url,
                'path': path,
                'status': 'orphan',
                'crawl_count': 0,
                'last_crawl': None,
                'first_crawl': None,
                'status_codes': None
            })
        
        seen_urls.add(full_url)
    
    # Sort: orphans first, then by crawl count ascending
    results.sort(key=lambda x: (0 if x['status'] == 'orphan' else 1, x['crawl_count']))
    
    orphan_count = sum(1 for r in results if r['status'] == 'orphan')
    
    return jsonify({
        'success': True,
        'results': results,
        'total': len(results),
        'orphan_count': orphan_count,
        'crawled_count': len(results) - orphan_count
    })


# ─── Date Range ──────────────────────────────────────────────────────────────

@app.route('/api/date-range')
def api_date_range():
    """Get the date range available in parsed logs."""
    db_path = get_db_path()
    if not os.path.exists(db_path):
        return jsonify({'success': False, 'error': 'No logs parsed yet'})
    
    result = query_db('''
        SELECT MIN(date) as min_date, MAX(date) as max_date, COUNT(*) as total
        FROM log_entries
    ''')
    
    if result:
        return jsonify({
            'success': True,
            'min_date': result[0]['min_date'],
            'max_date': result[0]['max_date'],
            'total_entries': result[0]['total']
        })
    
    return jsonify({'success': False, 'error': 'No data'})


if __name__ == '__main__':
    os.makedirs('templates', exist_ok=True)
    os.makedirs('static', exist_ok=True)
    app.run(debug=True, port=5001)
