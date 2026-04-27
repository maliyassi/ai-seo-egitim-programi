<?php
require_once __DIR__ . '/vendor/autoload.php';
session_start();

$credentialsPath = __DIR__ . '/credentials.json';
$tokenPath = __DIR__ . '/token.json';
$isAuthenticated = file_exists($credentialsPath) && file_exists($tokenPath);

$authUrl = '';
$sitesList = [];

if (file_exists($credentialsPath)) {
    $client = new Google\Client();
    $client->setAuthConfig($credentialsPath);
    $client->addScope('https://www.googleapis.com/auth/webmasters.readonly');
    $client->setRedirectUri('http://localhost:8080/oauth2callback.php');
    $client->setAccessType('offline');
    $client->setPrompt('consent');

    if (!$isAuthenticated) {
        $authUrl = $client->createAuthUrl();
    } else {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired()) {
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                file_put_contents($tokenPath, json_encode($client->getAccessToken()));
            } else {
                // Refresh token yoksa yeniden giriş yapmalı
                unlink($tokenPath);
                header("Location: " . $client->createAuthUrl());
                exit;
            }
        }

        // Mülkleri Çek
        $service = new Google\Service\SearchConsole($client);
        try {
            $sites = $service->sites->listSites();
            foreach ($sites->getSiteEntry() as $site) {
                $sitesList[] = $site->getSiteUrl();
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
} else {
    $error = "credentials.json dosyası bulunamadı. Lütfen Google Cloud Console'dan indirip proje dizinine ekleyin.";
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prophet SEO Forecast | Gelişmiş Analiz Aracı</title>
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/style.css">
    
    <!-- İkonlar için Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
</head>
<body class="dark-theme">
    
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar glass-panel">
            <div class="logo-area">
                <i class="ph ph-chart-line-up"></i>
                <h2>SEO Prophet</h2>
            </div>
            
            <nav class="main-nav">
                <a href="#" class="nav-item active"><i class="ph ph-squares-four"></i> Dashboard</a>
            </nav>

            <div class="sidebar-footer">
                <?php if ($isAuthenticated): ?>
                    <div class="status-badge success">
                        <span class="indicator"></span> Credentials Yüklü
                    </div>
                <?php else: ?>
                    <div class="status-badge error">
                        <span class="indicator"></span> Credentials Bekleniyor
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            
            <header class="topbar">
                <div>
                    <h1 class="page-title">Organik Trafik Analizi & Tahmini</h1>
                    <p class="page-subtitle">Zaman serisi ve makine öğrenimi ile gelecekteki trafiğinizi öngörün.</p>
                </div>
                
                <div class="user-profile">
                    <img src="https://ui-avatars.com/api/?name=Admin&background=8A2be2&color=fff" alt="User">
                </div>
            </header>

            <!-- Control Panel -->
            <section class="control-panel glass-panel">
                <div class="input-group">
                    <label for="propertySelect">GSC Mülkü</label>
                    <select id="propertySelect">
                        <?php if(!empty($sitesList)): ?>
                            <option value="">Analiz Edilecek Mülk Seçiniz...</option>
                            <?php foreach($sitesList as $siteUrl): ?>
                                <option value="<?php echo htmlspecialchars($siteUrl); ?>"><?php echo htmlspecialchars($siteUrl); ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="">GSC Bağlantısı Gerekiyor</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="input-group">
                    <label for="metricSelect">Metrik</label>
                    <select id="metricSelect">
                        <option value="clicks">Tıklama (Clicks)</option>
                        <option value="impressions">Gösterim (Impressions)</option>
                    </select>
                </div>

                <div class="input-group">
                    <label for="forecastPeriod">Tahmin Süresi (Gün)</label>
                    <input type="number" id="forecastPeriod" value="90" min="30" max="365">
                </div>

                <?php if ($isAuthenticated): ?>
                    <button id="runAnalysisBtn" class="btn-primary">
                        <i class="ph ph-magic-wand"></i> Analizi Başlat
                    </button>
                <?php else: ?>
                    <a href="<?php echo $authUrl; ?>" class="btn-primary" style="text-decoration:none; background: linear-gradient(135deg, #10b981, #059669);">
                        <i class="ph ph-google-logo"></i> GSC Bağla
                    </a>
                <?php endif; ?>
            </section>

            <!-- Loading State -->
            <div id="loadingState" class="hidden">
                <div class="spinner"></div>
                <p>Prophet modeli eğitiliyor ve tahmin çıkarılıyor...</p>
            </div>

            <!-- Charts & Results -->
            <section id="resultsArea" class="results-grid">
                
                <!-- Main Chart -->
                <div class="chart-card glass-panel span-2">
                    <div class="card-header">
                        <h3>Organik Trafik Tahmini (Prophet Y-Hat)</h3>
                        <div class="chart-actions">
                            <button class="icon-btn"><i class="ph ph-download-simple"></i></button>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="prophetChart"></canvas>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="stats-card glass-panel">
                    <div class="stat-icon"><i class="ph ph-trend-up"></i></div>
                    <div class="stat-info">
                        <h4>Beklenen Trafik Artışı</h4>
                        <span class="stat-value" id="expectedIncreaseVal">---</span>
                        <p class="stat-desc" id="expectedIncreaseDesc">Önümüzdeki --- gün için</p>
                    </div>
                </div>

                <div class="stats-card glass-panel">
                    <div class="stat-icon warning"><i class="ph ph-warning"></i></div>
                    <div class="stat-info">
                        <h4>Anomali Tespiti</h4>
                        <span class="stat-value" id="anomalyCountVal">---</span>
                        <p class="stat-desc">Geçmiş veride düşüş anı</p>
                    </div>
                </div>

                <div class="stats-card glass-panel">
                    <div class="stat-icon info"><i class="ph ph-calendar"></i></div>
                    <div class="stat-info">
                        <h4>Sezonallık Zirvesi</h4>
                        <span class="stat-value" id="seasonalityPeakVal">---</span>
                        <p class="stat-desc">Yıllık trend ağırlığı</p>
                    </div>
                </div>

            </section>

        </main>
    </div>

    <!-- Custom JS -->
    <script src="assets/script.js"></script>
</body>
</html>
