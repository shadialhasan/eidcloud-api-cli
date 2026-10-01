<?php

declare(strict_types=1);

namespace EidCloud\ApiCli\Command;

use EidCloud\ApiCli\Client\HttpClient;

/**
 * Manages automatic pagination over cursor-based and offset/page-based REST APIs.
 */
class PaginationHandler
{
    private HttpClient $client;

    public function __construct(HttpClient $client)
    {
        $this->client = $client;
    }

    /**
     * Executes requests iteratively while collecting all pages.
     */
    public function paginateAll(
        string $method,
        string $path,
        array $queryParams,
        mixed $body,
        array $headers,
        int $maxPages = 20,
        int $limit = 50,
        callable $onPage = null
    ): array {
        $allRecords = [];
        $currentPage = 1;
        $params = $queryParams;

        if (!isset($params['limit'])) {
            $params['limit'] = $limit;
        }

        while ($currentPage <= $maxPages) {
            $params['page'] = $currentPage;
            $res = $this->client->request($method, $path, $params, $body, $headers);

            if ($res['status'] >= 400) {
                break;
            }

            $items = $this->extractItemsFromResponse($res['json'] ?? $res['body']);
            if (empty($items)) {
                break;
            }

            if ($onPage !== null) {
                $onPage($items, $currentPage);
            }

            $allRecords = array_merge($allRecords, $items);

            // If less records returned than limit, we reached the end
            if (count($items) < $limit) {
                break;
            }

            // Check if next page link or cursor is missing
            $currentPage++;
        }

        return $allRecords;
    }

    public function extractItemsFromResponse(mixed $response): array
    {
        if (is_array($response)) {
            // If response is already an indexed array of items
            if (!empty($response) && array_keys($response) === range(0, count($response) - 1)) {
                return $response;
            }

            // Common pagination wrapper keys
            $keys = ['data', 'items', 'results', 'records', 'rows', 'entities'];
            foreach ($keys as $k) {
                if (isset($response[$k]) && is_array($response[$k])) {
                    return $response[$k];
                }
            }

            // If single object, return as single-item array
            return [$response];
        }

        return [];
    }
}
