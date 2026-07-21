<?php

/**
 * Attributes PHP/JS error text to plugins, themes, or core paths.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Parses stack traces and log lines for WordPress component suspects.
 */
final class ErrorPathAttributor {
    /**
     * @return array{
     *     suspects: list<array{kind: string, slug: string, file: string, confidence: string}>,
     *     error_type: string|null,
     *     evidence: list<string>
     * }
     */
    public function from_text(string $text): array {
        $text = trim($text);

        if ('' === $text) {
            return [
                'suspects' => [],
                'error_type' => null,
                'evidence' => [],
            ];
        }

        $evidence = $this->extract_evidence_lines($text);
        $suspects = $this->extract_suspects($text);

        return [
            'suspects' => $suspects,
            'error_type' => new ErrorTypeDetector()->detect($text),
            'evidence' => $evidence,
        ];
    }

    /**
     * @return list<string>
     */
    private function extract_evidence_lines(string $text): array {
        $lines = preg_split('/\r\n|\r|\n/', $text);

        if (!is_array($lines)) {
            $lines = [];
        }
        $evidence = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            if (
                preg_match('/\b(fatal|error|warning|notice|exception|uncaught)\b/i', $line)
                || str_contains($line, 'Stack trace')
                || str_contains($line, 'wp-content/')
            ) {
                $evidence[] = $this->redact_path($line);

                if (count($evidence) >= 8) {
                    break;
                }
            }
        }

        if ([] === $evidence) {
            $evidence[] = $this->redact_path(mb_substr($text, 0, 500));
        }

        return $evidence;
    }

    /**
     * @return list<array{kind: string, slug: string, file: string, confidence: string}>
     */
    private function extract_suspects(string $text): array {
        $seen = [];
        $suspects = [];

        $patterns = [
            'plugin' => '~(?:/|\\\)wp-content(?:/|\\\)plugins(?:/|\\\)([a-zA-Z0-9_-]+)(?:/|\\\)~',
            'theme' => '~(?:/|\\\)wp-content(?:/|\\\)themes(?:/|\\\)([a-zA-Z0-9_-]+)(?:/|\\\)~',
            'mu_plugin' => '~(?:/|\\\)wp-content(?:/|\\\)mu-plugins(?:/|\\\)([^/\\\s]+)~',
        ];

        foreach ($patterns as $kind => $pattern) {
            $matches = [];

            if (!preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $slug = sanitize_key($match[1] ?? '');

                if ('' === $slug || array_key_exists($kind . ':' . $slug, $seen)) {
                    continue;
                }

                $seen[$kind . ':' . $slug] = true;
                $file = $this->redact_path($match[0] ?? '');

                $suspects[] = [
                    'kind' => $kind,
                    'slug' => $slug,
                    'file' => $file,
                    'confidence' => 'plugin' === $kind || 'theme' === $kind ? 'high' : 'medium',
                ];
            }
        }

        return $suspects;
    }

    private function redact_path(string $text): string {
        if (defined('ABSPATH')) {
            return str_replace(ABSPATH, '[ABSPATH]/', $text);
        }

        return $text;
    }
}
