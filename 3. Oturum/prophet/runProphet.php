<?php
header('Content-Type: application/json');

// İstek parametrelerini al
$csvFile = $_GET['csv'] ?? '';
$period = (int)($_GET['period'] ?? 90);

if (empty($csvFile) || !file_exists($csvFile)) {
    echo json_encode(['error' => 'Geçerli bir CSV veri dosyası bulunamadı.']);
    exit;
}

// Güvenlik: Yalnızca mevcut klasördeki data_*.csv dosyalarına izin ver
$basename = basename($csvFile);
if (!preg_match('/^data_[a-f0-9]{32}\.csv$/', $basename)) {
    echo json_encode(['error' => 'Geçersiz dosya adı formatı.']);
    exit;
}

// Python betiğinin yolu
$pythonScript = __DIR__ . '/prophet_forecast.py';
$csvPath = __DIR__ . '/' . $basename;

// Komutu hazırla
// ÖNEMLİ: MacOS (Apple Silicon) ve genel Unix sistemleri için `python3` komutu varsayılır.
$command = escapeshellcmd("python3 $pythonScript $csvPath $period");

// Betiği çalıştır ve çıktıyı al
$output = shell_exec($command);

if ($output === null) {
    echo json_encode(['error' => 'Python scripti çalıştırılamadı (shell_exec hatası).']);
    exit;
}

// Çıktıyı doğrudan JS tarafına yansıt (Python zat JSON basıyor)
echo $output;

// İşlem bitince geçici CSV dosyasını temizlemek iyi bir fikir olabilir
// unlink($csvPath);
?>
