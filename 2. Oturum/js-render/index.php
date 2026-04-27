<?php
// ═══════════════════════════════════════════════════════════════
//  JS Rendering SEO Analyzer  –  PHP + Node.js + Playwright
set_time_limit(120); // analyzer can take up to ~45s + processing
// ═══════════════════════════════════════════════════════════════

$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['url'])) {
    $url = filter_var(trim($_POST['url']), FILTER_SANITIZE_URL);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $error = 'Geçersiz URL. Lütfen https:// ile başlayan tam bir URL girin.';
    } else {
        $escaped = escapeshellarg($url);
        $nodeCmd = 'cd ' . escapeshellarg(__DIR__) . ' && /usr/local/bin/node analyzer.js ' . $escaped . ' 2>&1';
        $jsonRaw = shell_exec($nodeCmd);
        $decoded = json_decode($jsonRaw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($decoded['error'])) $error = $decoded['error'];
            else $result = $decoded;
        } else {
            $error = 'Analyzer çıktısı okunamadı. Çıktı: ' . htmlspecialchars(substr($jsonRaw ?? '', 0, 600));
        }
    }
}

function severityBadge($sev) {
    $map = [
        'critical'  => ['🔴', 'badge-critical',  'Kritik'],
        'important' => ['🟡', 'badge-important', 'Önemli'],
        'info'      => ['🔵', 'badge-info',      'Bilgi'],
    ];
    $d = $map[$sev] ?? ['⚪', 'badge-info', $sev];
    return "<span class=\"badge {$d[1]}\">{$d[0]} {$d[2]}</span>";
}

function seoTable($raw, $rendered) {
    $fields = [
        ['title','Sayfa Başlığı (Title)'],['metaDesc','Meta Description'],
        ['canonical','Canonical URL'],['robots','Meta Robots'],
        ['ogTitle','OG Title'],['ogDesc','OG Description'],['ogImage','OG Image'],
        ['twitterTitle','Twitter Title'],['twitterDesc','Twitter Description'],
        ['h1','H1 Etiketleri'],['h2','H2 Etiketleri'],['h3','H3 Etiketleri'],['internalLinks','İç Linkler'],
        ['externalLinks','Dış Linkler'],['nofollowLinks','Nofollow'],
        ['imagesTotal','Toplam Görsel'],['imagesNoAlt','Alt Sız Görsel'],
        ['wordCount','Kelime Sayısı'],['schemaTypes','Schema Türleri'],
    ];
    $html = '<table class="seo-table"><thead><tr>
        <th>Alan</th><th>Ham HTML</th><th>JS Render</th><th>Durum</th>
    </tr></thead><tbody>';
    foreach ($fields as [$key, $label]) {
        $r  = $raw[$key]      ?? '—';
        $rd = $rendered[$key] ?? '—';
        if (is_array($r))  $r  = implode(', ', $r)  ?: '—';
        if (is_array($rd)) $rd = implode(', ', $rd) ?: '—';
        if ($r === '')  $r  = '—';
        if ($rd === '') $rd = '—';
        $match = ($r === $rd);
        $rowClass = $match ? 'row-ok' : 'row-diff';
        $icon = $match ? '✅' : '⚠️';
        $html .= "<tr class=\"{$rowClass}\">
            <td class=\"field-name\">{$label}</td>
            <td class=\"val\">" . htmlspecialchars($r)  . "</td>
            <td class=\"val\">" . htmlspecialchars($rd) . "</td>
            <td class=\"status\">{$icon}</td>
        </tr>";
    }
    return $html . '</tbody></table>';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JS Render SEO Analyzer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg: #0a0e1a; --bg2: #101526;
    --surface: rgba(255,255,255,0.04); --surface2: rgba(255,255,255,0.07);
    --border: rgba(255,255,255,0.10);
    --accent: #6c63ff; --accent2: #00cfff;
    --green: #00e676; --red: #ff4d6d; --yellow: #ffd166;
    --text: #e8eaf0; --muted: #7a7f9a;
    --radius: 14px; --radius-sm: 8px;
    --font: 'Inter', sans-serif; --mono: 'JetBrains Mono', monospace;
}
body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; padding: 0 0 80px; }
body::before {
    content: ''; position: fixed; inset: 0; z-index: -1;
    background:
        radial-gradient(ellipse 80% 60% at 20% -10%, rgba(108,99,255,0.18) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 80% 110%, rgba(0,207,255,0.12) 0%, transparent 60%),
        var(--bg);
}
header { text-align: center; padding: 60px 20px 40px; }
header .logo { display: inline-flex; align-items: center; gap: 12px; margin-bottom: 18px; }
header .logo .icon {
    width: 48px; height: 48px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 14px; display: flex; align-items: center;
    justify-content: center; font-size: 24px;
    box-shadow: 0 0 30px rgba(108,99,255,0.4);
}
header h1 {
    font-size: 2.4rem; font-weight: 800;
    background: linear-gradient(90deg, var(--accent), var(--accent2));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
header p.subtitle { color: var(--muted); font-size: 1rem; margin-top: 8px; max-width: 600px; margin-inline: auto; line-height: 1.7; }
.container { max-width: 1300px; margin: 0 auto; padding: 0 20px; }
.search-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 30px; backdrop-filter: blur(20px); margin-bottom: 40px; }
.search-row { display: flex; gap: 12px; align-items: stretch; }
.search-row input[type="text"] {
    flex: 1; background: var(--surface2); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 14px 18px;
    font-size: 1rem; font-family: var(--mono); color: var(--text); outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.search-row input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(108,99,255,0.2); }
