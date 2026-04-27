/* ═══════════════════════════════════════════════════════════════════════════
   Log Analyzer – Frontend Application
   ═══════════════════════════════════════════════════════════════════════════ */

// ─── Global State ───────────────────────────────────────────────────────────
let state = {
    siteUrl: '',
    gmtOffset: 3,
    currentView: 'setup',
    crawlPage: 1,
    crawlSort: 'count',
    crawlSortDir: 'desc',
    charts: {},
    orphanResults: []
};

// ─── Initialization ─────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadSites();
    loadHistory();
});

async function loadSites() {
    const select = document.getElementById('site-select');
    try {
        const res = await fetch('/api/sites');
        const data = await res.json();

        if (data.success && data.sites.length > 0) {
            select.innerHTML = data.sites.map(s =>
                `<option value="${s.siteUrl}">${s.siteUrl} (${s.permissionLevel})</option>`
            ).join('');
        } else {
            select.innerHTML = '<option value="">Site bulunamadı – GSC hesabını kontrol edin</option>';
        }
    } catch (err) {
        select.innerHTML = '<option value="">Bağlantı hatası</option>';
        console.error('Site loading error:', err);
    }
}

async function loadHistory() {
    try {
        const res = await fetch('/api/history');
        const data = await res.json();

        const container = document.getElementById('history-container');
        const list = document.getElementById('history-list');

        if (data.success && data.history && data.history.length > 0) {
            if (container) container.style.display = 'block';
            if (list) {
                list.innerHTML = data.history.map(item => {
                    const date = new Date(item.created_at + 'Z');
                    const formattedDate = date.toLocaleString('tr-TR', {
                        day: 'numeric', month: 'long', year: 'numeric',
                        hour: '2-digit', minute: '2-digit'
                    });

                    return `
                        <button class="history-item btn btn-outline" style="text-align: left; justify-content: flex-start; padding: 12px; font-size: 14px; width: 100%; border: 1px solid var(--border-color); background: var(--card-bg); cursor: pointer;" 
                                onclick="loadSession('${item.db_path}', '${item.site_url}')">
                            <span style="font-weight: 500; color: var(--primary-color)">${item.site_url}</span>
                            <span style="margin: 0 8px; color: var(--border-color)">|</span>
                            <span style="color: var(--text-muted)">${formattedDate}</span>
                        </button>
                    `;
                }).join('');
            }
        }
    } catch (err) {
        console.error('History load error:', err);
    }
}

async function loadSession(dbPath, siteUrl) {
    showLoading('Geçmiş Yükleniyor...', siteUrl);
    try {
        const res = await fetch('/api/load-session', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ db_file: dbPath })
        });
        const data = await res.json();
        if (data.success) {
            state.siteUrl = siteUrl;
            document.getElementById('site-select').value = siteUrl;
            hideLoading();
            showMainApp();
        } else {
            hideLoading();
            alert('Kayıt yüklenemedi: ' + (data.error || 'Bilinmeyen hata'));
        }
    } catch (err) {
        hideLoading();
        alert('Bağlantı hatası: ' + err.message);
    }
}

// ─── View Switching ─────────────────────────────────────────────────────────
function switchView(viewName) {
    // Hide all views
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));

    // Show target
    const view = document.getElementById(`view-${viewName}`);
    if (view) view.classList.add('active');

    const tab = document.querySelector(`.nav-tab[data-view="${viewName}"]`);
    if (tab) tab.classList.add('active');

    state.currentView = viewName;

    // Load view data
    if (viewName === 'dashboard') loadDashboard();
}

// ─── Parsing ────────────────────────────────────────────────────────────────
async function startParsing() {
    const siteUrl = document.getElementById('site-select').value;
    const gmtOffset = parseFloat(document.getElementById('timezone-select').value);

    if (!siteUrl) {
        alert('Lütfen bir site seçin');
        return;
    }

    state.siteUrl = siteUrl;
    state.gmtOffset = gmtOffset;

    // Show loading
    showLoading('Loglar Yükleniyor...', 'Log dosyaları parse ediliyor');

    try {
        const res = await fetch('/api/parse-logs', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ gmt_offset: gmtOffset, site_url: siteUrl })
        });

        const data = await res.json();
        if (!data.success) {
            hideLoading();
            alert(data.error || 'Parsing başlatılamadı');
            return;
        }

        // Poll for progress
        pollParseStatus();
    } catch (err) {
        hideLoading();
        alert('Bağlantı hatası: ' + err.message);
    }
}

