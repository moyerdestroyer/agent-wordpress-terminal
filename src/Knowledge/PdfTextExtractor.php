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
 *
 * Pathological PDFs can hang or fatal inside smalot/pdfparser (FlateDecode loops)
 * and blow PHP max_execution_time. The PHP parser path therefore runs in an
 * isolated subprocess with a hard wall-clock timeout whenever possible.
 */
final class PdfTextExtractor {
    private const PHP_PARSER_MAX_BYTES = 15_000_000;
    private const PHP_PARSER_TIMEOUT_SECONDS = 8;
    private const PHP_PARSER_MAX_PAGES = 100;
    private const CLI_TIMEOUT_SECONDS = 12;

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
        $output = $this->shell_with_timeout($command, self::CLI_TIMEOUT_SECONDS);

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

        if (false === $bytes || $bytes <= 0 || $bytes > self::PHP_PARSER_MAX_BYTES) {
            return '';
        }

        $isolated = $this->via_php_parser_isolated($path);

        if (null !== $isolated) {
            return $isolated;
        }

        // Refuse in-process parsing under a finite max_execution_time — smalot
        // FlateDecode loops can fatal the HTTP worker past any try/catch.
        if ((int) ini_get('max_execution_time') > 0) {
            return '';
        }

        return $this->via_php_parser_in_process($path);
    }

    /**
     * Run smalot in a child PHP process killed by `timeout` so FlateDecode loops
     * cannot fatal the Apache/FPM worker.
     *
     * @return string|null Extracted text, empty string on soft failure, or null when isolation is unavailable.
     */
    private function via_php_parser_isolated(string $path): ?string {
        if (!function_exists('shell_exec') || !is_callable('shell_exec') || !is_executable('/usr/bin/timeout')) {
            return null;
        }

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $php = $this->php_cli_binary();

        if (!is_readable($autoload) || null === $php) {
            return null;
        }

        $script = tempnam(sys_get_temp_dir(), 'awpt-pdf-parser-');

        if (!is_string($script)) {
            return null;
        }

        $script_file = $script . '.php';

        if (!rename($script, $script_file)) {
            $this->delete_temp($script);

            return null;
        }

        $max_pages = self::PHP_PARSER_MAX_PAGES;
        $runner = implode("\n", [
            '<?php',
            'declare(strict_types=1);',
            '$autoload = $argv[1] ?? \'\';',
            '$path = $argv[2] ?? \'\';',
            '$max_pages = max(1, (int) ($argv[3] ?? 100));',
            'if (!is_readable($autoload) || !is_readable($path)) { exit(2); }',
            'require $autoload;',
            'try {',
            '    $document = (new Smalot\\PdfParser\\Parser())->parseFile($path);',
            '    $pages = [];',
            '    foreach (array_slice($document->getPages(), 0, $max_pages) as $page) {',
            '        $pages[] = trim($page->getText());',
            '    }',
            '    echo implode("\\f", $pages);',
            '} catch (Throwable) {',
            '    exit(3);',
            '}',
            '',
        ]);

        if (false === file_put_contents($script_file, $runner)) {
            $this->delete_temp($script_file);

            return null;
        }

        $command = sprintf(
            '/usr/bin/timeout %ds %s %s %s %s %d 2>/dev/null',
            self::PHP_PARSER_TIMEOUT_SECONDS,
            escapeshellarg($php),
            escapeshellarg($script_file),
            escapeshellarg($autoload),
            escapeshellarg($path),
            $max_pages,
        );
        $stdout = shell_exec($command);
        $this->delete_temp($script_file);

        return is_string($stdout) ? trim($stdout) : '';
    }

    private function via_php_parser_in_process(string $path): string {
        try {
            $document = new \Smalot\PdfParser\Parser()->parseFile($path);
            $pages = [];

            foreach (array_slice($document->getPages(), 0, self::PHP_PARSER_MAX_PAGES) as $page) {
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

        if (!$this->has_request_budget(self::CLI_TIMEOUT_SECONDS + 2)) {
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

        if (!mkdir($base, 0o700)) {
            return '';
        }

        $prefix = $base . '/page';
        $render = sprintf(
            '%s -f 1 -l 20 -r 150 -png -q %s %s 2>/dev/null',
            escapeshellcmd($renderer),
            escapeshellarg($path),
            escapeshellarg($prefix),
        );
        $this->shell_with_timeout($render, self::CLI_TIMEOUT_SECONDS);
        $pages = glob($prefix . '-*.png');
        $text = [];
        /** @var mixed $filtered_languages */
        $filtered_languages = apply_filters('awpt_pdf_ocr_languages', 'eng', $path);
        $languages = is_string($filtered_languages) ? preg_replace('/[^a-z0-9_+.-]/i', '', $filtered_languages) : 'eng';
        $languages = is_string($languages) && '' !== $languages ? $languages : 'eng';

        foreach (is_array($pages) ? $pages : [] as $page) {
            if (!$this->has_request_budget(3)) {
                break;
            }

            $command = sprintf(
                '%s %s stdout -l %s --psm 3 2>/dev/null',
                escapeshellcmd($ocr),
                escapeshellarg($page),
                escapeshellarg($languages),
            );
            $output = $this->shell_with_timeout($command, 8);

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

    private function shell_with_timeout(string $command, int $seconds): ?string {
        $seconds = max(1, $seconds);

        if (is_executable('/usr/bin/timeout')) {
            $command = sprintf('/usr/bin/timeout %ds %s', $seconds, $command);
        }

        $output = shell_exec($command);

        return is_string($output) ? $output : null;
    }

    /**
     * Resolve a CLI PHP binary. Under mod_php, PHP_BINARY is often the Apache
     * SAPI binary and cannot run scripts as CLI.
     */
    private function php_cli_binary(): ?string {
        /** @var list<string> $candidates */
        $candidates = array_values(array_filter(
            [PHP_BINARY, '/usr/local/bin/php', '/usr/bin/php', 'php'],
            static fn(string $candidate): bool => '' !== $candidate,
        ));

        foreach ($candidates as $candidate) {
            if ('php' === $candidate) {
                $which = shell_exec('command -v php 2>/dev/null');

                if (is_string($which) && '' !== trim($which) && $this->looks_like_php_cli(trim($which))) {
                    return trim($which);
                }

                continue;
            }

            if (is_executable($candidate) && $this->looks_like_php_cli($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function looks_like_php_cli(string $binary): bool {
        $base = strtolower(basename($binary));

        return str_starts_with($base, 'php') && !str_contains($base, 'apache') && !str_contains($base, 'fpm');
    }

    private function delete_temp(string $path): void {
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function has_request_budget(int $needed_seconds): bool {
        $max = (int) ini_get('max_execution_time');

        if ($max <= 0) {
            return true;
        }

        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? $_SERVER['REQUEST_TIME'] ?? microtime(true));
        $remaining = $max - (microtime(true) - $started);

        return $remaining >= $needed_seconds;
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
