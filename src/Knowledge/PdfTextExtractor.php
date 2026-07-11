<?php

/**
 * Lightweight PDF text extraction for Knowledge indexing.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Extracts plain text from PDFs without a hard Composer dependency.
 */
final class PdfTextExtractor {
    public function extract(string $path): string {
        if (!is_readable($path)) {
            return '';
        }

        $from_cli = $this->via_pdftotext($path);

        if ('' !== trim($from_cli)) {
            return $from_cli;
        }

        return $this->via_raw_scan($path);
    }

    private function via_pdftotext(string $path): string {
        if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
            return '';
        }

        $binary = $this->find_pdftotext();

        if (null === $binary) {
            return '';
        }

        $command = sprintf('%s -nopgbrk -layout -q %s - 2>/dev/null', escapeshellcmd($binary), escapeshellarg($path));
        $output = shell_exec($command);

        return is_string($output) ? trim($output) : '';
    }

    private function find_pdftotext(): ?string {
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', 'pdftotext'] as $candidate) {
            if ('pdftotext' === $candidate) {
                $which = shell_exec('command -v pdftotext 2>/dev/null');

                if (is_string($which) && '' !== trim($which)) {
                    return trim($which);
                }

                continue;
            }

            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function via_raw_scan(string $path): string {
        $raw = file_get_contents($path);

        if (!is_string($raw) || !str_starts_with($raw, '%PDF')) {
            return '';
        }

        // Best-effort: pull printable runs from parentheses and plain text streams.
        $parts = [];
        $matches = [];

        if (preg_match_all('/\((?:\\\\.|[^\\\\)]){3,}\)/s', $raw, $matches) > 0 && is_array($matches[0] ?? null)) {
            foreach ($matches[0] as $match) {
                $text = substr($match, 1, -1);
                $text = stripcslashes($text);
                $text = preg_replace('/[^\P{C}\n\t]+/u', ' ', $text);
                $text = trim(is_string($text) ? $text : '');

                if (mb_strlen($text, 'UTF-8') >= 3) {
                    $parts[] = $text;
                }
            }
        }

        $joined = trim(implode(' ', $parts));

        return mb_substr($joined, 0, 200_000, 'UTF-8');
    }
}
