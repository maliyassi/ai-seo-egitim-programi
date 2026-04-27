<?php

declare(strict_types=1);

namespace KeywordAnalyzer;

use RuntimeException;

final class GeminiOptimizer
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-3-flash-preview'
    ) {
    }

    /**
     * @param list<string> $missingQueries
     */
    public function rewrite(string $title, string $content, array $missingQueries): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Gemini API key bos olamaz.');
        }

        $prompt = $this->buildPrompt($title, $content, $missingQueries);
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.4,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Gemini istegi basarisiz: ' . $error);
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            throw new RuntimeException('Gemini yaniti parse edilemedi.');
        }

        if ($httpCode >= 400) {
            $message = $json['error']['message'] ?? 'Bilinmeyen hata';
            throw new RuntimeException('Gemini API hatasi: ' . $message);
        }

        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $text = is_string($text) ? trim($text) : '';
        if ($text === '') {
            throw new RuntimeException('Gemini bos icerik dondurdu.');
        }

        return $text;
    }

    /**
     * Tek bir sorgu için doğal bir cümle önerisi üretir.
     */
    public function suggestSentence(string $query, string $title, string $contentSummary): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Gemini API key bos olamaz.');
        }

        $contentPreview = mb_substr($contentSummary, 0, 800, 'UTF-8');

        $prompt = <<<PROMPT
Asagida bir web sayfasinin basligi, icerik ozeti ve bir arama sorgusu verilmistir.

Gorev: Bu arama sorgusunun anlamini iceren, sayfanin konusuyla uyumlu, dogal Turkce bir cumle yaz.

Onemli kurallar:
1) Cumleyi DOGAL Turkce dilbilgisi kurallarina gore yaz. Kelimeleri bitisik YAZMA, her kelimeyi ayri yaz.
2) Ornegin "bosanmis kadinlara maas varmi" sorgusunu soyle kullan: "boşanmış kadınlara maaş var mı" — kelimeleri ayir, Turkce karakterleri dogru kullan.
3) Turkce ozel karakterleri kullan: ı, ş, ç, ğ, ö, ü, İ, Ş, Ç, Ğ, Ö, Ü
4) Sorguyu oldugu gibi kopyalama, ANLAMINI koru ama dogal Turkce ile yaz.
5) Sadece tek bir cumle dondur, baska hicbir sey yazma.
6) Markdown/HTML kullanma, duz metin olsun.
7) Cumle SEO uyumlu, okunakli ve sayfanin konusuyla ilgili olmali.

Sayfa basligi: {$title}

Icerik ozeti:
{$contentPreview}

Arama sorgusu: {$query}
PROMPT;

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.7,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Gemini istegi basarisiz: ' . $error);
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            throw new RuntimeException('Gemini yaniti parse edilemedi.');
        }

        if ($httpCode >= 400) {
            $message = $json['error']['message'] ?? 'Bilinmeyen hata';
            throw new RuntimeException('Gemini API hatasi: ' . $message);
        }

        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $text = is_string($text) ? trim($text) : '';
        if ($text === '') {
            throw new RuntimeException('Gemini bos icerik dondurdu.');
        }

        return $text;
    }

    /**
     * @param list<string> $missingQueries
     */
    private function buildPrompt(string $title, string $content, array $missingQueries): string
    {
        $queries = implode("\n- ", $missingQueries);

        return <<<PROMPT
Asagidaki Turkce icerigi dogal kalacak sekilde yeniden yaz.

Kurallar:
1) Verilecek eksik sorgularin her biri metinde ayni karakterlerle EN AZ bir kez gecsin.
2) Sadece duz metin dondur, markdown/HTML kullanma.
3) Basligi metnin ilk satirina koru.
4) Anlami bozma, okunakli ve SEO uyumlu tut.
5) Anahtar kelimeleri asiri tekrar etme.

Baslik:
{$title}

Mevcut icerik:
{$content}

Gecmesi zorunlu eksik sorgular:
- {$queries}
PROMPT;
    }
}
