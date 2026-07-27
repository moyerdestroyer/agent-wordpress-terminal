<?php

/**
 * Deterministic, structure-aware text chunking for Knowledge indexing.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Produces model-independent chunks with stable section metadata.
 */
final class KnowledgeTextChunker {
    public const VERSION = '3';

    private const DEFAULT_TARGET_TOKENS = 600;
    private const DEFAULT_MAX_TOKENS = 900;
    private const DEFAULT_OVERLAP_TOKENS = 80;

    private int $target_tokens;
    private int $max_tokens;
    private int $overlap_tokens;

    /**
     * The legacy constructor accepted character limits. Small values are retained for
     * bootstrap-free tests; production defaults use the token-oriented contract.
     */
    public function __construct(int $chunk_size = 0, int $chunk_overlap = 0) {
        $this->target_tokens = $chunk_size > 0 ? max(8, (int) ceil($chunk_size / 4)) : self::DEFAULT_TARGET_TOKENS;
        $this->max_tokens = $chunk_size > 0 ? max(8, (int) ceil($chunk_size / 4)) : self::DEFAULT_MAX_TOKENS;
        $this->overlap_tokens = $chunk_size > 0 ? max(0, (int) ceil($chunk_overlap / 4)) : self::DEFAULT_OVERLAP_TOKENS;
        $this->overlap_tokens = min($this->overlap_tokens, $this->max_tokens - 1);
    }

    /**
     * Backward-compatible list of chunk texts.
     *
     * @return list<string>
     */
    public function chunk(string $content): array {
        return array_values(array_map(
            static fn(array $chunk): string => (string) ($chunk['text'] ?? ''),
            $this->chunks($content),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function chunks(string $content, string $content_type = 'prose'): array {
        $content = $this->normalize($content);

        if ('' === $content) {
            return [];
        }

        $sections = match ($content_type) {
            'json' => $this->json_sections($content),
            'csv' => $this->csv_sections($content),
            'scss', 'css' => $this->style_sections($content),
            'pdf' => $this->pdf_sections($content),
            default => $this->prose_sections($content),
        };

        return $this->pack_sections($sections);
    }

    /**
     * Model-independent, deliberately conservative token estimate.
     */
    public static function estimate_tokens(string $text): int {
        $characters = mb_strlen($text, 'UTF-8');
        $unused = [];
        $words = preg_match_all('/[\p{L}\p{N}_]+|[^\s\p{L}\p{N}_]/u', $text, $unused);
        $lexical = false === $words ? 0 : $words;

        return max(1, (int) ceil(max($characters / 4, $lexical * 1.15)));
    }

    private function normalize(string $content): string {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/[ \t]+\n/u', "\n", $content);
        $content = preg_replace('/\n{4,}/u', "\n\n\n", is_string($content) ? $content : '');

        return trim(is_string($content) ? $content : '');
    }

    /**
     * @return list<array{text: string, heading_path: string, section_key: string, page: int}>
     */
    private function prose_sections(string $content): array {
        $lines = explode("\n", $content);
        /** @var list<string> $headings */
        $headings = [];
        $current = [];
        $current_path = '';
        $sections = [];

        $flush = static function () use (&$current, &$sections, &$current_path): void {
            $text = trim(implode("\n", $current));

            if ('' !== $text) {
                $sections[] = [
                    'text' => $text,
                    'heading_path' => $current_path,
                    'section_key' => '' !== $current_path ? $current_path : 'document',
                    'page' => 0,
                ];
            }

            $current = [];
        };

        foreach ($lines as $line) {
            $matches = [];

            if (preg_match('/^(#{1,6})\s+(.+)$/u', trim($line), $matches)) {
                $flush();
                $level = strlen($matches[1]);
                $headings = array_slice($headings, 0, $level - 1);
                $headings[$level - 1] = trim($matches[2]);
                $current_path = implode(
                    ' > ',
                    array_values(array_filter($headings, static fn(string $heading): bool => '' !== $heading)),
                );
                $current[] = trim($line);
                continue;
            }

            $current[] = $line;
        }

        $flush();

        return (
            [] !== $sections
                ? $sections
                : [[
                    'text' => $content,
                    'heading_path' => '',
                    'section_key' => 'document',
                    'page' => 0,
                ]]
        );
    }

    /**
     * @return list<array{text: string, heading_path: string, section_key: string, page: int}>
     */
    private function pdf_sections(string $content): array {
        $pages = preg_split('/\f/u', $content);

        if (!is_array($pages) || count($pages) <= 1) {
            return $this->prose_sections($content);
        }

        $sections = [];

        foreach ($pages as $index => $page) {
            $page = trim($page);

            if ('' === $page) {
                continue;
            }

            $page_number = $index + 1;
            $sections[] = [
                'text' => $page,
                'heading_path' => 'Page ' . $page_number,
                'section_key' => 'page:' . $page_number,
                'page' => $page_number,
            ];
        }

        return $sections;
    }

    /**
     * @return list<array{text: string, heading_path: string, section_key: string, page: int}>
     */
    private function json_sections(string $content): array {
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return $this->prose_sections($content);
        }

        /** @var array<string, list<string>> $groups */
        $groups = [];
        $walk = function (mixed $value, string $path) use (&$walk, &$groups): void {
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    $child_path = '' === $path ? (string) $key : $path . '.' . $key;
                    $walk($child, $child_path);
                }

                return;
            }

            $group = $this->json_group_path($path);
            $groups[$group] ??= [];
            $groups[$group][] = $path . ': ' . $this->scalar_to_string($value);
        };
        $walk($decoded, '');

        $sections = [];

        foreach ($groups as $path => $lines) {
            $sections[] = [
                'text' => implode("\n", $lines),
                'heading_path' => $path,
                'section_key' => $path,
                'page' => 0,
            ];
        }

        return $sections;
    }

