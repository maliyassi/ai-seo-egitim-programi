<?php

declare(strict_types=1);

use KeywordAnalyzer\GeminiOptimizer;

require_once dirname(__DIR__) . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

const DEFAULT_GEMINI_MODEL = 'gemini-3-flash-preview';
const DEFAULT_GEMINI_API_KEY = 'BURAYA_GEMINI_API_KEYINIZI_GIRIN';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Sadece POST istekleri kabul edilir.']);
    exit;
}

set_time_limit(0);

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Geçersiz JSON.']);
    exit;
}

$query = trim((string) ($body['query'] ?? ''));
$title = trim((string) ($body['title'] ?? ''));
$contentSummary = trim((string) ($body['content_summary'] ?? ''));
$apiKey = trim((string) ($body['api_key'] ?? (getenv('GEMINI_API_KEY') ?: DEFAULT_GEMINI_API_KEY)));

if ($query === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Sorgu boş olamaz.']);
    exit;
}

try {
    $optimizer = new GeminiOptimizer($apiKey, DEFAULT_GEMINI_MODEL);
    $sentence = $optimizer->suggestSentence($query, $title, $contentSummary);
    echo json_encode(['sentence' => $sentence], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
