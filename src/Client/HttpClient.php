<?php

declare(strict_types=1);

namespace EidCloud\ApiCli\Client;

use RuntimeException;

/**
 * Pure PHP HTTP Client using native stream wrappers (no curl dependency required).
 */
class HttpClient
{
    private string $baseUrl;
    private array $defaultHeaders;
    private int $timeout;
    private bool $verifySsl;

    public function __construct(
        string $baseUrl = '',
        array $defaultHeaders = [],
        int $timeout = 30,
        bool $verifySsl = true
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->defaultHeaders = array_merge([
            'User-Agent' => 'eidcloud-api-cli/1.0.0 (PHP ' . PHP_VERSION . ')',
            'Accept' => 'application/json, text/plain, */*'
        ], $defaultHeaders);
        $this->timeout = $timeout;
        $this->verifySsl = $verifySsl;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setDefaultHeader(string $name, string $value): self
    {
        $this->defaultHeaders[$name] = $value;
        return $this;
    }

    /**
     * Send HTTP Request
     *
     * @param string $method GET, POST, PUT, DELETE, PATCH, etc.
     * @param string $path URL path or full URL
     * @param array $queryParams Key-value query parameters
     * @param mixed $body Array/string payload
     * @param array $headers Request headers
     * @return array [ 'status' => int, 'headers' => array, 'body' => string, 'json' => mixed ]
     */
    public function request(
        string $method,
        string $path,
        array $queryParams = [],
        mixed $body = null,
        array $headers = []
    ): array {
        $url = $this->buildUrl($path, $queryParams);
        $mergedHeaders = array_merge($this->defaultHeaders, $headers);

        $content = '';
        if ($body !== null) {
            if (is_array($body) || is_object($body)) {
                $content = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!isset($mergedHeaders['Content-Type'])) {
                    $mergedHeaders['Content-Type'] = 'application/json';
                }
            } else {
                $content = (string)$body;
            }
            $mergedHeaders['Content-Length'] = (string)strlen($content);
        }

        $headerLines = [];
        foreach ($mergedHeaders as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        $contextOptions = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines) . "\r\n",
                'content' => $content,
                'timeout' => $this->timeout,
                'ignore_errors' => true, // Retrieve body even on 4xx/5xx
                'follow_location' => 1,
                'max_redirects' => 5
            ],
            'ssl' => [
                'verify_peer' => $this->verifySsl,
                'verify_peer_name' => $this->verifySsl,
            ]
        ];

        $context = stream_context_create($contextOptions);
        $start = microtime(true);
        $responseBody = @file_get_contents($url, false, $context);
        $duration = microtime(true) - $start;

        if ($responseBody === false && empty($http_response_header)) {
            $lastError = error_get_last();
            $msg = $lastError['message'] ?? 'Connection failed or host unreachable.';
            throw new RuntimeException("HTTP Request Error [{$method} {$url}]: {$msg}");
        }

        $headersList = $http_response_header ?? [];
        $statusLine = $headersList[0] ?? 'HTTP/1.1 200 OK';
        preg_match('{HTTP\/\S*\s(\d{3})}', $statusLine, $match);
        $statusCode = isset($match[1]) ? (int)$match[1] : 200;

        $parsedHeaders = [];
        foreach ($headersList as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($k))] = trim($v);
            }
        }

        $decodedJson = null;
        if (!empty($responseBody)) {
            $decodedJson = json_decode($responseBody, true);
        }

        return [
            'status' => $statusCode,
            'status_line' => $statusLine,
            'headers' => $parsedHeaders,
            'body' => $responseBody ?: '',
            'json' => $decodedJson,
            'duration_ms' => round($duration * 1000, 2),
            'url' => $url,
            'method' => strtoupper($method)
        ];
    }

    private function buildUrl(string $path, array $queryParams = []): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $url = $path;
        } else {
            $url = $this->baseUrl . '/' . ltrim($path, '/');
        }

        if (!empty($queryParams)) {
            $query = http_build_query($queryParams);
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . $query;
        }

        return $url;
    }
}
