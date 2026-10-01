<?php

declare(strict_types=1);

namespace EidCloud\ApiCli\Validator;

use InvalidArgumentException;

/**
 * Validates CLI arguments against OpenAPI parameter specifications and JSON schemas.
 */
class RequestValidator
{
    /**
     * Validates and coerces parameters according to OpenAPI parameter schemas.
     *
     * @param array $paramSpecs Array of parameter definitions from OpenAPI
     * @param array $provided Key-value pairs provided by CLI user
     * @return array [ 'valid' => bool, 'errors' => array, 'coerced' => array ]
     */
    public function validateParameters(array $paramSpecs, array $provided): array
    {
        $errors = [];
        $coerced = [];

        foreach ($paramSpecs as $spec) {
            $name = $spec['name'] ?? '';
            $required = $spec['required'] ?? false;
            $schema = $spec['schema'] ?? ['type' => 'string'];
            $type = $schema['type'] ?? 'string';

            $val = $provided[$name] ?? null;

            if ($val === null || $val === '') {
                if ($required) {
                    $in = $spec['in'] ?? 'query';
                    $errors[] = "Missing required parameter: --{$name} ({$in})";
                }
                continue;
            }

            // Coercion & Type checks
            switch ($type) {
                case 'integer':
                    if (!is_numeric($val) || (int)$val != $val) {
                        $errors[] = "Parameter --{$name} must be an integer, got: {$val}";
                    } else {
                        $coerced[$name] = (int)$val;
                    }
                    break;

                case 'number':
                    if (!is_numeric($val)) {
                        $errors[] = "Parameter --{$name} must be a number, got: {$val}";
                    } else {
                        $coerced[$name] = (float)$val;
                    }
                    break;

                case 'boolean':
                    $low = strtolower((string)$val);
                    if (in_array($low, ['true', '1', 'yes'], true)) {
                        $coerced[$name] = true;
                    } elseif (in_array($low, ['false', '0', 'no'], true)) {
                        $coerced[$name] = false;
                    } else {
                        $errors[] = "Parameter --{$name} must be a boolean (true/false), got: {$val}";
                    }
                    break;

                case 'array':
                    if (is_string($val)) {
                        $coerced[$name] = array_map('trim', explode(',', $val));
                    } else {
                        $coerced[$name] = (array)$val;
                    }
                    break;

                default:
                    // Check enum if present
                    if (!empty($schema['enum']) && !in_array($val, $schema['enum'], true)) {
                        $allowed = implode(', ', $schema['enum']);
                        $errors[] = "Parameter --{$name} must be one of [{$allowed}], got: {$val}";
                    } else {
                        $coerced[$name] = (string)$val;
                    }
                    break;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'coerced' => $coerced
        ];
    }

    /**
     * Validates JSON payload or raw body string.
     */
    public function validateBody(?array $bodySpec, mixed $body): array
    {
        if ($bodySpec === null || empty($bodySpec)) {
            return ['valid' => true, 'errors' => []];
        }

        $required = $bodySpec['required'] ?? false;
        if ($required && ($body === null || $body === '')) {
            return [
                'valid' => false,
                'errors' => ['Request body is required for this operation.']
            ];
        }

        return ['valid' => true, 'errors' => []];
    }
}
