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
        return $this->extract_with_metadata($path)['text'];
    }

    /**
     * @return array{text: string, method: string, page_count: int, warning: string}
     */
    public function extract_with_metadata(string $path): array {
        if (!is_readable($path)) {
            return $this->result('', 'unavailable', __('The PDF file is not readable.', 'agent-wordpress-terminal'));
        }

        $from_cli = $this->via_pdftotext($path);

        if ($this->has_meaningful_text($from_cli)) {
            return $this->result($from_cli, 'pdftotext');
        }

        $from_php = $this->via_php_parser($path);

        if ($this->has_meaningful_text($from_php)) {
            return $this->result($from_php, 'pdfparser');
        }

        $from_ocr = $this->via_ocr($path);

        if ($this->has_meaningful_text($from_ocr)) {
            return $this->result($from_ocr, 'ocr');
        }

        $from_raw = $this->via_raw_scan($path);

        if ($this->has_meaningful_text($from_raw)) {
            return $this->result(
                $from_raw,
                'raw_scan',
                __(
                    'PDF text was recovered with a best-effort fallback; verify formatting against the source.',
                    'agent-wordpress-terminal',
                ),
            );
        }

        return $this->result(
            '',
            'unavailable',
            __(
                'No readable text layer was found. Install Tesseract OCR (with pdftoppm) for scanned PDFs.',
                'agent-wordpress-terminal',
            ),
        );
    }

    private function via_pdftotext(string $path): string {
        if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
            return '';
        }

        $binary = $this->find_pdftotext();

        if (null === $binary) {
            return '';
        }

        // Preserve form-feed page boundaries so callers can paginate and cite pages.
        $command = sprintf('%s -layout -q %s - 2>/dev/null', escapeshellcmd($binary), escapeshellarg($path));
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

    private function via_php_parser(string $path): string {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            return '';
        }

        $bytes = filesize($path);

        if (false === $bytes || $bytes <= 0 || $bytes > 25_000_000) {
            return '';
        }

        try {
            $document = new \Smalot\PdfParser\Parser()->parseFile($path);
            $pages = [];

            foreach (array_slice($document->getPages(), 0, 250) as $page) {
                $pages[] = trim($page->getText());
            }

            return implode("\f", $pages);
        } catch (\Throwable) {
            return '';
        }
    }

    private function via_ocr(string $path): string {
        if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
            return '';
        }

        $renderer = $this->find_binary(['/usr/bin/pdftoppm', '/usr/local/bin/pdftoppm'], 'pdftoppm');
        $ocr = $this->find_binary(['/usr/bin/tesseract', '/usr/local/bin/tesseract'], 'tesseract');

        if (null === $renderer || null === $ocr) {
            return '';
        }

        $base = tempnam(sys_get_temp_dir(), 'awpt-pdf-');

        if (!is_string($base)) {
            return '';
        }

        unlink($base);

        if (!mkdir($base, 0700)) {
            return '';
        }

        $prefix = $base . '/page';
        $render = sprintf(
            '%s -f 1 -l 20 -r 150 -png -q %s %s 2>/dev/null',
            escapeshellcmd($renderer),
            escapeshellarg($path),
            escapeshellarg($prefix),
        );
        shell_exec($render);
        $pages = glob($prefix . '-*.png');
        $text = [];
        /** @var mixed $filtered_languages */
        $filtered_languages = apply_filters('awpt_pdf_ocr_languages', 'eng', $path);
        $languages = is_string($filtered_languages) ? preg_replace('/[^a-z0-9_+.-]/i', '', $filtered_languages) : 'eng';
        $languages = is_string($languages) && '' !== $languages ? $languages : 'eng';

        foreach (is_array($pages) ? $pages : [] as $page) {
            $command = sprintf(
                '%s %s stdout -l %s --psm 3 2>/dev/null',
                escapeshellcmd($ocr),
                escapeshellarg($page),
                escapeshellarg($languages),
            );
            $output = shell_exec($command);

            if (is_string($output) && '' !== trim($output)) {
                $text[] = trim($output);
            }

            unlink($page);
        }

        rmdir($base);

        return implode("\f", $text);
    }

    /**
     * @param list<string> $paths
     */
    private function find_binary(array $paths, string $command): ?string {
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        $found = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');

        return is_string($found) && '' !== trim($found) ? trim($found) : null;
    }

    private function has_meaningful_text(string $text): bool {
        $plain = preg_replace('/[^\p{L}\p{N}]+/u', '', $text);

        return is_string($plain) && mb_strlen($plain, 'UTF-8') >= 40;
    }

    /**
     * @return array{text: string, method: string, page_count: int, warning: string}
     */
    private function result(string $text, string $method, string $warning = ''): array {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        return [
            'text' => mb_substr($text, 0, 500_000, 'UTF-8'),
            'method' => $method,
            'page_count' => '' === $text ? 0 : max(1, substr_count($text, "\f") + 1),
            'warning' => $warning,
        ];
    }
}
