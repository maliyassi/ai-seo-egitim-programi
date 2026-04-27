<?php
require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json');

$credentialsPath = __DIR__ . '/credentials.json';
$tokenPath = __DIR__ . '/token.json';

if (!file_exists($credentialsPath) || !file_exists($tokenPath)) {
    echo json_encode(['error' => 'Yetkilendirme eksik. Lütfen önce Google hesabınızı bağlayın.']);
    exit;
}

$client = new Google\Client();
$client->setAuthConfig($credentialsPath);
$client->addScope('https://www.googleapis.com/auth/webmasters.readonly');

$accessToken = json_decode(file_get_contents($tokenPath), true);
$client->setAccessToken($accessToken);

if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    } else {
        echo json_encode(['error' => 'Oturum süresi dolmuş. Lütfen tekrar giriş yapın.']);
        exit;
    }
}

$service = new Google\Service\SearchConsole($client);

// İstek parametreleri
$siteUrl = $_GET['siteUrl'] ?? '';
$metric = $_GET['metric'] ?? 'clicks'; // clicks veya impressions
$days = (int)($_GET['days'] ?? 480); // API limiti genelde 16 ay (yaklaşık 480 gün)

if (empty($siteUrl)) {
    echo json_encode(['error' => 'Geçerli bir site URL belirtilmedi.']);
    exit;
}

// Tarih aralıkları
$endDate = date('Y-m-d', strtotime('-2 days')); // GSC verileri genelde 2 gün geriden gelir
$startDate = date('Y-m-d', strtotime("-{$days} days", strtotime($endDate)));

$request = new Google\Service\SearchConsole\SearchAnalyticsQueryRequest();
$request->setStartDate($startDate);
$request->setEndDate($endDate);
$request->setDimensions(['date']); // Günlük boyut
$request->setRowLimit(25000);

try {
    $response = $service->searchanalytics->query($siteUrl, $request);
    $rows = $response->getRows();
    
    if (empty($rows)) {
        echo json_encode(['error' => 'Bu mülk için veri bulunamadı.']);
        exit;
    }

    $csvData = "ds,y\n"; // Prophet formatı
    $historicalData = [
        'labels' => [],
        'values' => []
    ];

    foreach ($rows as $row) {
        $date = $row->getKeys()[0];
        $val = $metric === 'impressions' ? $row->getImpressions() : $row->getClicks();
        
        $csvData .= "{$date},{$val}\n";
        
        $historicalData['labels'][] = $date;
        $historicalData['values'][] = $val;
    }

    // CSV'yi geçici olarak bir dosyaya kaydet (Python'un okuyabilmesi için)
    $csvFilename = __DIR__ . '/data_' . md5($siteUrl) . '.csv';
    file_put_contents($csvFilename, $csvData);

    echo json_encode([
        'success' => true,
        'message' => 'Veriler GSC üzerinden başarıyla çekildi.',
        'historical' => $historicalData,
        'csv_file' => $csvFilename
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => 'API Hatası: ' . $e->getMessage()]);
}
?>