.search-row input::placeholder { color: var(--muted); }
.btn-analyze {
    padding: 14px 28px; background: linear-gradient(135deg, var(--accent), var(--accent2));
    border: none; border-radius: var(--radius-sm); color: #fff;
    font-size: 1rem; font-weight: 600; cursor: pointer; white-space: nowrap;
    transition: transform .15s, box-shadow .15s, opacity .15s;
    box-shadow: 0 4px 20px rgba(108,99,255,0.35); font-family: var(--font);
}
.btn-analyze:hover { transform: translateY(-1px); box-shadow: 0 6px 28px rgba(108,99,255,0.5); }
.btn-analyze:disabled { opacity: 0.6; cursor: not-allowed; }
.hint { margin-top: 12px; color: var(--muted); font-size: 0.82rem; display: flex; align-items: center; gap: 6px; }

#loading { display: none; text-align: center; padding: 50px 20px; }
#loading .spinner {
    width: 48px; height: 48px; border: 4px solid var(--border);
    border-top-color: var(--accent); border-radius: 50%;
    animation: spin 0.9s linear infinite; margin: 0 auto 16px;
}
@keyframes spin { to { transform: rotate(360deg); } }
#loading p { color: var(--muted); }

.error-box { background: rgba(255,77,109,0.12); border: 1px solid rgba(255,77,109,0.4); border-radius: var(--radius); padding: 20px 24px; color: var(--red); margin-bottom: 30px; display: flex; align-items: flex-start; gap: 12px; }

.stats-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 30px; }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; text-align: center; backdrop-filter: blur(10px); transition: transform .2s; }
.stat-card:hover { transform: translateY(-2px); }
.stat-card .num { font-size: 2rem; font-weight: 800; line-height: 1.1; }
.stat-card .label { font-size: 0.78rem; color: var(--muted); margin-top: 6px; }
.stat-card.green .num { color: var(--green); }
.stat-card.red .num { color: var(--red); }
.stat-card.yellow .num { color: var(--yellow); }
.stat-card.blue .num { color: var(--accent2); }
.stat-card.purple .num { color: var(--accent); }

.section-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

.changes-grid { display: flex; flex-direction: column; gap: 12px; margin-bottom: 36px; }
.change-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; backdrop-filter: blur(10px); border-left: 4px solid var(--muted); }
.change-card.critical  { border-left-color: var(--red);    background: rgba(255,77,109,0.06); }
.change-card.important { border-left-color: var(--yellow); background: rgba(255,209,102,0.06); }
.change-card.info      { border-left-color: var(--accent2); }
.change-card .ch-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.change-card .ch-key { font-weight: 700; font-size: 0.95rem; }
.ch-vals { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.ch-val { background: rgba(0,0,0,0.3); border-radius: var(--radius-sm); padding: 10px 14px; font-family: var(--mono); font-size: 0.82rem; word-break: break-all; }
.ch-val .vl { color: var(--muted); font-size: 0.72rem; display: block; margin-bottom: 4px; }

.table-wrap { overflow-x: auto; border-radius: var(--radius); border: 1px solid var(--border); margin-bottom: 36px; }
.seo-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.seo-table thead { background: rgba(108,99,255,0.15); }
.seo-table th { padding: 14px 16px; text-align: left; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); border-bottom: 1px solid var(--border); }
.seo-table td { padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.04); vertical-align: top; }
.seo-table tr:last-child td { border-bottom: none; }
.seo-table .field-name { font-weight: 600; white-space: nowrap; }
.seo-table .val { font-family: var(--mono); font-size: 0.8rem; color: var(--muted); max-width: 380px; word-break: break-word; }
.seo-table .status { text-align: center; font-size: 1.1rem; }
.seo-table .row-diff { background: rgba(255,209,102,0.04); }

