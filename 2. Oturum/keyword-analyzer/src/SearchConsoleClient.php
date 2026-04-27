<?php

declare(strict_types=1);

namespace KeywordAnalyzer;

use Google\Client;
use Google\Service\Exception;
use Google\Service\SearchConsole;
use Google\Service\SearchConsole\ApiDimensionFilter;
use Google\Service\SearchConsole\ApiDimensionFilterGroup;
use Google\Service\SearchConsole\SearchAnalyticsQueryRequest;
use Google\Service\SearchConsole\WmxSite;
use RuntimeException;

final class SearchConsoleClient
{
    private SearchConsole $service;

    public function __construct(string $credentialsPath)
    {
        if (!is_file($credentialsPath)) {
            throw new RuntimeException('Google JSON dosyası bulunamadı: ' . $credentialsPath);
        }

        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->setScopes([SearchConsole::WEBMASTERS_READONLY]);

        $this->service = new SearchConsole($client);
    }

    /**
     * @return list<string>
     */
    public function listAccessibleSites(): array
    {
        try {
            $siteList = $this->service->sites->listSites();
        } catch (Exception $e) {
            throw new RuntimeException('Search Console site listesi alinamadi: ' . $e->getMessage(), 0, $e);
        }

        $entries = $siteList->getSiteEntry() ?? [];
        $sites = [];

        foreach ($entries as $entry) {
            if (!$entry instanceof WmxSite) {
                continue;
            }

            $permission = (string) ($entry->getPermissionLevel() ?? '');
            if ($permission === 'siteUnverifiedUser') {
                continue;
            }

            $siteUrl = (string) ($entry->getSiteUrl() ?? '');
            if ($siteUrl !== '') {
                $sites[] = $siteUrl;
            }
        }

        return array_values(array_unique($sites));
    }

    public function resolveSiteUrlForPage(string $pageUrl, string $preferredSiteUrl = ''): string
    {
        $sites = $this->listAccessibleSites();
        if ($sites === []) {
            throw new RuntimeException('Bu kimlik ile Search Console property listesi bos. Service account e-posta adresini property yetkilerine ekleyin.');
        }

        $preferredSiteUrl = trim($preferredSiteUrl);
        if ($preferredSiteUrl !== '' && in_array($preferredSiteUrl, $sites, true)) {
            return $preferredSiteUrl;
        }

        $host = (string) (parse_url($pageUrl, PHP_URL_HOST) ?? '');
        if ($host === '') {
            throw new RuntimeException('URL host bilgisi okunamadi: ' . $pageUrl);
        }
        $host = strtolower($host);
        $hostNoWww = preg_replace('/^www\./', '', $host) ?? $host;

        $candidates = [];
        $candidates[] = 'sc-domain:' . $hostNoWww;
        $candidates[] = 'https://' . $host . '/';
        $candidates[] = 'https://' . $hostNoWww . '/';
        $candidates[] = 'https://www.' . $hostNoWww . '/';
        $candidates[] = 'http://' . $host . '/';
        $candidates[] = 'http://' . $hostNoWww . '/';
        $candidates[] = 'http://www.' . $hostNoWww . '/';

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (in_array($candidate, $sites, true)) {
                return $candidate;
            }
        }

        $preview = implode(', ', array_slice($sites, 0, 20));
        throw new RuntimeException(
            'URL icin erisilebilir property bulunamadi. Bu hesapla gorulen propertyler: ' . $preview
        );
    }

    /**
     * @return list<array{query:string, clicks:float, impressions:float, ctr:float, position:float}>
     */
    public function getQueriesForPage(string $siteUrl, string $pageUrl, string $startDate, string $endDate): array
    {
        $filter = new ApiDimensionFilter();
        $filter->setDimension('page');
        $filter->setOperator('equals');
        $filter->setExpression($pageUrl);

        $group = new ApiDimensionFilterGroup();
        $group->setFilters([$filter]);

        $request = new SearchAnalyticsQueryRequest();
        $request->setStartDate($startDate);
        $request->setEndDate($endDate);
        $request->setDimensions(['query']);
        $request->setRowLimit(25000);
        $request->setDimensionFilterGroups([$group]);

        try {
            $response = $this->service->searchanalytics->query($siteUrl, $request);
        } catch (Exception $e) {
            throw new RuntimeException('Search Console API hatası: ' . $e->getMessage(), 0, $e);
        }

        $rows = $response->getRows() ?? [];
        $result = [];

        foreach ($rows as $row) {
            $keys = $row->getKeys() ?? [];
            $query = $keys[0] ?? '';
            if ($query === '') {
                continue;
            }

            $result[] = [
                'query' => (string) $query,
                'clicks' => (float) ($row->getClicks() ?? 0),
                'impressions' => (float) ($row->getImpressions() ?? 0),
                'ctr' => (float) ($row->getCtr() ?? 0),
                'position' => (float) ($row->getPosition() ?? 0),
            ];
        }

        return $result;
    }
}