async function pollParseStatus() {
    const check = async () => {
        try {
            const res = await fetch('/api/parse-status');
            const data = await res.json();

            if (data.status === 'complete') {
                hideLoading();
                showMainApp();
                return;
            }

            if (data.status === 'indexing') {
                updateLoadingProgress(95,
                    `İndeksler oluşturuluyor... (${formatNumber(data.parsed_lines)} satır)`
                );
                document.getElementById('loading-title').textContent = 'İndeksler Oluşturuluyor...';
            } else if (data.status === 'parsing') {
                // Estimate total lines ~5.8 million for smoother progress bar
                // because file sizes vary wildly and there are long gaps between processed_files
                const estTotalLines = 5850000;
                let pct = Math.min(90, Math.round((data.parsed_lines / estTotalLines) * 90));

                // Fallback if parsing totally unknown
                if (data.parsed_lines === 0 && data.processed_files > 0) {
                    pct = Math.min(90, Math.round((data.processed_files / data.total_files) * 90));
                }

                updateLoadingProgress(pct,
                    `${data.processed_files}/${data.total_files} dosya – ${formatNumber(data.parsed_lines)} satır işlendi`
                );
            }

            setTimeout(check, 1000);
        } catch {
            setTimeout(check, 2000);
        }
    };
    check();
}

function showMainApp() {
    document.getElementById('view-setup').classList.remove('active');
    document.getElementById('app-header').style.display = 'flex';
    document.getElementById('site-display').textContent = state.siteUrl;
    switchView('dashboard');
}

// ─── Loading UI ─────────────────────────────────────────────────────────────
function showLoading(title, detail) {
    document.getElementById('loading-title').textContent = title;
    document.getElementById('loading-detail').textContent = detail;
    document.getElementById('loading-progress').textContent = '0%';
    document.getElementById('progress-fill').style.width = '0%';
    document.getElementById('loading-overlay').classList.add('active');
}

function updateLoadingProgress(pct, detail) {
    document.getElementById('progress-fill').style.width = pct + '%';
    document.getElementById('loading-progress').textContent = pct + '%';
    if (detail) document.getElementById('loading-detail').textContent = detail;
}

function hideLoading() {
    document.getElementById('loading-overlay').classList.remove('active');
}

// ─── Dashboard ──────────────────────────────────────────────────────────────
async function loadDashboard() {
    const botType = document.getElementById('filter-bot-type').value;
    const dateFrom = document.getElementById('filter-date-from').value;
    const dateTo = document.getElementById('filter-date-to').value;

    const params = new URLSearchParams({ bot_type: botType });
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);

    try {
        const res = await fetch(`/api/bot-stats?${params}`);
        const data = await res.json();

        if (data.success) {
            renderDashboardStats(data);
            renderDailyChart(data.daily);
            renderStatusChart(data.status_codes);
        }
    } catch (err) {
        console.error('Dashboard load error:', err);
    }

    // Fetch date range if not set
    if (!document.getElementById('filter-date-from').value) {
        try {
            const res = await fetch('/api/date-range');
            const data = await res.json();
            if (data.success) {
                document.getElementById('filter-date-from').value = data.min_date;
                document.getElementById('filter-date-to').value = data.max_date;
            }
        } catch { }
    }

    loadCrawlDetail();
}

function renderDashboardStats(data) {
    const container = document.getElementById('dashboard-stats');

    // Calculate stats
    const total = data.total;
    const status200 = data.status_codes.find(s => s.status === 200)?.count || 0;
    const status404 = data.status_codes.find(s => s.status === 404)?.count || 0;
    const status301 = data.status_codes.find(s => s.status === 301)?.count || 0;

    container.innerHTML = `
        <div class="stat-card">
            <div class="stat-label">Toplam Tarama</div>
            <div class="stat-value">${formatNumber(total)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">200 OK</div>
            <div class="stat-value success">${formatNumber(status200)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">301 Yönlendirme</div>
            <div class="stat-value warning">${formatNumber(status301)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">404 Hata</div>
            <div class="stat-value danger">${formatNumber(status404)}</div>
        </div>
        ${data.bot_totals.map(b => `
            <div class="stat-card">
                <div class="stat-label">${formatBotName(b.bot_type)}</div>
                <div class="stat-value">${formatNumber(b.count)}</div>
            </div>
        `).join('')}
    `;
}

