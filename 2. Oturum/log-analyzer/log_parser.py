"""
Plesk Log Parser Module – Optimized
Parses Apache Combined Log Format from .gz and plain text files.
Uses deferred indexing, WAL mode, large batches, and fast datetime parsing.
"""

import re
import gzip
import os
import sqlite3
from datetime import datetime, timedelta, timezone

# Apache Combined Log Format regex (non-named groups for speed)
LOG_PATTERN = re.compile(
    r'(\S+)\s+\S+\s+\S+\s+'
    r'\[([^\]]+)\]\s+'
    r'"(\S+)\s+(\S+)\s+\S+"\s+'
    r'(\d{3})\s+(\S+)\s+'
    r'"([^"]*)"\s+'
    r'"([^"]*)"'
)

# Pre-compiled bot patterns
BOT_PATTERNS_COMPILED = [
    ('googlebot_smartphone', re.compile(r'Googlebot/2\.1.*Mobile', re.IGNORECASE)),
    ('googlebot_desktop', re.compile(r'Googlebot/2\.1(?!.*Mobile)', re.IGNORECASE)),
    ('googlebot_image', re.compile(r'Googlebot-Image', re.IGNORECASE)),
    ('googlebot_video', re.compile(r'Googlebot-Video', re.IGNORECASE)),
    ('googlebot_news', re.compile(r'Googlebot-News', re.IGNORECASE)),
    ('googlebot_other', re.compile(r'Google(?!bot).*bot|APIs-Google|AdsBot-Google|Mediapartners-Google|Storebot-Google', re.IGNORECASE)),
    ('bingbot', re.compile(r'bingbot', re.IGNORECASE)),
    ('yandexbot', re.compile(r'YandexBot', re.IGNORECASE)),
    ('other_bot', re.compile(
        r'bot|crawler|spider|crawl|slurp|scraper|fetcher|'
        r'DataForSeoBot|AhrefsBot|SemrushBot|Barkrowler|PetalBot|'
        r'Applebot|ClaudeBot|Bytespider|facebookexternalhit|WhatsApp',
        re.IGNORECASE
    )),
]

MONTHS = {
    'Jan': 1, 'Feb': 2, 'Mar': 3, 'Apr': 4, 'May': 5, 'Jun': 6,
    'Jul': 7, 'Aug': 8, 'Sep': 9, 'Oct': 10, 'Nov': 11, 'Dec': 12
}

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
SCANS_DIR = os.path.join(BASE_DIR, 'scans')
os.makedirs(SCANS_DIR, exist_ok=True)
ACTIVE_DB = os.path.join(BASE_DIR, 'logs.db')
LOGS_DIR = os.path.join(BASE_DIR, 'logs')
FLAG_PATH = os.path.join(BASE_DIR, '.parsing')


def set_active_db(path):
    global ACTIVE_DB
    ACTIVE_DB = path


def get_db_path():
    return ACTIVE_DB


def classify_bot(user_agent: str) -> str:
    for bot_name, pattern in BOT_PATTERNS_COMPILED:
        if pattern.search(user_agent):
            return bot_name
    return 'human'


def parse_datetime_fast(dt_str: str, gmt_offset: int = 0) -> tuple:
    """Fast manual datetime parsing. Returns (datetime_str, date_str)."""
    try:
        day = int(dt_str[0:2])
        month = MONTHS.get(dt_str[3:6], 1)
        year = int(dt_str[7:11])
        hour = int(dt_str[12:14])
        minute = int(dt_str[15:17])
        second = int(dt_str[18:20])
        
        tz_str = dt_str[21:26]
        tz_sign = 1 if tz_str[0] == '+' else -1
        tz_hours = int(tz_str[1:3])
        tz_mins = int(tz_str[3:5])
        src_offset_minutes = tz_sign * (tz_hours * 60 + tz_mins)
        target_offset_minutes = int(gmt_offset * 60)
        
        if src_offset_minutes != target_offset_minutes:
            total_mins = (hour * 60 + minute) + (target_offset_minutes - src_offset_minutes)
            extra_days = 0
            if total_mins < 0:
                extra_days = -1
                total_mins += 1440
            elif total_mins >= 1440:
                extra_days = 1
                total_mins -= 1440
            hour = total_mins // 60
            minute = total_mins % 60
            if extra_days != 0:
                from datetime import date as dt_date
                d = dt_date(year, month, day) + timedelta(days=extra_days)
                year, month, day = d.year, d.month, d.day
        
        return (f"{year:04d}-{month:02d}-{day:02d} {hour:02d}:{minute:02d}:{second:02d}",
                f"{year:04d}-{month:02d}-{day:02d}")
    except (ValueError, IndexError, KeyError):
        return dt_str, dt_str[:10] if len(dt_str) >= 10 else dt_str


