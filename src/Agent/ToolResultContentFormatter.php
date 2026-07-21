<?php

/**
 * Content-oriented tool transcript formatting.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Formats read and site-health tool outputs; delegates list/search/knowledge.
 */
final class ToolResultContentFormatter {
    private ToolResultListFormatter $lists;

    public function __construct(?ToolResultListFormatter $lists = null) {
        $this->lists = $lists ?? new ToolResultListFormatter();
    }

    /**
     * @param array<array-key, mixed> $output
     */
    public function format(string $tool, array $output): string {
        $listed = $this->lists->format($tool, $output);
        if ('' !== $listed) {
            return $listed;
        }

        if ('awpt/knowledge-auto-retrieval' === $tool) {
            return $this->format_knowledge($output);
        }

        return match ($tool) {
            'awpt/read-content' => $this->format_read_content($output),
            'awpt/read-block-tree' => $this->format_read_block_tree($output),
            'awpt/read-pattern' => $this->format_read_pattern($output),
            'awpt/list-patterns' => $this->format_patterns($output),
            'awpt/read-site-health' => $this->format_site_health($output),
            default => '',
        };
    }

    /** @param array<array-key, mixed> $output */
    private function format_read_pattern(array $output): string {
        return sprintf(
            /* translators: 1: pattern name, 2: title, 3: block count. */
            __('Read pattern %1$s — %2$s (%3$d top-level blocks).', 'agent-wordpress-terminal'),
            (string) ($output['name'] ?? ''),
            (string) ($output['title'] ?? ''),
            (int) ($output['block_count'] ?? 0),
        );
    }

    /** @param array<array-key, mixed> $output */
    private function format_patterns(array $output): string {
        $patterns = $this->list_items($output['patterns'] ?? []);
        $search = trim((string) ($output['search'] ?? ''));
        $count = (int) ($output['count'] ?? count($patterns));
        $lines = [
            '' !== $search
                ? sprintf(
                    /* translators: 1: match count, 2: pattern metadata search. */
                    __('Found %1$d patterns matching metadata search “%2$s”.', 'agent-wordpress-terminal'),
                    $count,
                    $search,
                )
                : sprintf(
                    /* translators: %d: registered pattern count. */
                    __('Found %d registered patterns.', 'agent-wordpress-terminal'),
                    $count,
                ),
        ];

        if ('' !== (string) ($output['search_note'] ?? '')) {
            $lines[] = (string) $output['search_note'];
        }

        foreach (array_slice($patterns, 0, 12) as $pattern) {
            $lines[] = sprintf('- %s — %s', (string) ($pattern['name'] ?? ''), (string) ($pattern['title'] ?? ''));
        }

        $suggestions = $this->list_items($output['suggested_patterns'] ?? []);

        if ([] !== $suggestions) {
            $lines[] = sprintf(
                /* translators: %d: total number of patterns available without the metadata filter. */
                __('There are %d total patterns available. Possible layout patterns:', 'agent-wordpress-terminal'),
                (int) ($output['available_count'] ?? count($suggestions)),
            );

            foreach (array_slice($suggestions, 0, 8) as $pattern) {
                $lines[] = sprintf('- %s — %s', (string) ($pattern['name'] ?? ''), (string) ($pattern['title'] ?? ''));
            }
        }

        if (count($patterns) > 12 || (int) ($output['count'] ?? 0) > 12) {
            $lines[] = __(
                'Additional patterns were kept in tool context but omitted from this transcript.',
                'agent-wordpress-terminal',
            );
        }

        return implode("\n", $lines);
    }

    private function format_read_content(array $output): string {
        $plain = trim((string) ($output['plain_text'] ?? ''));
        $lines = [sprintf(
            /* translators: 1: post ID, 2: title, 3: post type, 4: status */
            __('Read #%1$d %2$s (%3$s, %4$s).', 'agent-wordpress-terminal'),
            (int) ($output['id'] ?? 0),
            (string) ($output['title'] ?? ''),
            (string) ($output['type'] ?? ''),
            (string) ($output['status'] ?? ''),
        )];

        if ('' !== $plain) {
            $lines[] = sprintf(
                /* translators: %s: content excerpt */
                __('Excerpt: %s', 'agent-wordpress-terminal'),
                $this->excerpt($plain, 360),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $output
     */

    private function format_read_block_tree(array $output): string {
        $blocks = $this->flatten_blocks($this->list_items($output['blocks'] ?? []));
        $count = (int) ($output['count'] ?? count($blocks));

        if ([] === $blocks) {
            return __('No Gutenberg blocks were found in this content.', 'agent-wordpress-terminal');
        }

        $lines = [sprintf(
            /* translators: %d: block count */
            __('Read block tree with %d blocks:', 'agent-wordpress-terminal'),
            $count,
        )];

        foreach (array_slice($blocks, 0, 12) as $block) {
            $excerpt = trim((string) ($block['text_excerpt'] ?? ''));
            $lines[] = sprintf(
                /* translators: 1: block path, 2: block name, 3: block text excerpt */
                __('- %1$s %2$s %3$s', 'agent-wordpress-terminal'),
                (string) ($block['path'] ?? ''),
                (string) ($block['name'] ?? ''),
                '' !== $excerpt ? '- ' . $this->excerpt($excerpt, 90) : '',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<array-key, mixed>> $blocks
     * @return list<array<array-key, mixed>>
     */

    private function flatten_blocks(array $blocks): array {
        $flat = [];

        foreach ($blocks as $block) {
            $flat[] = $block;
            $inner = $this->list_items($block['inner'] ?? []);

            if ([] !== $inner) {
                $flat = array_merge($flat, $this->flatten_blocks($inner));
            }
        }

        return $flat;
    }

    /**
     * @param array<array-key, mixed> $output
     */

    private function format_site_health(array $output): string {
        $lines = [__('Site Health', 'agent-wordpress-terminal')];
        $environment = is_array($output['environment'] ?? null) ? $output['environment'] : [];

        if ([] !== $environment) {
            $lines[] = sprintf(
                'PHP %s · memory %s · WP %s',
                (string) ($environment['php_version'] ?? '?'),
                (string) ($environment['php_memory_limit'] ?? '?'),
                (string) ($environment['wp_version'] ?? '?'),
            );
        }

        $tests = is_array($output['tests'] ?? null) ? $output['tests'] : [];

        foreach ($tests as $test) {
            if (!is_array($test)) {
                continue;
            }

            $lines[] = sprintf(
                '- %s: %s',
                (string) ($test['label'] ?? $test['slug'] ?? 'test'),
                (string) ($test['status'] ?? 'unknown'),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array<array-key, mixed>>
     */

    /**
     * @param array<array-key, mixed> $output
     */
    private function format_knowledge(array $output): string {
        $results = $this->list_items($output['results'] ?? []);

        if ([] === $results) {
            return __('No automatic Knowledge excerpts were added to context.', 'agent-wordpress-terminal');
        }

        $lines = [__('Automatic Knowledge context added.', 'agent-wordpress-terminal')];
        // fix string - use original
        $lines = [__('Automatic Knowledge context added:', 'agent-wordpress-terminal')];

        foreach (array_slice($results, 0, 5) as $result) {
            $lines[] = sprintf(
                /* translators: 1: source label, 2: source kind */
                __('- %1$s (%2$s)', 'agent-wordpress-terminal'),
                (string) ($result['label'] ?? ''),
                (string) ($result['source_kind'] ?? ''),
            );
        }

        return implode("\n", $lines);
    }

    private function list_items(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }

    private function excerpt(string $text, int $limit): string {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit - 3, 'UTF-8')) . '...';
    }
}