function renderDailyChart(dailyData) {
    const ctx = document.getElementById('chart-daily').getContext('2d');

    if (state.charts.daily) state.charts.daily.destroy();

    // Group by date and bot_type
    const dates = [...new Set(dailyData.map(d => d.date))].sort();
    const botTypes = [...new Set(dailyData.map(d => d.bot_type))];

    const colors = {
        'googlebot_smartphone': '#6366f1',
        'googlebot_desktop': '#8b5cf6',
        'googlebot_image': '#a78bfa',
        'googlebot_video': '#c4b5fd',
        'googlebot_news': '#60a5fa',
        'googlebot_other': '#34d399'
    };

    const datasets = botTypes.map(bot => {
        const botData = dates.map(date => {
            const entry = dailyData.find(d => d.date === date && d.bot_type === bot);
            return entry ? entry.count : 0;
        });

        return {
            label: formatBotName(bot),
            data: botData,
            borderColor: colors[bot] || '#94a3b8',
            backgroundColor: (colors[bot] || '#94a3b8') + '20',
            fill: true,
            tension: 0.4,
            borderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 6
        };
    });

    state.charts.daily = new Chart(ctx, {
        type: 'line',
        data: { labels: dates, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            scales: {
                x: {
                    grid: { color: 'rgba(255,255,255,0.04)' },
                    ticks: { color: '#64748b', font: { size: 11 } }
                },
                y: {
                    grid: { color: 'rgba(255,255,255,0.04)' },
                    ticks: { color: '#64748b', font: { size: 11 } },
                    beginAtZero: true
                }
            },
            plugins: {
                legend: {
                    labels: { color: '#94a3b8', font: { size: 12 }, usePointStyle: true, padding: 16 }
                }
            }
        }
    });
}

function renderStatusChart(statusData) {
    const ctx = document.getElementById('chart-status').getContext('2d');

    if (state.charts.status) state.charts.status.destroy();

    const statusColors = {
        200: '#34d399',
        301: '#fbbf24',
        302: '#fb923c',
        304: '#60a5fa',
        404: '#f87171',
        500: '#ef4444',
        503: '#dc2626'
    };

    const labels = statusData.map(s => `${s.status}`);
    const values = statusData.map(s => s.count);
    const bgColors = statusData.map(s => statusColors[s.status] || '#94a3b8');

    state.charts.status = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: bgColors.map(c => c + 'CC'),
                borderColor: bgColors,
                borderWidth: 2,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: '#94a3b8', font: { size: 12 }, padding: 12, usePointStyle: true }
                }
            }
        }
    });
}

// ─── Crawl Detail Table ─────────────────────────────────────────────────────
async function loadCrawlDetail() {
    const botType = document.getElementById('filter-bot-type').value;
    const dateFrom = document.getElementById('filter-date-from').value;
    const dateTo = document.getElementById('filter-date-to').value;
    const status = document.getElementById('filter-status').value;

    const params = new URLSearchParams({
        bot_type: botType,
        page: state.crawlPage,
        per_page: 50,
        sort_by: state.crawlSort,
        sort_dir: state.crawlSortDir
    });
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    if (status) params.set('status', status);

    try {
        const res = await fetch(`/api/bot-crawl-detail?${params}`);
        const data = await res.json();

        if (data.success) {
            renderCrawlTable(data.urls);
            renderCrawlPagination(data.page, data.total_pages, data.total);
            document.getElementById('crawl-total').textContent =
                `${formatNumber(data.total)} benzersiz URL`;
        }
    } catch (err) {
        console.error('Crawl detail error:', err);
    }
}

function renderCrawlTable(urls) {
    const tbody = document.getElementById('crawl-tbody');

    if (!urls.length) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:40px;color:var(--text-muted)">Veri bulunamadı</td></tr>';
        return;
    }

    tbody.innerHTML = urls.map(u => `
        <tr>
            <td class="url-cell" data-url="${escapeHtml(u.url)}">${escapeHtml(u.url)}</td>
            <td><strong>${formatNumber(u.count)}</strong></td>
            <td>${u.last_crawl || '–'}</td>
            <td>${renderStatusBadges(u.status_codes)}</td>
        </tr>
    `).join('');
}

function renderCrawlPagination(current, totalPages, total) {
    const container = document.getElementById('crawl-pagination');
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '';
    html += `<button ${current <= 1 ? 'disabled' : ''} onclick="goToCrawlPage(${current - 1})">← Önceki</button>`;

    const start = Math.max(1, current - 2);
    const end = Math.min(totalPages, current + 2);

    if (start > 1) html += `<button onclick="goToCrawlPage(1)">1</button>`;
    if (start > 2) html += `<span class="page-info">...</span>`;

    for (let i = start; i <= end; i++) {
        html += `<button class="${i === current ? 'active' : ''}" onclick="goToCrawlPage(${i})">${i}</button>`;
    }

    if (end < totalPages - 1) html += `<span class="page-info">...</span>`;
    if (end < totalPages) html += `<button onclick="goToCrawlPage(${totalPages})">${totalPages}</button>`;

    html += `<button ${current >= totalPages ? 'disabled' : ''} onclick="goToCrawlPage(${current + 1})">Sonraki →</button>`;

    container.innerHTML = html;
}

