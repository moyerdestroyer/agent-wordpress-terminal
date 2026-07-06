<?php

/**
 * Formats content read tool outputs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds readable summaries for post content and block trees.
 */
final class ToolResultContentReadFormatter {
    private ToolResultArrayFormatter $arrays;

    public function __construct(?ToolResultArrayFormatter $arrays = null) {
        $this->arrays = $arrays ?? new ToolResultArrayFormatter();
    }

    /**
     * @param array<array-key, mixed> $output
     */
    public function format(string $tool, array $output): string {
        return match ($tool) {
            'awpt/read-content' => $this->format_read_content($output),
            'awpt/read-block-tree' => $this->format_read_block_tree($output),
            default => '',
        };
    }

    /**
     * @param array<array-key, mixed> $output
     */
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
                $this->arrays->excerpt($plain, 360),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $output
     */
    private function format_read_block_tree(array $output): string {
        $blocks = $this->flatten_blocks($this->arrays->list_items($output['blocks'] ?? []));
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
            $lines[] = $this->format_block_line($block);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $block
     */
    private function format_block_line(array $block): string {
        $excerpt = trim((string) ($block['text_excerpt'] ?? ''));

        return sprintf(
            /* translators: 1: block path, 2: block name, 3: block text excerpt */
            __('- %1$s %2$s %3$s', 'agent-wordpress-terminal'),
            (string) ($block['path'] ?? ''),
            (string) ($block['name'] ?? ''),
            '' !== $excerpt ? '- ' . $this->arrays->excerpt($excerpt, 90) : '',
        );
    }

    /**
     * @param list<array<array-key, mixed>> $blocks
     * @return list<array<array-key, mixed>>
     */
    private function flatten_blocks(array $blocks): array {
        $flat = [];

        foreach ($blocks as $block) {
            $flat[] = $block;
            $inner = $this->arrays->list_items($block['inner'] ?? []);

            if ([] !== $inner) {
                $flat = array_merge($flat, $this->flatten_blocks($inner));
            }
        }

        return $flat;
    }
}
