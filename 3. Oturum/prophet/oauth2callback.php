<?php
require_once __DIR__ . '/vendor/autoload.php';

session_start();

$client = new Google\Client();
$client->setAuthConfig(__DIR__ . '/credentials.json');
$client->addScope('https://www.googleapis.com/auth/webmasters.readonly');
$client->setRedirectUri('http://localhost:8080/oauth2callback.php');
$client->setAccessType('offline');
$client->setPrompt('consent');

if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    $client->setAccessToken($token);
    
    // Token'ı dosyaya kaydet (gerçek uygulamada DB'ye veya güvenli bir yere kaydedilmeli)
    file_put_contents(__DIR__ . '/token.json', json_encode($client->getAccessToken()));
    
    header('Location: index.php');
    exit;
}

if (!file_exists(__DIR__ . '/token.json')) {
    $authUrl = $client->createAuthUrl();
    echo "<a href='$authUrl'>Google ile Giriş Yap (GSC Verileri İçin)</a>";
    exit;
} else {
    $accessToken = json_decode(file_get_contents(__DIR__ . '/token.json'), true);
    $client->setAccessToken($accessToken);
}

if ($client->isAccessTokenExpired()) {
    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    file_put_contents(__DIR__ . '/token.json', json_encode($client->getAccessToken()));
}

$service = new Google\Service\SearchConsole($client);

// Mülkleri Listeleme Testi
try {
    $sites = $service->sites->listSites();
    echo "<h3>Bağlı GSC Mülkleri:</h3><ul>";
    foreach ($sites->getSiteEntry() as $site) {
        $siteUrl = $site->getSiteUrl();
        echo "<li>{$siteUrl}</li>";
    }
    echo "</ul>";
} catch (Exception $e) {
    echo "Hata: " . $e->getMessage();
}
?>