function goToCrawlPage(page) {
    state.crawlPage = page;
    loadCrawlDetail();
}

function sortCrawlTable(column) {
    if (state.crawlSort === column) {
        state.crawlSortDir = state.crawlSortDir === 'desc' ? 'asc' : 'desc';
    } else {
        state.crawlSort = column;
        state.crawlSortDir = 'desc';
    }
    state.crawlPage = 1;
    loadCrawlDetail();
}

// ─── 404 Decline Report ─────────────────────────────────────────────────────
async function load404Decline() {
    const months = parseInt(document.getElementById('decline-months').value) || 6;
    const botType = document.getElementById('decline-bot-type').value;

    document.getElementById('decline-loading').style.display = 'block';
    document.getElementById('decline-table-container').style.display = 'none';
    document.getElementById('decline-empty').style.display = 'none';
    document.getElementById('decline-stats').innerHTML = '';

    try {
        const res = await fetch('/api/404-decline', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                site_url: state.siteUrl,
                months: months,
                bot_type: botType
            })
        });

        const data = await res.json();
        document.getElementById('decline-loading').style.display = 'none';

        if (data.success) {
            renderDeclineStats(data);
            renderDeclineTable(data.results);
        } else {
            document.getElementById('decline-empty').style.display = 'block';
            document.getElementById('decline-empty').querySelector('h3').textContent =
                data.error || 'Bir hata oluştu';
        }
    } catch (err) {
        document.getElementById('decline-loading').style.display = 'none';
        document.getElementById('decline-empty').style.display = 'block';
        document.getElementById('decline-empty').querySelector('h3').textContent =
            'Bağlantı hatası: ' + err.message;
    }
}

function renderDeclineStats(data) {
    document.getElementById('decline-stats').innerHTML = `
        <div class="stat-card">
            <div class="stat-label">Loglarda 404 URL</div>
            <div class="stat-value danger">${formatNumber(data.total_log_404s)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">GSC'de Eşleşen</div>
            <div class="stat-value warning">${formatNumber(data.matched_with_gsc)}</div>
        </div>
    `;
}

function renderDeclineTable(results) {
    const tbody = document.getElementById('decline-tbody');
    const container = document.getElementById('decline-table-container');
    const empty = document.getElementById('decline-empty');

    if (!results || !results.length) {
        container.style.display = 'none';
        empty.style.display = 'block';
        empty.querySelector('h3').textContent = 'Eşleşen sayfa bulunamadı';
        empty.querySelector('p').textContent =
            'Loglardaki 404 sayfalar GSC verilerinde gösterim almamış';
        return;
    }

    container.style.display = 'block';
    empty.style.display = 'none';

    tbody.innerHTML = results.map(r => `
        <tr>
            <td class="url-cell" data-url="${escapeHtml(r.url)}">${escapeHtml(r.url)}</td>
            <td><strong>${formatNumber(r.impressions)}</strong></td>
            <td>${formatNumber(r.clicks)}</td>
            <td>${r.ctr}%</td>
            <td>${r.position}</td>
            <td><span class="badge badge-danger">${r.log_404_count}</span></td>
            <td>${r.first_seen_404 || '–'}</td>
            <td>${r.last_seen_404 || '–'}</td>
        </tr>
    `).join('');
}

// ─── Orphan Pages ───────────────────────────────────────────────────────────
async function findOrphanPages() {
    const sitemapsText = document.getElementById('orphan-sitemaps').value.trim();
    const botType = document.getElementById('orphan-bot-type').value;

    if (!sitemapsText) {
        alert('Lütfen en az bir Sitemap URL\'si girin');
        return;
    }

    const sitemapUrls = sitemapsText.split('\n')
        .map(u => u.trim())
        .filter(u => u.length > 0 && (u.startsWith('http://') || u.startsWith('https://')));

    if (sitemapUrls.length === 0) {
        alert('Lütfen geçerli URL\'ler girin (http:// veya https:// ile başlamalı)');
        return;
    }

    document.getElementById('orphan-loading').style.display = 'block';
    document.getElementById('orphan-table-container').style.display = 'none';
    document.getElementById('orphan-empty').style.display = 'none';
    document.getElementById('orphan-stats').innerHTML = '';

    try {
        const res = await fetch('/api/orphan-pages', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sitemap_urls: sitemapUrls, bot_type: botType })
        });

        const data = await res.json();
        document.getElementById('orphan-loading').style.display = 'none';

        if (data.success) {
            state.orphanResults = data.results;
            renderOrphanStats(data);
            renderOrphanTable(data.results);
        } else {
            document.getElementById('orphan-empty').style.display = 'block';
            document.getElementById('orphan-empty').querySelector('h3').textContent =
                data.error || 'Bir hata oluştu';
        }
    } catch (err) {
        document.getElementById('orphan-loading').style.display = 'none';
        document.getElementById('orphan-empty').style.display = 'block';
        document.getElementById('orphan-empty').querySelector('h3').textContent =
            'Bağlantı hatası: ' + err.message;
    }
}

