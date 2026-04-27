<?php

declare(strict_types=1);

namespace KeywordAnalyzer;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

final class ContentExtractor
{
    /**
     * @return array{title: string, content: string, analyzed_text: string}
     */
    public function extract(string $url, string $contentXPath): array
    {
        $html = $this->fetchUrl($url);
        $dom = $this->loadHtml($html);
        $xpath = new DOMXPath($dom);

        $titleNode = $xpath->query('//h1')?->item(0);
        $title = $titleNode?->textContent ?? '';

        $nodes = $xpath->query($contentXPath);
        if ($nodes === false) {
            throw new RuntimeException('Geçersiz XPath ifadesi.');
        }

        if ($nodes->length === 0) {
            throw new RuntimeException('XPath ile içerik alanı bulunamadı.');
        }

        $chunks = [];
        foreach ($nodes as $node) {
            $chunks[] = $this->extractNodeTextWithoutNoise($node);
        }

        $content = trim(preg_replace('/\s+/u', ' ', implode(' ', $chunks)) ?? '');
        $analyzed = trim($title . ' ' . $content);

        return [
            'title' => trim($title),
            'content' => $content,
            'analyzed_text' => $analyzed,
        ];
    }

    private function fetchUrl(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: KeywordAnalyzerBot/1.0\r\nAccept-Language: tr-TR,tr;q=0.9,en;q=0.8\r\n",
                'timeout' => 20,
            ],
        ]);

        $html = @file_get_contents($url, false, $context);
        if ($html === false || $html === '') {
            throw new RuntimeException('URL içeriği alınamadı. URL erişilebilir mi kontrol edin.');
        }

        return $html;
    }

    private function loadHtml(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return $dom;
    }

    private function extractNodeTextWithoutNoise(DOMNode $node): string
    {
        $tmp = new DOMDocument('1.0', 'UTF-8');
        $imported = $tmp->importNode($node->cloneNode(true), true);
        if (!$imported instanceof DOMNode) {
            return '';
        }

        $tmp->appendChild($imported);
        $xpath = new DOMXPath($tmp);

        $removeList = [
            './/script',
            './/style',
            './/noscript',
            './/svg',
            './/h1',
        ];

        foreach ($removeList as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }

            for ($i = $nodes->length - 1; $i >= 0; --$i) {
                $candidate = $nodes->item($i);
                if ($candidate instanceof DOMElement && $candidate->parentNode !== null) {
                    $candidate->parentNode->removeChild($candidate);
                }
            }
        }

        return trim($tmp->textContent ?? '');
    }
}