/* ── Diff Viewer ─────────────────────────────────────── */
.diff-wrapper {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 36px;
}
.diff-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 18px; background: var(--surface2);
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap; gap: 10px;
}
.diff-toolbar .diff-title { font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; }
.diff-legend { display: flex; gap: 12px; font-size: 0.78rem; }
.diff-legend span { display: flex; align-items: center; gap: 5px; }
.diff-legend .dot { width: 10px; height: 10px; border-radius: 2px; }
.dot-add    { background: rgba(0,230,118,0.5); }
.dot-remove { background: rgba(255,77,109,0.5); }
.dot-equal  { background: var(--border); }

.diff-controls { display: flex; gap: 8px; }
.diff-controls button {
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 6px; padding: 5px 12px;
    color: var(--text); font-size: 0.8rem; cursor: pointer; font-family: var(--font);
    transition: background .15s;
}
.diff-controls button:hover { background: rgba(108,99,255,0.2); }
.diff-controls button.active { background: var(--accent); border-color: var(--accent); color: #fff; }

#diffContainer {
    font-family: var(--mono);
    font-size: 0.75rem;
    line-height: 1.7;
    max-height: 600px;
    overflow-y: auto;
    overflow-x: auto;
}
.diff-line {
    display: flex;
    white-space: pre;
    min-width: max-content;
}
.diff-line .ln {
    min-width: 52px;
    text-align: right;
    padding-right: 12px;
    color: var(--muted);
    user-select: none;
    border-right: 1px solid var(--border);
    flex-shrink: 0;
}
.diff-line .lc { padding: 0 16px; flex: 1; }
.diff-line.add    { background: rgba(0,230,118,0.10); }
.diff-line.remove { background: rgba(255,77,109,0.10); }
.diff-line.add    .lc { color: #4cff90; }
.diff-line.remove .lc { color: #ff7090; }
.diff-line.equal     .lc { color: var(--muted); }
.diff-line.separator { background: rgba(108,99,255,0.10); }
.diff-line.separator .lc { color: var(--accent); font-style: italic; }
.diff-line.info      { background: rgba(255,209,102,0.06); }
.diff-line.info      .lc { color: var(--yellow); font-style: italic; }
.diff-line.add    .ln { color: #4cff90; border-right-color: rgba(0,230,118,0.3); }
.diff-line.remove .ln { color: #ff7090; border-right-color: rgba(255,77,109,0.3); }

.diff-summary-bar {
    padding: 10px 18px;
    background: var(--surface2);
    border-top: 1px solid var(--border);
    font-size: 0.8rem; color: var(--muted);
    display: flex; gap: 20px; flex-wrap: wrap;
}
.diff-summary-bar .s-add { color: var(--green); font-weight: 600; }
.diff-summary-bar .s-rm  { color: var(--red);   font-weight: 600; }

.no-change { background: rgba(0,230,118,0.08); border: 1px solid rgba(0,230,118,0.3); border-radius: var(--radius); padding: 20px 24px; color: var(--green); display: flex; align-items: center; gap: 12px; margin-bottom: 30px; font-weight: 600; }

.badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
.badge-critical  { background: rgba(255,77,109,0.2); color: #ff7090; }
.badge-important { background: rgba(255,209,102,0.2); color: #ffd166; }
.badge-info      { background: rgba(0,207,255,0.15); color: var(--accent2); }

.schema-warn { color: var(--yellow); font-size: 0.78rem; margin-top: 6px; font-family: var(--mono); }

footer { text-align: center; padding-top: 60px; color: var(--muted); font-size: 0.8rem; }

@media (max-width: 768px) {
    .ch-vals, .search-row { grid-template-columns: 1fr; flex-direction: column; }
}
</style>
</head>
<body>
<header>
    <div class="logo">
        <div class="icon">🚀</div>
        <h1>JS Render SEO Analyzer</h1>
    </div>
    <p class="subtitle">
        Ham HTTP yanıtı ile Playwright render edilmiş HTML'yi satır satır karşılaştırır.<br>
        Kırmızı = silinen · Yeşil = eklenen — SEO için kritik farkları anında bulur.
    </p>
</header>

<div class="container">

    <div class="search-card">
        <form method="POST" id="analyzeForm">
            <div class="search-row">
                <input type="text" name="url" id="urlInput"
                    placeholder="https://example.com"
                    value="<?= htmlspecialchars($_POST['url'] ?? '') ?>"
                    required autocomplete="off" spellcheck="false"/>
                <button type="submit" class="btn-analyze" id="submitBtn">🔍 Analiz Et</button>
            </div>
            <p class="hint">💡 Analiz Playwright ile tam render yapar, ~10–30 saniye sürebilir.</p>
        </form>
    </div>

    <div id="loading">
        <div class="spinner"></div>
        <p>Playwright ile sayfa render ediliyor, lütfen bekleyin…</p>
    </div>

    <?php if ($error): ?>
    <div class="error-box">
        <span>❌</span>
        <div><?= htmlspecialchars($error) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($result):
        $changes   = $result['changes']    ?? [];
        $diff      = $result['htmlLineDiff'] ?? [];
        $raw       = $result['rawSEO'];
        $ren       = $result['renderedSEO'];
        $ms        = $result['renderTimeMs'] ?? 0;
        $critCount = count(array_filter($changes, fn($c) => $c['severity'] === 'critical'));
        $impCount  = count(array_filter($changes, fn($c) => $c['severity'] === 'important'));

        $addLines = count(array_filter($diff, fn($l) => $l['type'] === 'add'));
        $rmLines  = count(array_filter($diff, fn($l) => $l['type'] === 'remove'));
    ?>

    <!-- Stats Strip -->
    <div class="stats-strip">
        <div class="stat-card <?= $critCount > 0 ? 'red' : 'green' ?>">
            <div class="num"><?= $critCount ?></div>
            <div class="label">Kritik Değişim</div>
        </div>
        <div class="stat-card <?= $impCount > 0 ? 'yellow' : 'green' ?>">
            <div class="num"><?= $impCount ?></div>
            <div class="label">Önemli Değişim</div>
        </div>
        <div class="stat-card blue">
            <div class="num"><?= count($changes) ?></div>
            <div class="label">SEO Elementi Farkı</div>
        </div>
        <div class="stat-card green">
            <div class="num">+<?= $addLines ?></div>
            <div class="label">Eklenen Satır</div>
        </div>
        <div class="stat-card red">
            <div class="num">-<?= $rmLines ?></div>
            <div class="label">Silinen Satır</div>
        </div>
        <div class="stat-card purple">
            <div class="num"><?= round($ms / 1000, 1) ?>s</div>
            <div class="label">Render Süresi</div>
        </div>
    </div>

    <!-- Critical Changes -->
    <?php if (empty($changes)): ?>
    <div class="no-change">✅ Hiçbir SEO elementi değişmemiş — Bu site JS render bağımsız görünüyor!</div>
    <?php else: ?>
    <div class="section-title">⚡ Tespit Edilen SEO Değişimleri</div>
    <div class="changes-grid">
        <?php
        $keyLabels = [
            'title'=>'Title','metaDesc'=>'Meta Description','canonical'=>'Canonical',
            'robots'=>'Robots','ogTitle'=>'OG Title','ogDesc'=>'OG Description',
            'ogImage'=>'OG Image','twitterTitle'=>'Twitter Title','twitterDesc'=>'Twitter Description',
            'h1'=>'H1 Etiketleri','h2'=>'H2 Etiketleri','h3'=>'H3 Etiketleri',
            'internalLinks'=>'İç Link','externalLinks'=>'Dış Link',
            'wordCount'=>'Kelime Sayısı','schemaTypes'=>'Schema Türleri','imagesNoAlt'=>'Alt Sız Görsel',
        ];
        foreach ($changes as $c):
            $label = $keyLabels[$c['key']] ?? $c['key'];
        ?>
        <div class="change-card <?= $c['severity'] ?>">
            <div class="ch-header">
                <span class="ch-key"><?= htmlspecialchars($label) ?></span>
                <?= severityBadge($c['severity']) ?>
            </div>
            <div class="ch-vals">
                <div class="ch-val">
                    <span class="vl">🌐 Ham HTML</span>
                    <?= htmlspecialchars($c['raw'] ?: '(boş)') ?>
                </div>
                <div class="ch-val">
                    <span class="vl">⚙️ JS Render</span>
                    <?= htmlspecialchars($c['rendered'] ?: '(boş)') ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Schema error hint -->
    <?php if (!empty($ren['schemaErrors'])): ?>
    <div class="schema-warn">
        ⚠️ Schema parse hataları (render sonrası): <?= htmlspecialchars(implode(' | ', $ren['schemaErrors'])) ?>
    </div>
    <?php endif; ?>

    <!-- Full SEO Table -->
    <div class="section-title" style="margin-top:28px">📊 Tam SEO Karşılaştırma Tablosu</div>
    <div class="table-wrap"><?= seoTable($raw, $ren) ?></div>

    <!-- HTML Diff Viewer -->
    <div class="section-title">📄 HTML Kaynak Diff (Ham → Render)</div>
    <div class="diff-wrapper">
        <div class="diff-toolbar">
            <div class="diff-title">
                🔍 Satır Satır HTML Karşılaştırması
                <div class="diff-legend">
                    <span><div class="dot dot-add"></div> Eklendi (Render)</span>
                    <span><div class="dot dot-remove"></div> Silindi (Ham'dan)</span>
                    <span><div class="dot dot-equal"></div> Değişmemiş</span>
                </div>
            </div>
            <div class="diff-controls">
                <button id="btnAll" class="active" onclick="filterDiff('all')">Tümü</button>
                <button id="btnChanges" onclick="filterDiff('changes')">Sadece Farklar</button>
            </div>
        </div>
        <div id="diffContainer">
            <!-- populated by JS -->
        </div>
        <div class="diff-summary-bar">
            <span>Toplam <?= count($diff) ?> satır</span>
            <span class="s-add">+<?= $addLines ?> eklenen</span>
            <span class="s-rm">-<?= $rmLines ?> silinen</span>
            <span><?= count($diff) - $addLines - $rmLines ?> değişmemiş</span>
            <span>Ham: <?= number_format($result['rawHtmlLength']) ?> byte · Render: <?= number_format($result['renderedHtmlLength']) ?> byte</span>
        </div>
    </div>

    <script>
    // Diff data from PHP
    const DIFF = <?= json_encode($diff, JSON_UNESCAPED_UNICODE) ?>;

    let lineNo_raw = 0, lineNo_ren = 0;
    const rows = DIFF.map(entry => {
        if (entry.type === 'equal')  { lineNo_raw++; lineNo_ren++; return { ...entry, ln: lineNo_raw + '/' + lineNo_ren }; }
        if (entry.type === 'remove') { lineNo_raw++; return { ...entry, ln: lineNo_raw + '/' + '-' }; }
        if (entry.type === 'add')    { lineNo_ren++; return { ...entry, ln: '-' + '/' + lineNo_ren }; }
        return { ...entry, ln: '' };
    });

    function esc(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function renderDiff(mode) {
        const container = document.getElementById('diffContainer');
        const prefix = { add: '+ ', remove: '- ', equal: '  ', separator: '', info: '' };
        let html = '';
        for (const row of rows) {
            if (mode === 'changes' && (row.type === 'equal' || row.type === 'separator')) continue;
            const p = prefix[row.type] ?? '  ';
            const ln = (row.type === 'separator' || row.type === 'info') ? '···' : (row.ln || '');
            html += `<div class="diff-line ${row.type}"><span class="ln">${ln}</span><span class="lc">${p}${esc(row.line)}</span></div>`;
        }
        container.innerHTML = html || '<div style="padding:20px;color:var(--muted)">Fark yok.</div>';
    }

    function filterDiff(mode) {
        document.getElementById('btnAll').classList.toggle('active', mode === 'all');
        document.getElementById('btnChanges').classList.toggle('active', mode === 'changes');
        renderDiff(mode);
    }

    renderDiff('all');
    </script>

    <?php endif; ?>

</div><!-- /.container -->

<footer>JS Render SEO Analyzer · Playwright + PHP · <?= date('Y') ?></footer>

<script>
document.getElementById('analyzeForm').addEventListener('submit', function() {
    document.getElementById('loading').style.display = 'block';
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').textContent = '⏳ Analiz yapılıyor…';
});
</script>
</body>
</html>