function renderOrphanStats(data) {
    const pct = data.total > 0 ? Math.round((data.orphan_count / data.total) * 100) : 0;

    document.getElementById('orphan-stats').innerHTML = `
        <div class="stat-card">
            <div class="stat-label">Toplam URL</div>
            <div class="stat-value">${formatNumber(data.total)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Orphan (Taranmayan)</div>
            <div class="stat-value danger">${formatNumber(data.orphan_count)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Taranan</div>
            <div class="stat-value success">${formatNumber(data.crawled_count)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Orphan Oranı</div>
            <div class="stat-value ${pct > 50 ? 'danger' : pct > 20 ? 'warning' : 'success'}">${pct}%</div>
        </div>
    `;
}

function renderOrphanTable(results) {
    const tbody = document.getElementById('orphan-tbody');
    const container = document.getElementById('orphan-table-container');
    const empty = document.getElementById('orphan-empty');

    if (!results || !results.length) {
        container.style.display = 'none';
        empty.style.display = 'block';
        return;
    }

    container.style.display = 'block';
    empty.style.display = 'none';
    document.getElementById('orphan-export-btn').style.display = 'block';

    tbody.innerHTML = results.map(r => `
        <tr class="${r.status === 'orphan' ? 'orphan-row' : 'crawled-row'}">
            <td>
                ${r.status === 'orphan'
            ? '<span class="badge badge-danger">👻 Orphan</span>'
            : '<span class="badge badge-success">✅ Taranan</span>'}
            </td>
            <td class="url-cell" data-url="${escapeHtml(r.url)}">${escapeHtml(r.url)}</td>
            <td>${r.crawl_count || '0'}</td>
            <td>${r.last_crawl || '–'}</td>
            <td>${r.first_crawl || '–'}</td>
            <td>${r.status_codes ? renderStatusBadges(r.status_codes) : '–'}</td>
        </tr>
    `).join('');
}

function exportOrphan() {
    if (!state.orphanResults.length) return;

    const headers = ['Durum', 'URL', 'Tarama Sayısı', 'Son Tarama', 'İlk Tarama', 'Durum Kodları'];
    const rows = state.orphanResults.map(r => [
        r.status === 'orphan' ? 'Orphan' : 'Taranan',
        r.url,
        r.crawl_count || 0,
        r.last_crawl || '',
        r.first_crawl || '',
        r.status_codes || ''
    ]);

    let csv = headers.join(',') + '\n';
    csv += rows.map(r => r.map(v => `"${v}"`).join(',')).join('\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'orphan_pages.csv';
    a.click();
    URL.revokeObjectURL(url);
}

// ─── Helpers ────────────────────────────────────────────────────────────────
function formatNumber(n) {
    if (n == null) return '0';
    return new Intl.NumberFormat('tr-TR').format(n);
}

function formatBotName(bot) {
    const names = {
        'googlebot_desktop': 'Googlebot Desktop',
        'googlebot_smartphone': 'Googlebot Smartphone',
        'googlebot_image': 'Googlebot Image',
        'googlebot_video': 'Googlebot Video',
        'googlebot_news': 'Googlebot News',
        'googlebot_other': 'Google Diğer',
        'bingbot': 'Bingbot',
        'yandexbot': 'YandexBot',
        'other_bot': 'Diğer Botlar',
        'human': 'İnsan'
    };
    return names[bot] || bot;
}

function renderStatusBadges(statusCodes) {
    if (!statusCodes) return '';
    const codes = statusCodes.split(',');
    return codes.map(code => {
        const c = parseInt(code);
        let cls = 'badge-neutral';
        if (c >= 200 && c < 300) cls = 'badge-success';
        else if (c >= 300 && c < 400) cls = 'badge-warning';
        else if (c >= 400 && c < 500) cls = 'badge-danger';
        else if (c >= 500) cls = 'badge-danger';
        return `<span class="badge ${cls}">${c}</span>`;
    }).join(' ');
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
