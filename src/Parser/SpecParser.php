<?php

declare(strict_types=1);

namespace EidCloud\ApiCli\Parser;

use InvalidArgumentException;
use RuntimeException;

/**
 * Universal OpenAPI 3.0 / 3.1 & Swagger 2.0 Specification Parser.
 */
class SpecParser
{
    private array $spec;
    private array $resolvedCache = [];

    public function __construct(string|array $source)
    {
        if (is_array($source)) {
            $this->spec = $source;
        } elseif (is_string($source)) {
            $this->spec = $this->loadSource($source);
        } else {
            throw new InvalidArgumentException("Specification must be a file path, URL, JSON string, or array.");
        }
    }

    private function loadSource(string $source): array
    {
        $content = '';
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: eidcloud-api-cli/1.0.0\r\nAccept: application/json\r\n",
                    'timeout' => 15,
                ]
            ]);
            $content = @file_get_contents($source, false, $context);
            if ($content === false) {
                throw new RuntimeException("Failed to download OpenAPI spec from URL: {$source}");
            }
        } elseif (file_exists($source)) {
            $content = file_get_contents($source);
            if ($content === false) {
                throw new RuntimeException("Failed to read OpenAPI spec file: {$source}");
            }
        } else {
            // Check if string is raw JSON
            $trimmed = trim($source);
            if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
                $content = $source;
            } else {
                throw new InvalidArgumentException("Spec file does not exist: {$source}");
            }
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON specification: " . json_last_error_msg());
        }

        return $decoded;
    }

    public function getRawSpec(): array
    {
        return $this->spec;
    }

    public function getTitle(): string
    {
        return $this->spec['info']['title'] ?? 'API Client';
    }

    public function getVersion(): string
    {
        return $this->spec['info']['version'] ?? '1.0.0';
    }

    public function getDescription(): string
    {
        return $this->spec['info']['description'] ?? 'Generated API CLI tool powered by EidCloud.';
    }

    public function getBaseUrl(): string
    {
        if (!empty($this->spec['servers'][0]['url'])) {
            return rtrim($this->spec['servers'][0]['url'], '/');
        }

        if (!empty($this->spec['host'])) {
            $scheme = !empty($this->spec['schemes'][0]) ? $this->spec['schemes'][0] : 'https';
            $basePath = !empty($this->spec['basePath']) ? rtrim($this->spec['basePath'], '/') : '';
            return "{$scheme}://{$this->spec['host']}{$basePath}";
        }

        return 'http://localhost';
    }

    public function getSecuritySchemes(): array
    {
        if (isset($this->spec['components']['securitySchemes'])) {
            return $this->spec['components']['securitySchemes'];
        }
        if (isset($this->spec['securityDefinitions'])) {
            return $this->spec['securityDefinitions'];
        }
        return [];
    }

    public function getOperations(): array
    {
        $operations = [];
        $paths = $this->spec['paths'] ?? [];

        foreach ($paths as $path => $methods) {
            if (!is_array($methods)) {
                continue;
            }

            // Extract path-level parameters
            $pathParams = $methods['parameters'] ?? [];

            foreach ($methods as $method => $opData) {
                $upperMethod = strtoupper($method);
                if (!in_array($upperMethod, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'])) {
                    continue;
                }

                $mergedParams = array_merge($pathParams, $opData['parameters'] ?? []);
                $resolvedParams = [];

                foreach ($mergedParams as $param) {
                    $resolvedParams[] = $this->resolveRef($param);
                }

                $operationId = $opData['operationId'] ?? $this->generateOperationId($upperMethod, $path);
                $tags = !empty($opData['tags']) ? $opData['tags'] : [$this->extractResourceFromPath($path)];

                $operations[] = [
                    'operationId' => $operationId,
                    'path' => $path,
                    'method' => $upperMethod,
                    'summary' => $opData['summary'] ?? '',
                    'description' => $opData['description'] ?? '',
                    'tags' => $tags,
                    'resource' => strtolower($tags[0]),
                    'parameters' => $resolvedParams,
                    'requestBody' => isset($opData['requestBody']) ? $this->resolveRef($opData['requestBody']) : null,
                    'responses' => $opData['responses'] ?? [],
                    'security' => $opData['security'] ?? ($this->spec['security'] ?? [])
                ];
            }
        }

        return $operations;
    }

    public function resolveRef(array $element): array
    {
        if (!isset($element['$ref'])) {
            return $element;
        }

        $ref = $element['$ref'];
        if (isset($this->resolvedCache[$ref])) {
            return $this->resolvedCache[$ref];
        }

        if (str_starts_with($ref, '#/')) {
            $parts = explode('/', substr($ref, 2));
            $current = $this->spec;
            foreach ($parts as $part) {
                $part = str_replace('~1', '/', str_replace('~0', '~', $part));
                if (isset($current[$part])) {
                    $current = $current[$part];
                } else {
                    return $element; // Fallback
                }
            }
            $resolved = $this->resolveRef($current);
            $this->resolvedCache[$ref] = $resolved;
            return $resolved;
        }

        return $element;
    }

    public function generateOperationId(string $method, string $path): string
    {
        $clean = preg_replace('/\{([^}]+)\}/', 'By_$1', $path);
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $clean);
        $clean = trim(preg_replace('/_+/', '_', $clean), '_');
        return strtolower($method) . '_' . $clean;
    }

    public function extractResourceFromPath(string $path): string
    {
        $parts = explode('/', trim($path, '/'));
        $first = $parts[0] ?? 'general';
        return preg_replace('/\{.*?\}/', '', $first) ?: 'default';
    }
}
