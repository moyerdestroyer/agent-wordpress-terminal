<?php

/**
 * Reads a tail slice of the WordPress / PHP error log.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns recent error log lines with noise filtering and size caps.
 */
final class ErrorLogReader {
    private const DEFAULT_MAX_LINES = 100;
    private const HARD_MAX_LINES = 200;

    /**
     * @var list<string>
     */
    private const NOISE_PATTERNS = [
        'Xdebug:',
        'xdebug.client_host',
        'Step Debug',
        'WP AI Assistant: debug logging enabled',
    ];

    /**
     * @return array{exists: bool, source: string|null, lines: list<string>, wp_debug: bool, wp_debug_log: bool}
     */
    public function read(int $max_lines = self::DEFAULT_MAX_LINES): array {
        $max_lines = max(1, min($max_lines, self::HARD_MAX_LINES));
        $wp_log = WP_CONTENT_DIR . '/debug.log';

        $candidates = array_filter([
            $wp_log,
            ini_get('error_log') ? (string) ini_get('error_log') : null,
        ]);

        $found_path = null;
        $found_label = null;

        foreach ($candidates as $path) {
            if (is_string($path) && file_exists($path) && is_readable($path)) {
                $found_path = $path;
                $found_label = $path === $wp_log ? 'wp-content/debug.log' : $path;
                break;
            }
        }

        if (null === $found_path) {
            return [
                'exists' => false,
                'source' => null,
                'lines' => [],
                'wp_debug' => defined('WP_DEBUG') && \WP_DEBUG,
                'wp_debug_log' => defined('WP_DEBUG_LOG') && \WP_DEBUG_LOG,
            ];
        }

        return [
            'exists' => true,
            'source' => $this->redact_path($found_label ?? $found_path),
            'lines' => $this->read_tail($found_path, $max_lines),
            'wp_debug' => defined('WP_DEBUG') && \WP_DEBUG,
            'wp_debug_log' => defined('WP_DEBUG_LOG') && \WP_DEBUG_LOG,
        ];
    }

    /**
     * @return list<string>
     */
    private function read_tail(string $path, int $max_lines): array {
        $file = new \SplFileObject($path);
        $file->seek(\PHP_INT_MAX);
        $total = $file->key();
        $start = max(0, $total - ($max_lines * 4));
        $lines = [];

        $file->seek($start);

        while (!$file->eof()) {
            $line = rtrim((string) $file->current());
            $file->next();

            if ('' === trim($line)) {
                continue;
            }

            if ($this->is_noise($line)) {
                continue;
            }

            $lines[] = $this->redact_path($line);
        }

        return array_slice($lines, -$max_lines);
    }

    private function is_noise(string $line): bool {
        foreach (self::NOISE_PATTERNS as $pattern) {
            if (str_contains($line, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function redact_path(string $text): string {
        if (defined('ABSPATH')) {
            return str_replace(ABSPATH, '[ABSPATH]/', $text);
        }

        return $text;
    }
}