def init_db_minimal():
    """Initialize SQLite database with tables only (no indexes)."""
    conn = sqlite3.connect(get_db_path())
    c = conn.cursor()
    c.execute('DROP TABLE IF EXISTS log_entries')
    c.execute('DROP TABLE IF EXISTS parse_status')
    
    c.execute('''
        CREATE TABLE log_entries (
            ip TEXT, datetime TEXT, date TEXT, method TEXT,
            url TEXT, status INTEGER, size INTEGER, referrer TEXT,
            user_agent TEXT, bot_type TEXT
        )
    ''')
    c.execute('''
        CREATE TABLE parse_status (
            id INTEGER PRIMARY KEY DEFAULT 1,
            total_files INTEGER DEFAULT 0,
            processed_files INTEGER DEFAULT 0,
            total_lines INTEGER DEFAULT 0,
            parsed_lines INTEGER DEFAULT 0,
            status TEXT DEFAULT 'idle',
            started_at TEXT,
            completed_at TEXT
        )
    ''')
    c.execute('INSERT INTO parse_status (id, status) VALUES (1, ?)', ('idle',))
    conn.commit()
    conn.close()


def create_indexes():
    """Create indexes after all data is inserted."""
    conn = sqlite3.connect(get_db_path())
    c = conn.cursor()
    c.execute('CREATE INDEX IF NOT EXISTS idx_bot_type ON log_entries(bot_type)')
    c.execute('CREATE INDEX IF NOT EXISTS idx_status ON log_entries(status)')
    c.execute('CREATE INDEX IF NOT EXISTS idx_date ON log_entries(date)')
    c.execute('CREATE INDEX IF NOT EXISTS idx_url ON log_entries(url)')
    c.execute('CREATE INDEX IF NOT EXISTS idx_bot_status ON log_entries(bot_type, status)')
    c.execute('CREATE INDEX IF NOT EXISTS idx_bot_date ON log_entries(bot_type, date)')
    c.execute('CREATE INDEX IF NOT EXISTS idx_url_status ON log_entries(url, status)')
    conn.commit()
    conn.close()


def get_log_files() -> list:
    files = []
    if not os.path.exists(LOGS_DIR):
        return files
    for f in sorted(os.listdir(LOGS_DIR)):
        full = os.path.join(LOGS_DIR, f)
        if os.path.isfile(full):
            if f.endswith('.gz') or (f.startswith('access_ssl_log') and not f.endswith('.webstat')):
                if not f.endswith('.gz'):
                    if os.path.getsize(full) == 0:
                        continue
                files.append(full)
    return files


