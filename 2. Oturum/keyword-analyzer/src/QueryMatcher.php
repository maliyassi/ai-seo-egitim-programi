<?php

declare(strict_types=1);

namespace KeywordAnalyzer;

final class QueryMatcher
{
    /**
     * @param list<array{query:string, clicks:float, impressions:float, ctr:float, position:float}> $queries
     * @return list<array{query:string, status:string, color:string, matched_words:list<string>, clicks:float, impressions:float, ctr:float, position:float}>
     */
    public function analyze(array $queries, string $analyzedText): array
    {
        $contentNorm = TextNormalizer::normalize($analyzedText);
        $contentNormLatin = TextNormalizer::normalizeLatinized($analyzedText);

        $contentSet = array_fill_keys(TextNormalizer::tokenize($contentNorm), true);
        $contentLatinSet = array_fill_keys(TextNormalizer::tokenize($contentNormLatin), true);

        $rows = [];
        foreach ($queries as $row) {
            $match = $this->classifyQuery(
                $row['query'],
                $contentNorm,
                $contentNormLatin,
                $contentSet,
                $contentLatinSet
            );

            $rows[] = [
                'query' => $row['query'],
                'status' => $match['status'],
                'color' => $match['color'],
                'matched_words' => $match['matched_words'],
                'clicks' => $row['clicks'],
                'impressions' => $row['impressions'],
                'ctr' => $row['ctr'],
                'position' => $row['position'],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,bool> $contentSet
     * @param array<string,bool> $contentLatinSet
     * @return array{status:string, color:string, matched_words:list<string>}
     */
    private function classifyQuery(
        string $query,
        string $contentNorm,
        string $contentNormLatin,
        array $contentSet,
        array $contentLatinSet
    ): array {
        $qNorm = TextNormalizer::normalize($query);
        $qNormLatin = TextNormalizer::normalizeLatinized($query);

        if ($qNorm === '') {
            return ['status' => 'Eslesme yok', 'color' => 'red', 'matched_words' => []];
        }

        if ($this->containsPhrase($contentNorm, $qNorm)) {
            return ['status' => 'Sirali tam eslesme', 'color' => 'green', 'matched_words' => []];
        }

        $tokens = TextNormalizer::tokenize($qNorm);
        $strictMatches = [];
        foreach ($tokens as $token) {
            if (isset($contentSet[$token])) {
                $strictMatches[] = $token;
            }
        }

        if (count($strictMatches) === count($tokens) && count($tokens) > 0) {
            return ['status' => 'Sirasiz tam eslesme', 'color' => 'orange', 'matched_words' => []];
        }

        if (count($strictMatches) > 0) {
            return ['status' => 'Kismi eslesme', 'color' => 'yellow', 'matched_words' => array_values(array_unique($strictMatches))];
        }

        if ($qNormLatin !== '' && $this->containsPhrase($contentNormLatin, $qNormLatin)) {
            return ['status' => 'Yazim hatasi eslesmesi', 'color' => 'blue', 'matched_words' => []];
        }

        $latinTokens = TextNormalizer::tokenize($qNormLatin);
        $latinMatches = [];
        foreach ($latinTokens as $token) {
            if (isset($contentLatinSet[$token])) {
                $latinMatches[] = $token;
            }
        }

        if (count($latinTokens) > 0 && count($latinMatches) === count($latinTokens)) {
            return ['status' => 'Yazim hatasi eslesmesi', 'color' => 'blue', 'matched_words' => array_values(array_unique($latinMatches))];
        }

        return ['status' => 'Eslesme yok', 'color' => 'red', 'matched_words' => []];
    }

    private function containsPhrase(string $haystack, string $needle): bool
    {
        return str_contains(' ' . $haystack . ' ', ' ' . $needle . ' ');
    }
}
