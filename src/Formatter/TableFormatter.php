<?php

declare(strict_types=1);

namespace EidCloud\ApiCli\Formatter;

/**
 * High-performance ANSI table renderer, CSV exporter, and pretty JSON outputter.
 */
class TableFormatter
{
    private bool $useColor;

    public function __construct(bool $useColor = true)
    {
        $this->useColor = $useColor && $this->supportsAnsi();
    }

    private function supportsAnsi(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return getenv('ANSICON') !== false || getenv('ConEmuANSI') === 'ON' || getenv('TERM') === 'xterm' || (function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT));
        }
        return function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }

    public function color(string $text, string $color): string
    {
        if (!$this->useColor) {
            return $text;
        }

        $codes = [
            'red' => "\033[31m",
            'green' => "\033[32m",
            'yellow' => "\033[33m",
            'blue' => "\033[34m",
            'magenta' => "\033[35m",
            'cyan' => "\033[36m",
            'white' => "\033[37m",
            'bold' => "\033[1m",
            'dim' => "\033[2m",
            'reset' => "\033[0m"
        ];

        return ($codes[$color] ?? '') . $text . $codes['reset'];
    }

    /**
     * Render an array of rows into a beautiful box table.
     */
    public function renderTable(array $rows, array $headers = []): string
    {
        if (empty($rows)) {
            return $this->color("No records found.\n", 'dim');
        }

        // If list of simple scalar values or non-assoc
        if (!is_array(reset($rows))) {
            $normalized = [];
            foreach ($rows as $k => $v) {
                $normalized[] = ['Key' => $k, 'Value' => is_scalar($v) ? (string)$v : json_encode($v)];
            }
            $rows = $normalized;
        }

        // Normalize rows to associative arrays
        $normalizedRows = [];
        $allKeys = [];

        foreach ($rows as $item) {
            if (!is_array($item)) {
                $item = ['value' => $item];
            }
            $flat = $this->flattenRow($item);
            $normalizedRows[] = $flat;
            foreach (array_keys($flat) as $k) {
                $allKeys[$k] = true;
            }
        }

        if (empty($headers)) {
            $headers = array_keys($allKeys);
        }

        // Calculate max column widths
        $widths = [];
        foreach ($headers as $h) {
            $widths[$h] = mb_strwidth((string)$h);
        }

        foreach ($normalizedRows as $row) {
            foreach ($headers as $h) {
                $val = isset($row[$h]) ? (string)$row[$h] : '';
                $len = mb_strwidth($val);
                if ($len > ($widths[$h] ?? 0)) {
                    $widths[$h] = min($len, 50); // Cap width at 50 chars for cleanliness
                }
            }
        }

        $out = '';
        // Top border: ┌───┬───┐
        $out .= $this->color('┌' . implode('┬', array_map(fn($w) => str_repeat('─', $w + 2), $widths)) . "┐\n", 'cyan');

        // Header row: │ col1 │ col2 │
        $headerPadded = [];
        foreach ($headers as $h) {
            $w = $widths[$h];
            $headerPadded[] = ' ' . $this->padString((string)$h, $w) . ' ';
        }
        $out .= $this->color('│', 'cyan') . implode($this->color('│', 'cyan'), array_map(fn($t) => $this->color($t, 'bold'), $headerPadded)) . $this->color("│\n", 'cyan');

        // Header separator: ├───┼───┤
        $out .= $this->color('├' . implode('┼', array_map(fn($w) => str_repeat('─', $w + 2), $widths)) . "┤\n", 'cyan');

        // Data rows
        foreach ($normalizedRows as $row) {
            $rowPadded = [];
            foreach ($headers as $h) {
                $w = $widths[$h];
                $val = isset($row[$h]) ? (string)$row[$h] : '';
                $val = $this->truncateString($val, $w);
                $rowPadded[] = ' ' . $this->padString($val, $w) . ' ';
            }
            $out .= $this->color('│', 'cyan') . implode($this->color('│', 'cyan'), $rowPadded) . $this->color("│\n", 'cyan');
        }

        // Bottom border: └───┴───┘
        $out .= $this->color('└' . implode('┴', array_map(fn($w) => str_repeat('─', $w + 2), $widths)) . "┘\n", 'cyan');

        return $out;
    }

    /**
     * Render data as CSV
     */
    public function renderCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        if (!is_array(reset($rows))) {
            $rows = array_map(fn($k, $v) => ['key' => $k, 'value' => $v], array_keys($rows), $rows);
        }

        $allKeys = [];
        $flatRows = [];
        foreach ($rows as $item) {
            $flat = $this->flattenRow(is_array($item) ? $item : ['value' => $item]);
            $flatRows[] = $flat;
            foreach (array_keys($flat) as $k) {
                $allKeys[$k] = true;
            }
        }
        $headers = array_keys($allKeys);

        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $headers);
        foreach ($flatRows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $line[] = $row[$h] ?? '';
            }
            fputcsv($fp, $line);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv ?: '';
    }

    /**
     * Pretty print JSON
     */
    public function renderJson(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function flattenRow(array $row, string $prefix = ''): array
    {
        $result = [];
        foreach ($row as $key => $val) {
            $newKey = $prefix === '' ? (string)$key : "{$prefix}.{$key}";
            if (is_array($val)) {
                if ($this->isAssoc($val)) {
                    $result = array_merge($result, $this->flattenRow($val, $newKey));
                } else {
                    $result[$newKey] = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            } elseif (is_bool($val)) {
                $result[$newKey] = $val ? 'true' : 'false';
            } elseif ($val === null) {
                $result[$newKey] = 'null';
            } else {
                $result[$newKey] = (string)$val;
            }
        }
        return $result;
    }

    private function isAssoc(array $arr): bool
    {
        if ([] === $arr) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function padString(string $str, int $length): string
    {
        $currentLen = mb_strwidth($str);
        if ($currentLen >= $length) {
            return $str;
        }
        return $str . str_repeat(' ', $length - $currentLen);
    }

    private function truncateString(string $str, int $max): string
    {
        if (mb_strwidth($str) <= $max) {
            return $str;
        }
        return mb_substr($str, 0, $max - 3) . '...';
    }
}
