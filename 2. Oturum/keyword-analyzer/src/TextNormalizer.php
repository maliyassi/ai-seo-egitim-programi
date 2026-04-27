<?php

declare(strict_types=1);

namespace KeywordAnalyzer;

final class TextNormalizer
{
    private const TURKISH_LATIN_MAP = [
        'ç' => 'c',
        'ğ' => 'g',
        'ı' => 'i',
        'ö' => 'o',
        'ş' => 's',
        'ü' => 'u',
        'â' => 'a',
        'î' => 'i',
        'û' => 'u',
    ];

    public static function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    public static function normalizeLatinized(string $text): string
    {
        $text = self::normalize($text);

        return strtr($text, self::TURKISH_LATIN_MAP);
    }

    /**
     * @return list<string>
     */
    public static function tokenize(string $normalizedText): array
    {
        if ($normalizedText === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalizedText), static fn (string $token): bool => $token !== ''));
    }
}