    private function json_group_path(string $path): string {
        $segments = explode('.', $path);
        array_pop($segments);
        $numeric_position = null;

        foreach ($segments as $index => $segment) {
            if (ctype_digit($segment)) {
                $numeric_position = $index;
                break;
            }
        }

        if (null !== $numeric_position) {
            $segments = array_slice($segments, 0, $numeric_position);
        } elseif (count($segments) > 4) {
            $segments = array_slice($segments, 0, 4);
        }

        return [] !== $segments ? implode('.', $segments) : 'root';
    }

    private function scalar_to_string(mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (null === $value) {
            return 'null';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<array{text: string, heading_path: string, section_key: string, page: int}>
     */
    private function csv_sections(string $content): array {
        $lines = array_values(array_filter(
            explode("\n", $content),
            static fn(string $line): bool => '' !== trim($line),
        ));

        if (count($lines) < 2) {
            return $this->prose_sections($content);
        }

        $header = array_shift($lines);
        $sections = [];

        foreach (array_chunk($lines, 40) as $index => $rows) {
            $sections[] = [
                'text' => $header . "\n" . implode("\n", $rows),
                'heading_path' => 'Rows ' . (($index * 40) + 1) . '–' . (($index * 40) + count($rows)),
                'section_key' => 'rows:' . $index,
                'page' => 0,
            ];
        }

        return $sections;
    }

    /**
     * @return list<array{text: string, heading_path: string, section_key: string, page: int}>
     */
    private function style_sections(string $content): array {
        $sections = [];
        $start = 0;
        $depth = 0;
        $length = strlen($content);

        for ($position = 0; $position < $length; ++$position) {
            if ('{' === $content[$position]) {
                ++$depth;
            } elseif ('}' === $content[$position]) {
                --$depth;

                if (0 === $depth) {
                    $rule = trim(substr($content, $start, $position - $start + 1));
                    $start = $position + 1;

                    if ('' === $rule) {
                        continue;
                    }

                    $selector = trim((string) strstr($rule, '{', true));
                    $sections[] = [
                        'text' => $rule,
                        'heading_path' => mb_substr($selector, 0, 160, 'UTF-8'),
                        'section_key' => 'rule:' . hash('sha256', $selector),
                        'page' => 0,
                    ];
                }
            }
        }

        $tail = trim(substr($content, $start));

        if ('' !== $tail) {
            $sections[] = [
                'text' => $tail,
                'heading_path' => '',
                'section_key' => 'style-tail',
                'page' => 0,
            ];
        }

        return [] !== $sections ? $sections : $this->prose_sections($content);
    }

    /**
     * @param list<array{text: string, heading_path: string, section_key: string, page: int}> $sections
     * @return list<array<string, mixed>>
     */
    private function pack_sections(array $sections): array {
        $chunks = [];

        foreach ($sections as $section) {
            foreach ($this->split_section($section['text']) as $ordinal => $piece) {
                $unused = [];
                $words = preg_match_all('/[\p{L}\p{N}_]+/u', $piece, $unused);
                $page = (int) $section['page'];
                $chunks[] = [
                    'text' => $piece,
                    'heading_path' => $section['heading_path'],
                    'section_key' => $section['section_key'],
                    'section_ordinal' => $ordinal,
                    'char_count' => mb_strlen($piece, 'UTF-8'),
                    'word_count' => false === $words ? 0 : $words,
                    'token_estimate' => self::estimate_tokens($piece),
                    'page_start' => $page,
                    'page_end' => $page,
                ];
            }
        }

        return $this->combine_small_chunks($chunks);
    }

    /**
     * @return list<string>
     */
    private function split_section(string $text): array {
        if (self::estimate_tokens($text) <= $this->max_tokens) {
            return [trim($text)];
        }

        $units = preg_split('/(?<=[.!?])\s+|\n{2,}/u', trim($text));
        $units = is_array($units) ? array_values(array_filter(array_map('trim', $units))) : [trim($text)];
        $pieces = [];
        $current = '';

        foreach ($units as $unit) {
            if (self::estimate_tokens($unit) > $this->max_tokens) {
                if ('' !== $current) {
                    $pieces[] = $current;
                    $current = '';
                }

                array_push($pieces, ...$this->word_windows($unit));
                continue;
            }

            $candidate = '' === $current ? $unit : $current . ' ' . $unit;

            if (self::estimate_tokens($candidate) <= $this->max_tokens) {
                $current = $candidate;
                continue;
            }

            $pieces[] = $current;
            $current = $this->overlap_tail($current) . ' ' . $unit;
            $current = trim($current);
        }

        if ('' !== $current) {
            $pieces[] = $current;
        }

        return array_values(array_filter(array_map('trim', $pieces)));
    }

    /**
     * @return list<string>
     */
    private function word_windows(string $text): array {
        $words = preg_split('/\s+/u', trim($text));
        $words = is_array($words) ? array_values(array_filter($words)) : [];

        if ([] === $words) {
            return [];
        }

        $pieces = [];
        $current = [];

        foreach ($words as $word) {
            $candidate = implode(' ', [...$current, $word]);

            if ([] !== $current && self::estimate_tokens($candidate) > $this->max_tokens) {
                $pieces[] = implode(' ', $current);
                $overlap = $this->tail_words($current, $this->overlap_tokens);
                $current = [...$overlap, $word];
            } else {
                $current[] = $word;
            }
        }

        if ([] !== $current) {
            $pieces[] = implode(' ', $current);
        }

        return $pieces;
    }

    private function overlap_tail(string $text): string {
        $words = preg_split('/\s+/u', trim($text));

        return is_array($words) ? implode(' ', $this->tail_words($words, $this->overlap_tokens)) : '';
    }

    /**
     * @param list<string> $words
     * @return list<string>
     */
    private function tail_words(array $words, int $token_budget): array {
        /** @var list<string> $tail */
        $tail = [];

        for ($position = count($words) - 1; $position >= 0; --$position) {
            $word = $words[$position] ?? '';

            if ('' === $word) {
                continue;
            }

            array_unshift($tail, $word);

            if (self::estimate_tokens(implode(' ', $tail)) >= $token_budget) {
                break;
            }
        }

        return $tail;
    }

    /**
     * Combine adjacent tiny paragraphs only when they share the same section key.
     *
     * @param list<array<string, mixed>> $chunks
     * @return list<array<string, mixed>>
     */
    private function combine_small_chunks(array $chunks): array {
        /** @var list<array<string, mixed>> $combined */
        $combined = [];

        foreach ($chunks as $chunk) {
            $last_index = count($combined) - 1;

            if (
                $last_index >= 0
                && $this->section_group(
                    (string) ($combined[$last_index]['section_key'] ?? ''),
                ) === $this->section_group((string) ($chunk['section_key'] ?? ''))
                && (int) $combined[$last_index]['token_estimate'] < $this->target_tokens
                && self::estimate_tokens(
                    (string) ($combined[$last_index]['text'] ?? '') . "\n\n" . (string) ($chunk['text'] ?? ''),
                ) <= $this->max_tokens
            ) {
                $text = (string) ($combined[$last_index]['text'] ?? '') . "\n\n" . (string) ($chunk['text'] ?? '');
                $combined[$last_index]['text'] = $text;
                $combined[$last_index]['char_count'] = mb_strlen($text, 'UTF-8');
                $combined[$last_index]['word_count'] =
                    (int) ($combined[$last_index]['word_count'] ?? 0) + (int) ($chunk['word_count'] ?? 0);
                $combined[$last_index]['token_estimate'] = self::estimate_tokens($text);
                $previous_heading = (string) ($combined[$last_index]['heading_path'] ?? '');
                $next_heading = (string) ($chunk['heading_path'] ?? '');

                if ('' !== $next_heading && !str_contains($previous_heading, $next_heading)) {
                    $combined[$last_index]['heading_path'] = '' !== $previous_heading
                        ? $previous_heading . ' | ' . $next_heading
                        : $next_heading;
                }

                $combined[$last_index]['page_end'] = max(
                    (int) $combined[$last_index]['page_end'],
                    (int) $chunk['page_end'],
                );
                continue;
            }

            $combined[] = $chunk;
        }

        return array_values($combined);
    }

    private function section_group(string $section_key): string {
        if (str_starts_with($section_key, 'page:') || str_starts_with($section_key, 'rule:')) {
            return $section_key;
        }

        $parts = preg_split('/\s+>\s+|\./u', $section_key);

        if (!is_array($parts) || [] === $parts || '' === $parts[0]) {
            return $section_key;
        }

        return $parts[0];
    }
}
