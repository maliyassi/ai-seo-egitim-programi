<?php

declare(strict_types=1);

use KeywordAnalyzer\ContentExtractor;
use KeywordAnalyzer\GeminiOptimizer;
use KeywordAnalyzer\QueryMatcher;
use KeywordAnalyzer\SearchConsoleClient;

require_once dirname(__DIR__) . '/vendor/autoload.php';

const DEFAULT_GEMINI_MODEL = 'gemini-2.5-flash';
const DEFAULT_GEMINI_API_KEY = 'AIzaSyDlg48MCGwcxcjDL1WPMnZGxJWXspBn5Ns';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$errors = [];
$analysisRows = [];
$optimizedRows = [];
$source = ['title' => '', 'content' => '', 'analyzed_text' => ''];
$optimizedText = '';
$optimizationRounds = 0;
$resolvedSiteUrl = '';
$defaultCredentialsPath = dirname(__DIR__) . '/credentials-2.json';
$defaultGeminiApiKey = (string) (getenv('GEMINI_API_KEY') ?: DEFAULT_GEMINI_API_KEY);

$input = [
    'url' => $_POST['url'] ?? '',
    'site_url' => $_POST['site_url'] ?? '',
    'content_xpath' => $_POST['content_xpath'] ?? '',
    'days' => $_POST['days'] ?? '28',
    'credentials_path' => $_POST['credentials_path'] ?? $defaultCredentialsPath,
    'gemini_api_key' => $_POST['gemini_api_key'] ?? $defaultGeminiApiKey,
    'action' => $_POST['action'] ?? 'analyze',
    'max_rounds' => $_POST['max_rounds'] ?? '3',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $url = trim((string) $input['url']);
        $siteUrl = trim((string) $input['site_url']);
        $contentXPath = trim((string) $input['content_xpath']);
        $credentialsPath = trim((string) $input['credentials_path']);
        $geminiKey = trim((string) $input['gemini_api_key']);
        $days = max(1, (int) $input['days']);
        $maxRounds = max(1, min(6, (int) $input['max_rounds']));
        $action = (string) $input['action'];

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Gecerli bir URL girin.');
        }

        if ($contentXPath === '') {
            throw new RuntimeException('XPath bos olamaz.');
        }

        if ($credentialsPath === '') {
            throw new RuntimeException('Google JSON dosya yolunu girin.');
        }

        $endDate = new DateTimeImmutable('today');
        $startDate = $endDate->sub(new DateInterval('P' . ($days - 1) . 'D'));

        $extractor = new ContentExtractor();
        $source = $extractor->extract($url, $contentXPath);

        $searchConsole = new SearchConsoleClient($credentialsPath);
        $resolvedSiteUrl = $searchConsole->resolveSiteUrlForPage($url, $siteUrl);
        $input['site_url'] = $resolvedSiteUrl;
        $queries = $searchConsole->getQueriesForPage(
            $resolvedSiteUrl,
            $url,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );

        $matcher = new QueryMatcher();
        $analysisRows = $matcher->analyze($queries, $source['analyzed_text']);
        usort($analysisRows, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        if ($action === 'optimize') {
            if ($geminiKey === '') {
                throw new RuntimeException('Gemini aktifken API key zorunludur.');
            }

            $currentText = $source['content'];
            $currentRows = $analysisRows;
            $optimizer = new GeminiOptimizer($geminiKey, DEFAULT_GEMINI_MODEL);

            for ($round = 1; $round <= $maxRounds; $round++) {
                $missingQueries = array_values(array_map(
                    static fn (array $row): string => $row['query'],
                    array_filter($currentRows, static fn (array $row): bool => $row['color'] === 'red')
                ));

                if ($missingQueries === []) {
                    break;
                }

                $currentText = $optimizer->rewrite($source['title'], $currentText, $missingQueries);
                $optimizationRounds = $round;
                $currentRows = $matcher->analyze($queries, trim($source['title'] . ' ' . $currentText));
            }

            $optimizedText = $currentText;
            $optimizedRows = $currentRows;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$legend = [
    'green' => ['label' => 'Sıralı tam eşleşme', 'icon' => '✓✓'],
    'orange' => ['label' => 'Sırasız tam eşleşme', 'icon' => '⇄'],
    'yellow' => ['label' => 'Kısmi eşleşme', 'icon' => '½'],
    'blue' => ['label' => 'Yazım hatası eşleşmesi', 'icon' => 'ĞG'],
    'red' => ['label' => 'Eşleşme yok', 'icon' => '✗'],
];

/* -- Stats computation -- */
$stats = ['green' => 0, 'orange' => 0, 'yellow' => 0, 'blue' => 0, 'red' => 0];
$totalClicks = 0;
$totalImpressions = 0;
$sourceRows = $optimizedRows !== [] ? $optimizedRows : $analysisRows;
foreach ($sourceRows as $row) {
    $stats[$row['color']] = ($stats[$row['color']] ?? 0) + 1;
    $totalClicks += $row['clicks'];
    $totalImpressions += $row['impressions'];
}
$totalQueries = array_sum($stats);
$coveragePercent = $totalQueries > 0 ? round(($totalQueries - $stats['red']) / $totalQueries * 100) : 0;

/** Helper to render a results table */
function renderTable(array $rows, array $legend): void {
    if ($rows === []) return;
    ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th class="th-query">Sorgu</th>
                <th>Durum</th>
                <th>Eşleşen</th>
                <th class="th-num">Tıklama</th>
                <th class="th-num">Gösterim</th>
                <th class="th-num">CTR</th>
                <th class="th-num">Pozisyon</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $idx => $row): ?>
                <tr class="row-<?= e($row['color']) ?>">
                    <td class="cell-query">
                        <?= e($row['query']) ?>
                        <?php if ($row['color'] === 'red'): ?>
                            <div class="suggest-wrap">
                                <button class="btn-suggest" onclick="suggestSentence(this, '<?= e(addslashes($row['query'])) ?>')" title="Gemini ile cümle önerisi al">
                                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2v4m0 12v4m-7.07-3.93 2.83-2.83m8.48-8.48 2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48 2.83 2.83"/></svg>
                                    Cümle Öner
                                </button>
                                <div class="suggest-result" style="display:none"></div>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= e($row['color']) ?>"><?= e($legend[$row['color']]['icon'] ?? '') ?> <?= e($row['status']) ?></span></td>
                    <td class="cell-matched"><?= e(implode(', ', $row['matched_words'])) ?></td>
                    <td class="cell-num"><?= number_format($row['clicks'], 0) ?></td>
                    <td class="cell-num"><?= number_format($row['impressions'], 0) ?></td>
                    <td class="cell-num"><?= number_format($row['ctr'] * 100, 1) ?>%</td>
                    <td class="cell-num"><?= number_format($row['position'], 1) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Keyword Analyzer — Search Console + Gemini</title>
    <meta name="description" content="Google Search Console sorgu kapsamını analiz edin ve Gemini AI ile içerik optimizasyonu yapın.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ===== RESET & BASE ===== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #0b1120;
            color: #e2e8f0;
            min-height: 100vh;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        /* ===== ANIMATED BG ===== */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 600px 400px at 20% 20%, rgba(56,189,248,0.08) 0%, transparent 70%),
                radial-gradient(ellipse 500px 500px at 80% 80%, rgba(168,85,247,0.08) 0%, transparent 70%),
                radial-gradient(ellipse 400px 300px at 60% 30%, rgba(34,197,94,0.05) 0%, transparent 60%);
            z-index: 0;
            pointer-events: none;
        }

        .page { position: relative; z-index: 1; max-width: 1280px; margin: 0 auto; padding: 32px 24px 64px; }

        /* ===== HEADER ===== */
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding: 40px 0 32px;
        }
        .header-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(56,189,248,0.1);
            border: 1px solid rgba(56,189,248,0.2);
            border-radius: 999px;
            padding: 6px 16px;
            font-size: 12px; font-weight: 600;
            color: #38bdf8;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            margin-bottom: 16px;
        }
        .header-badge .dot {
            width: 6px; height: 6px;
            background: #38bdf8;
            border-radius: 50%;
            animation: pulse-dot 2s ease-in-out infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.7); }
        }
        .header h1 {
            font-size: clamp(28px, 5vw, 42px);
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #f8fafc, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
        }
        .header p {
            color: #64748b;
            font-size: 15px;
            max-width: 520px;
            margin: 0 auto;
        }

        /* ===== GLASS CARD ===== */
        .glass {
            background: rgba(15,23,42,0.6);
            border: 1px solid rgba(148,163,184,0.1);
            border-radius: 16px;
            padding: 28px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            margin-bottom: 24px;
            transition: border-color 0.3s ease;
        }
        .glass:hover { border-color: rgba(148,163,184,0.18); }
        .glass-title {
            font-size: 16px; font-weight: 700;
            color: #f1f5f9;
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 20px;
        }
        .glass-title .icon {
            width: 32px; height: 32px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .icon-blue { background: rgba(56,189,248,0.15); }
        .icon-purple { background: rgba(168,85,247,0.15); }
        .icon-green { background: rgba(34,197,94,0.15); }
        .icon-orange { background: rgba(251,146,60,0.15); }

        /* ===== FORM ===== */
        .form-group { margin-bottom: 16px; }
        .form-label {
            display: block;
            font-size: 13px; font-weight: 600;
            color: #94a3b8;
            margin-bottom: 6px;
            letter-spacing: 0.3px;
        }
        .form-input, .form-select {
            width: 100%;
            padding: 11px 14px;
            background: rgba(30,41,59,0.7);
            border: 1px solid rgba(148,163,184,0.15);
            border-radius: 10px;
            color: #e2e8f0;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.25s ease;
            outline: none;
        }
        .form-input::placeholder { color: #475569; }
        .form-input:focus, .form-select:focus {
            border-color: rgba(56,189,248,0.5);
            box-shadow: 0 0 0 3px rgba(56,189,248,0.1), 0 0 20px rgba(56,189,248,0.05);
        }
        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M4.646 5.646a.5.5 0 0 1 .708 0L8 8.293l2.646-2.647a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }
        .form-select option { background: #1e293b; color: #e2e8f0; }
        .form-hint {
            font-size: 11px; color: #475569; margin-top: 4px;
        }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

        /* ===== BUTTONS ===== */
        .btn-group { display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 24px;
            border: none; border-radius: 12px;
            font-family: inherit; font-size: 14px; font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .btn::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .btn-primary {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: #fff;
            box-shadow: 0 4px 16px rgba(14,165,233,0.25);
        }
        .btn-secondary {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: #fff;
            box-shadow: 0 4px 16px rgba(139,92,246,0.25);
        }
        .btn-secondary:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(139,92,246,0.35);
        }
        .btn-secondary:active:not(:disabled) { transform: translateY(0); }
        .btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
            filter: grayscale(0.4);
        }
        .btn-primary:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(14,165,233,0.35);
        }
        .btn-primary:active:not(:disabled) { transform: translateY(0); }

        /* ===== LEGEND ===== */
        .legend { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 20px; }
        .legend-item {
            display: flex; align-items: center; gap: 8px;
            font-size: 12px; font-weight: 500;
            color: #94a3b8;
            padding: 6px 14px;
            background: rgba(30,41,59,0.5);
            border-radius: 8px;
            border: 1px solid rgba(148,163,184,0.08);
        }
        .legend-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .dot-green { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,0.5); }
        .dot-orange { background: #f97316; box-shadow: 0 0 6px rgba(249,115,22,0.5); }
        .dot-yellow { background: #eab308; box-shadow: 0 0 6px rgba(234,179,8,0.5); }
        .dot-blue { background: #3b82f6; box-shadow: 0 0 6px rgba(59,130,246,0.5); }
        .dot-red { background: #ef4444; box-shadow: 0 0 6px rgba(239,68,68,0.5); }

        /* ===== ERROR TOAST ===== */
        .error-toast {
            background: rgba(127,29,29,0.3);
            border: 1px solid rgba(239,68,68,0.3);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex; align-items: flex-start; gap: 12px;
            animation: slideDown 0.4s ease;
        }
        .error-toast .error-icon {
            width: 20px; height: 20px;
            background: rgba(239,68,68,0.2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; color: #fca5a5;
            flex-shrink: 0; margin-top: 1px;
        }
        .error-toast .error-text { color: #fca5a5; font-size: 14px; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ===== STATS CARDS ===== */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 24px; }
        .stat-card {
            background: rgba(30,41,59,0.5);
            border: 1px solid rgba(148,163,184,0.08);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            border-color: rgba(148,163,184,0.2);
            transform: translateY(-2px);
        }
        .stat-value {
            font-size: 28px; font-weight: 800;
            letter-spacing: -1px;
            margin-bottom: 2px;
        }
        .stat-label { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-green .stat-value { color: #4ade80; }
        .stat-orange .stat-value { color: #fb923c; }
        .stat-yellow .stat-value { color: #facc15; }
        .stat-blue .stat-value { color: #60a5fa; }
        .stat-red .stat-value { color: #f87171; }
        .stat-total .stat-value {
            background: linear-gradient(135deg, #e2e8f0, #94a3b8);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .stat-coverage .stat-value {
            background: linear-gradient(135deg, #4ade80, #22d3ee);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* ===== COVERAGE BAR ===== */
        .coverage-bar-wrap { margin-bottom: 24px; }
        .coverage-bar-label {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 8px;
        }
        .coverage-bar-label span { font-size: 13px; color: #94a3b8; font-weight: 500; }
        .coverage-bar-label strong { font-size: 14px; font-weight: 700; color: #f1f5f9; }
        .coverage-bar {
            height: 8px; border-radius: 99px;
            background: rgba(30,41,59,0.8);
            overflow: hidden;
            display: flex;
        }
        .coverage-bar .seg {
            height: 100%;
            transition: width 0.6s ease;
        }
        .seg-green { background: linear-gradient(90deg, #22c55e, #4ade80); }
        .seg-orange { background: linear-gradient(90deg, #f97316, #fb923c); }
        .seg-yellow { background: linear-gradient(90deg, #eab308, #facc15); }
        .seg-blue { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .seg-red { background: linear-gradient(90deg, #ef4444, #f87171); }

        /* ===== CONTENT PREVIEW ===== */
        .content-preview {
            background: rgba(30,41,59,0.4);
            border: 1px solid rgba(148,163,184,0.08);
            border-radius: 10px;
            padding: 16px 18px;
            font-size: 13px;
            color: #94a3b8;
            line-height: 1.7;
            max-height: 160px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-word;
            transition: max-height 0.4s ease;
            scrollbar-width: thin;
            scrollbar-color: #334155 transparent;
        }
        .content-preview::-webkit-scrollbar { width: 5px; }
        .content-preview::-webkit-scrollbar-track { background: transparent; }
        .content-preview::-webkit-scrollbar-thumb { background: #334155; border-radius: 99px; }
        .content-preview.expanded { max-height: none; }
        .toggle-btn {
            display: inline-flex; align-items: center; gap: 4px;
            background: none; border: none;
            color: #38bdf8; font-size: 12px; font-weight: 600;
            cursor: pointer; margin-top: 8px;
            font-family: inherit;
            transition: color 0.2s;
        }
        .toggle-btn:hover { color: #7dd3fc; }

        .content-meta {
            display: flex; flex-wrap: wrap; gap: 16px;
            margin-bottom: 16px;
        }
        .meta-item {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; color: #64748b;
        }
        .meta-item strong { color: #94a3b8; font-weight: 600; }

        /* ===== TABLE ===== */
        .table-wrap {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid rgba(148,163,184,0.08);
            scrollbar-width: thin;
            scrollbar-color: #334155 transparent;
        }
        .table-wrap::-webkit-scrollbar { height: 5px; }
        .table-wrap::-webkit-scrollbar-track { background: transparent; }
        .table-wrap::-webkit-scrollbar-thumb { background: #334155; border-radius: 99px; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead { background: rgba(30,41,59,0.8); }
        th {
            padding: 12px 14px;
            font-size: 11px; font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            text-align: left;
            border-bottom: 1px solid rgba(148,163,184,0.1);
            white-space: nowrap;
        }
        th.th-num { text-align: right; }
        td {
            padding: 10px 14px;
            border-bottom: 1px solid rgba(148,163,184,0.05);
            vertical-align: middle;
        }
        .cell-query { font-weight: 500; color: #e2e8f0; max-width: 280px; word-break: break-word; }
        .cell-matched { font-size: 12px; color: #64748b; max-width: 140px; word-break: break-word; }
        .cell-num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 500; color: #cbd5e1; }

        tbody tr { transition: background 0.2s ease; }
        tbody tr:hover { background: rgba(148,163,184,0.04); }

        .row-green { background: rgba(34,197,94,0.04); }
        .row-green:hover { background: rgba(34,197,94,0.08) !important; }
        .row-orange { background: rgba(249,115,22,0.04); }
        .row-orange:hover { background: rgba(249,115,22,0.08) !important; }
        .row-yellow { background: rgba(234,179,8,0.04); }
        .row-yellow:hover { background: rgba(234,179,8,0.08) !important; }
        .row-blue { background: rgba(59,130,246,0.04); }
        .row-blue:hover { background: rgba(59,130,246,0.08) !important; }
        .row-red { background: rgba(239,68,68,0.04); }
        .row-red:hover { background: rgba(239,68,68,0.08) !important; }

        /* ===== BADGE ===== */
        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 11px; font-weight: 600;
            white-space: nowrap;
        }
        .badge-green { background: rgba(34,197,94,0.15); color: #4ade80; }
        .badge-orange { background: rgba(249,115,22,0.15); color: #fb923c; }
        .badge-yellow { background: rgba(234,179,8,0.15); color: #facc15; }
        .badge-blue { background: rgba(59,130,246,0.15); color: #60a5fa; }
        .badge-red { background: rgba(239,68,68,0.15); color: #f87171; }

        /* ===== SECTION LABEL ===== */
        .section-label {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 600;
            color: #64748b;
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .section-label .line {
            flex: 1; height: 1px;
            background: linear-gradient(90deg, rgba(148,163,184,0.15), transparent);
            min-width: 60px;
        }

        /* ===== GEMINI COMPARISON ===== */
        .gemini-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(168,85,247,0.1);
            border: 1px solid rgba(168,85,247,0.2);
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 12px; font-weight: 600;
            color: #c084fc;
            margin-bottom: 16px;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .page { padding: 16px 12px 48px; }
            .glass { padding: 20px 16px; border-radius: 12px; }
            .form-grid-2, .form-grid-3 { grid-template-columns: 1fr; }
            .btn-group { flex-direction: column; }
            .btn { justify-content: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .header h1 { font-size: 24px; }
        }

        /* ===== LOADING (JS) ===== */
        .loading-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(11,17,32,0.7);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center; justify-content: center;
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 40px; height: 40px;
            border: 3px solid rgba(56,189,248,0.2);
            border-top-color: #38bdf8;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ===== SUGGEST BUTTON ===== */
        .suggest-wrap { margin-top: 6px; }
        .btn-suggest {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px;
            background: rgba(168,85,247,0.12);
            border: 1px solid rgba(168,85,247,0.25);
            border-radius: 6px;
            color: #c084fc;
            font-size: 11px; font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .btn-suggest:hover {
            background: rgba(168,85,247,0.2);
            border-color: rgba(168,85,247,0.4);
        }
        .btn-suggest:disabled {
            opacity: 0.5;
            cursor: wait;
        }
        .btn-suggest .spinner-sm {
            width: 10px; height: 10px;
            border: 2px solid rgba(168,85,247,0.3);
            border-top-color: #c084fc;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            display: inline-block;
        }
        .suggest-result {
            margin-top: 6px;
            padding: 8px 12px;
            background: rgba(168,85,247,0.06);
            border: 1px solid rgba(168,85,247,0.15);
            border-radius: 8px;
            font-size: 12px;
            color: #e2d4f0;
            line-height: 1.6;
            position: relative;
        }
        .suggest-result .copy-btn {
            position: absolute; top: 6px; right: 6px;
            background: rgba(168,85,247,0.15);
            border: none; border-radius: 4px;
            color: #c084fc; font-size: 10px;
            padding: 2px 8px; cursor: pointer;
            font-family: inherit; font-weight: 600;
        }
        .suggest-result .copy-btn:hover { background: rgba(168,85,247,0.3); }
        .suggest-error {
            color: #f87171;
            font-size: 12px;
            margin-top: 4px;
        }

        /* ===== FILTER CHIPS ===== */
        .filter-chips {
            display: flex; flex-wrap: wrap; gap: 8px;
            margin-bottom: 16px;
        }
        .filter-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px;
            background: rgba(30,41,59,0.6);
            border: 1px solid rgba(148,163,184,0.12);
            border-radius: 8px;
            color: #94a3b8;
            font-size: 12px; font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .filter-chip:hover { border-color: rgba(148,163,184,0.3); color: #e2e8f0; }
        .filter-chip.active {
            background: rgba(56,189,248,0.12);
            border-color: rgba(56,189,248,0.3);
            color: #38bdf8;
        }
        .fc-green.active { background: rgba(34,197,94,0.12); border-color: rgba(34,197,94,0.3); color: #4ade80; }
        .fc-orange.active { background: rgba(249,115,22,0.12); border-color: rgba(249,115,22,0.3); color: #fb923c; }
        .fc-yellow.active { background: rgba(234,179,8,0.12); border-color: rgba(234,179,8,0.3); color: #facc15; }
        .fc-blue.active { background: rgba(59,130,246,0.12); border-color: rgba(59,130,246,0.3); color: #60a5fa; }
        .fc-red.active { background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.3); color: #f87171; }
        .chip-count {
            background: rgba(148,163,184,0.1);
            padding: 1px 7px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
        }
        .filter-chip.active .chip-count { background: rgba(255,255,255,0.1); }
    </style>
</head>
<body>

<!-- Loading overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div style="text-align:center">
        <div class="spinner"></div>
        <p style="margin-top:16px; color:#94a3b8; font-size:14px; font-weight:500;">Analiz ediliyor…</p>
    </div>
</div>

<div class="page">

    <!-- ====== HEADER ====== -->
    <header class="header">
        <div class="header-badge"><span class="dot"></span> SEO Analiz Aracı</div>
        <h1>Keyword Analyzer</h1>
        <p>Search Console sorgularını sayfa içeriğiyle karşılaştırın, eksikleri Gemini AI ile tamamlayın.</p>
    </header>

    <!-- ====== ERRORS ====== -->
    <?php if ($errors !== []): ?>
        <?php foreach ($errors as $err): ?>
            <div class="error-toast">
                <span class="error-icon">!</span>
                <span class="error-text"><?= e($err) ?></span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- ====== FORM ====== -->
    <div class="glass">
        <div class="glass-title">
            <span class="icon icon-blue">🔍</span> Analiz Ayarları
        </div>
        <form method="post" id="analysisForm">
            <div class="form-group">
                <label class="form-label">Sayfa URL'si</label>
                <input class="form-input" type="url" name="url" value="<?= e((string) $input['url']) ?>" placeholder="https://example.com/sayfa-adi" required>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">İçerik XPath</label>
                    <input class="form-input" type="text" name="content_xpath" placeholder="//main//article" value="<?= e((string) $input['content_xpath']) ?>" required>
                    <div class="form-hint">Sayfada analiz edilecek metin alanının XPath'i</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Gün Aralığı</label>
                    <select class="form-select" name="days">
                        <?php foreach ([28, 90, 180, 365] as $dayOpt): ?>
                            <option value="<?= $dayOpt ?>" <?= (string)$dayOpt === (string)$input['days'] ? 'selected' : '' ?>><?= $dayOpt ?> gün</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Site Property URL <span style="font-weight:400;color:#475569">(opsiyonel)</span></label>
                <input class="form-input" type="text" name="site_url" placeholder="https://example.com/" value="<?= e((string) $input['site_url']) ?>">
                <div class="form-hint">Boş bırakılırsa URL'den otomatik bulunur</div>
            </div>

            <!-- Hidden fields for backend -->
            <input type="hidden" name="credentials_path" value="<?= e((string) $input['credentials_path']) ?>">
            <input type="hidden" name="gemini_api_key" value="<?= e((string) $input['gemini_api_key']) ?>">
            <input type="hidden" name="max_rounds" value="<?= e((string) $input['max_rounds']) ?>">

            <div class="btn-group">
                <button class="btn btn-primary" type="submit" name="action" value="analyze">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    İçerik + Sorgu Analizi
                </button>
            </div>
        </form>

        <div class="legend">
            <?php foreach ($legend as $color => $info): ?>
                <div class="legend-item">
                    <span class="legend-dot dot-<?= $color ?>"></span>
                    <?= e($info['label']) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ====== RESULTS ====== -->
    <?php if ($analysisRows !== []): ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card stat-total">
                <div class="stat-value"><?= $totalQueries ?></div>
                <div class="stat-label">Toplam Sorgu</div>
            </div>
            <div class="stat-card stat-coverage">
                <div class="stat-value"><?= $coveragePercent ?>%</div>
                <div class="stat-label">Kapsam</div>
            </div>
            <div class="stat-card stat-green">
                <div class="stat-value"><?= $stats['green'] ?></div>
                <div class="stat-label">Tam Eşleşme</div>
            </div>
            <div class="stat-card stat-orange">
                <div class="stat-value"><?= $stats['orange'] ?></div>
                <div class="stat-label">Sırasız</div>
            </div>
            <div class="stat-card stat-yellow">
                <div class="stat-value"><?= $stats['yellow'] ?></div>
                <div class="stat-label">Kısmi</div>
            </div>
            <div class="stat-card stat-blue">
                <div class="stat-value"><?= $stats['blue'] ?></div>
                <div class="stat-label">Yazım Hatası</div>
            </div>
            <div class="stat-card stat-red">
                <div class="stat-value"><?= $stats['red'] ?></div>
                <div class="stat-label">Eşleşmiyor</div>
            </div>
        </div>

        <!-- Coverage Bar -->
        <?php if ($totalQueries > 0): ?>
            <div class="coverage-bar-wrap">
                <div class="coverage-bar-label">
                    <span>Eşleşme Dağılımı</span>
                    <strong><?= $totalQueries ?> sorgu</strong>
                </div>
                <div class="coverage-bar">
                    <?php foreach (['green','orange','yellow','blue','red'] as $c): ?>
                        <?php $pct = $totalQueries > 0 ? round($stats[$c] / $totalQueries * 100, 1) : 0; ?>
                        <?php if ($pct > 0): ?>
                            <div class="seg seg-<?= $c ?>" style="width:<?= $pct ?>%" title="<?= e($legend[$c]['label']) ?>: <?= $stats[$c] ?>"></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Extracted Content -->
        <?php if ($source['title'] !== '' || $source['content'] !== ''): ?>
            <div class="glass">
                <div class="glass-title">
                    <span class="icon icon-green">📄</span> Çekilen İçerik
                </div>
                <div class="content-meta">
                    <?php if ($resolvedSiteUrl !== ''): ?>
                        <div class="meta-item">🌐 <strong>Property:</strong> <?= e($resolvedSiteUrl) ?></div>
                    <?php endif; ?>
                    <div class="meta-item">📌 <strong>Başlık:</strong> <?= e($source['title']) ?></div>
                </div>
                <div class="content-preview" id="contentPreview"><?= e($source['content']) ?></div>
                <button class="toggle-btn" onclick="toggleContent('contentPreview', this)">▼ Tamamını göster</button>
            </div>
        <?php endif; ?>

        <!-- Original Analysis Table -->
        <div class="glass">
            <div class="glass-title">
                <span class="icon icon-blue">📊</span> Orijinal İçerik Analizi
            </div>
            <div class="filter-chips" id="filterChips">
                <button class="filter-chip active" data-filter="all" onclick="filterTable('all', this)">Tümü <span class="chip-count"><?= $totalQueries ?></span></button>
                <?php if ($stats['green'] > 0): ?><button class="filter-chip fc-green" data-filter="green" onclick="filterTable('green', this)">Sıralı tam <span class="chip-count"><?= $stats['green'] ?></span></button><?php endif; ?>
                <?php if ($stats['orange'] > 0): ?><button class="filter-chip fc-orange" data-filter="orange" onclick="filterTable('orange', this)">Sırasız tam <span class="chip-count"><?= $stats['orange'] ?></span></button><?php endif; ?>
                <?php if ($stats['yellow'] > 0): ?><button class="filter-chip fc-yellow" data-filter="yellow" onclick="filterTable('yellow', this)">Kısmi <span class="chip-count"><?= $stats['yellow'] ?></span></button><?php endif; ?>
                <?php if ($stats['blue'] > 0): ?><button class="filter-chip fc-blue" data-filter="blue" onclick="filterTable('blue', this)">Yazım hatası <span class="chip-count"><?= $stats['blue'] ?></span></button><?php endif; ?>
                <?php if ($stats['red'] > 0): ?><button class="filter-chip fc-red" data-filter="red" onclick="filterTable('red', this)">Eşleşmiyor <span class="chip-count"><?= $stats['red'] ?></span></button><?php endif; ?>
            </div>
            <?php renderTable($analysisRows, $legend); ?>
        </div>
    <?php endif; ?>

    <!-- ====== OPTIMIZED RESULTS ====== -->
    <?php if ($optimizedRows !== []): ?>
        <div class="glass">
            <div class="glass-title">
                <span class="icon icon-purple">✨</span> Gemini Optimizasyon Sonuçları
            </div>
            <div class="gemini-badge">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2v4m0 12v4m-7.07-3.93 2.83-2.83m8.48-8.48 2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48 2.83 2.83"/></svg>
                <?= e((string) $optimizationRounds) ?> tur optimizasyon tamamlandı
            </div>

            <div style="margin-bottom: 20px;">
                <div class="section-label">Optimize Edilen İçerik <span class="line"></span></div>
                <div class="content-preview" id="optimizedPreview"><?= e($optimizedText) ?></div>
                <button class="toggle-btn" onclick="toggleContent('optimizedPreview', this)">▼ Tamamını göster</button>
            </div>

            <?php renderTable($optimizedRows, $legend); ?>
        </div>
    <?php endif; ?>

</div>

<!-- Hidden data for JS -->
<?php if ($source['title'] !== '' || $source['content'] !== ''): ?>
<script id="pageData" type="application/json"><?= json_encode([
    'title' => $source['title'],
    'content_summary' => mb_substr($source['content'], 0, 800, 'UTF-8'),
    'api_key' => (string) ($input['gemini_api_key'] ?? ''),
], JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>

<script>
    // Loading overlay on form submit
    document.getElementById('analysisForm')?.addEventListener('submit', function() {
        document.getElementById('loadingOverlay').classList.add('active');
    });

    // Toggle content preview expand/collapse
    function toggleContent(id, btn) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.toggle('expanded');
        btn.textContent = el.classList.contains('expanded') ? '▲ Daralt' : '▼ Tamamını göster';
    }

    // Filter table rows by status color
    function filterTable(color, chipBtn) {
        // Update active chip
        document.querySelectorAll('#filterChips .filter-chip').forEach(c => c.classList.remove('active'));
        chipBtn.classList.add('active');

        // Find the table in the same glass card
        const glass = chipBtn.closest('.glass');
        const rows = glass.querySelectorAll('tbody tr');
        rows.forEach(row => {
            if (color === 'all') {
                row.style.display = '';
            } else {
                row.style.display = row.classList.contains('row-' + color) ? '' : 'none';
            }
        });
    }

    // Suggest sentence via Gemini AJAX
    async function suggestSentence(btn, query) {
        const wrap = btn.closest('.suggest-wrap');
        const resultEl = wrap.querySelector('.suggest-result');
        const pageDataEl = document.getElementById('pageData');

        if (!pageDataEl) {
            resultEl.textContent = 'Sayfa verisi bulunamadı.';
            resultEl.style.display = 'block';
            return;
        }

        const pageData = JSON.parse(pageDataEl.textContent);

        // Disable button, show spinner
        const originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-sm"></span> Yükleniyor…';
        resultEl.style.display = 'none';

        try {
            const resp = await fetch('/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    query: query,
                    title: pageData.title,
                    content_summary: pageData.content_summary,
                    api_key: pageData.api_key
                })
            });

            const data = await resp.json();

            if (data.error) {
                resultEl.innerHTML = '<span class="suggest-error">⚠ ' + escapeHtml(data.error) + '</span>';
            } else {
                resultEl.innerHTML = escapeHtml(data.sentence) +
                    ' <button class="copy-btn" onclick="copyText(this)" title="Kopyala">📋 Kopyala</button>';
            }
            resultEl.style.display = 'block';
        } catch (err) {
            resultEl.innerHTML = '<span class="suggest-error">⚠ İstek başarısız: ' + escapeHtml(err.message) + '</span>';
            resultEl.style.display = 'block';
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
    }

    function copyText(btn) {
        const text = btn.parentElement.childNodes[0].textContent.trim();
        navigator.clipboard.writeText(text).then(() => {
            const orig = btn.textContent;
            btn.textContent = '✓ Kopyalandı';
            setTimeout(() => btn.textContent = orig, 1500);
        });
    }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
</script>
</body>
</html>