def parse_logs(gmt_offset: int = 0, callback=None):
    """Parse all log files sequentially with optimized SQLite writes.
    
    Optimizations:
    - Deferred indexing (indexes created after all inserts)
    - WAL mode + synchronous OFF
    - No AUTOINCREMENT
    - 50K batch inserts
    - Fast manual datetime parsing
    """
    init_db_minimal()
    
    log_files = get_log_files()
    total_files = len(log_files)
    
    if total_files == 0:
        return 0
    
    # Open connection with speed optimizations
    conn = sqlite3.connect(get_db_path())
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA synchronous=OFF")
    conn.execute("PRAGMA cache_size=-64000")
    c = conn.cursor()
    
    c.execute('''
        UPDATE parse_status SET 
            total_files = ?, processed_files = 0, 
            total_lines = 0, parsed_lines = 0,
            status = 'parsing', started_at = ?, completed_at = NULL
        WHERE id = 1
    ''', (total_files, datetime.now().isoformat()))
    conn.commit()
    
    total_parsed = 0
    batch = []
    batch_size = 50000
    match = LOG_PATTERN.match  # local ref for speed
    
    for file_idx, filepath in enumerate(log_files):
        opener = gzip.open if filepath.endswith('.gz') else open
        
        try:
            with opener(filepath, 'rt', encoding='utf-8', errors='replace') as f:
                for line in f:
                    line = line.strip()
                    if not line:
                        continue
                    
                    m = match(line)
                    if not m:
                        continue
                    
                    ip, dt_str, method, url, status, size, referrer, user_agent = m.groups()
                    size_int = int(size) if size != '-' else 0
                    status_int = int(status)
                    bot_type = classify_bot(user_agent)
                    dt_formatted, date_formatted = parse_datetime_fast(dt_str, gmt_offset)
                    
                    batch.append((ip, dt_formatted, date_formatted, method, url,
                                  status_int, size_int, referrer, user_agent, bot_type))
                    total_parsed += 1
                    
                    if len(batch) >= batch_size:
                        c.executemany(
                            'INSERT INTO log_entries VALUES (?,?,?,?,?,?,?,?,?,?)',
                            batch
                        )
                        conn.commit()
                        batch = []
                        
                        c.execute('''
                            UPDATE parse_status SET processed_files = ?, parsed_lines = ?
                            WHERE id = 1
                        ''', (file_idx, total_parsed))
                        conn.commit()
        
        except Exception as e:
            print(f"Error parsing {filepath}: {e}")
            continue
        
        # Update after each file
        c.execute('''
            UPDATE parse_status SET processed_files = ?, parsed_lines = ?
            WHERE id = 1
        ''', (file_idx + 1, total_parsed))
        conn.commit()
        print(f"  File {file_idx+1}/{total_files} done – {total_parsed:,} total lines")
    
    # Insert remaining
    if batch:
        c.executemany('INSERT INTO log_entries VALUES (?,?,?,?,?,?,?,?,?,?)', batch)
        conn.commit()
    
    conn.close()
    
    # Update status to indexing
    conn = sqlite3.connect(get_db_path())
    conn.execute('''
        UPDATE parse_status SET 
            status = 'indexing', processed_files = ?, parsed_lines = ?
        WHERE id = 1
    ''', (total_files, total_parsed))
    conn.commit()
    conn.close()
    
    # Create indexes after all inserts
    print("Creating indexes...")
    create_indexes()
    
    # Mark complete
    conn = sqlite3.connect(get_db_path())
    conn.execute('''
        UPDATE parse_status SET 
            status = 'complete', completed_at = ?,
            total_lines = ?, parsed_lines = ?
        WHERE id = 1
    ''', (datetime.now().isoformat(), total_parsed, total_parsed))
    conn.commit()
    conn.close()
    
    # Clean up flag file
    try:
        os.unlink(FLAG_PATH)
    except:
        pass
    
    print(f"Parse complete: {total_parsed:,} lines in {total_files} files")
    return total_parsed


def get_parse_status() -> dict:
    """Get current parsing status. Checks flag file for race condition prevention."""
    # If flag file exists but DB says idle, return parsing
    flag_exists = os.path.exists(FLAG_PATH)
    
    db_path = get_db_path()
    if not os.path.exists(db_path):
        if flag_exists:
            return {'status': 'parsing', 'total_files': 0, 'processed_files': 0, 'parsed_lines': 0}
        return {'status': 'idle', 'total_files': 0, 'processed_files': 0, 'parsed_lines': 0}
    
    conn = sqlite3.connect(db_path)
    c = conn.cursor()
    try:
        c.execute('SELECT * FROM parse_status WHERE id = 1')
        row = c.fetchone()
        if row:
            result = {
                'total_files': row[1],
                'processed_files': row[2],
                'total_lines': row[3],
                'parsed_lines': row[4],
                'status': row[5],
                'started_at': row[6],
                'completed_at': row[7]
            }
            # If flag file exists, override status to parsing
            if flag_exists and result['status'] != 'parsing':
                result['status'] = 'parsing'
            return result
    except:
        pass
    finally:
        conn.close()
    
    if flag_exists:
        return {'status': 'parsing', 'total_files': 0, 'processed_files': 0, 'parsed_lines': 0}
    return {'status': 'idle'}


def query_db(query: str, params: tuple = ()) -> list:
    conn = sqlite3.connect(get_db_path())
    conn.row_factory = sqlite3.Row
    c = conn.cursor()
    c.execute(query, params)
    results = [dict(row) for row in c.fetchall()]
    conn.close()
    return results
