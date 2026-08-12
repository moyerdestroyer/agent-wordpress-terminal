<?php

/**
 * Path-addressed text updates for materialized patterns.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;
use AWPT\Support\BlockTree;
use AWPT\Support\BlockTreePathHelpers;

if (!defined('ABSPATH')) {
    exit();
}

/** Updates rich-text leaf blocks while preserving their wrapper markup and attributes. */
final class PatternTextUpdater {
    /** @var list<string> */
    private const EDITABLE_BLOCKS = [
        'core/heading',
        'core/paragraph',
        'core/button',
        'core/list-item',
        'core/quote',
        'core/pullquote',
    ];

    /** @param array<string, mixed> $block */
    public static function is_replaceable_slot(array $block): bool {
        $name = (string) ($block['blockName'] ?? '');

        if (!in_array($name, self::EDITABLE_BLOCKS, true)) {
            return false;
        }

        $inner_html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';

        return 1 === preg_match('/^(\s*<([a-z][a-z0-9-]*)\b[^>]*>).*?(<\/\2>\s*)$/is', $inner_html);
    }

    /**
     * @param array<array-key, mixed> $updates
     */
    public function apply(string $content, array $updates): string|\WP_Error {
        if ([] === $updates) {
            return $content;
        }

        $blocks = ArrayKey::list_of_maps(parse_blocks($content));

        foreach ($updates as $index => $update) {
            if (!is_array($update)) {
                return $this->error(
                    'awpt_invalid_pattern_text_update',
                    __('Pattern text updates must be objects.', 'agent-wordpress-terminal'),
                    $index,
                );
            }

            $path = sanitize_text_field((string) ($update['block_path'] ?? ''));
            $replacement = wp_kses_post((string) ($update['content'] ?? ''));
            $segments = $this->segments($path);

            if ([] === $segments) {
                return $this->error(
                    'awpt_invalid_pattern_text_path',
                    __('Pattern text updates require a dotted numeric block_path.', 'agent-wordpress-terminal'),
                    $index,
                );
            }

            $result = $this->update_at($blocks, $segments, $replacement);

            if (is_wp_error($result)) {
                $data = $result->get_error_data();
                $data = is_array($data) ? $data : [];

                return new \WP_Error(
                    $result->get_error_code(),
                    $result->get_error_message(),
                    array_merge($data, ['update_index' => (int) $index, 'block_path' => $path]),
                );
            }
        }

        return new BlockTreePathHelpers()->serialize($blocks);
    }

    /** @return list<int> */
    private function segments(string $path): array {
        if (!preg_match('/^\d+(?:\.\d+)*$/', $path)) {
            return [];
        }

        return array_map('intval', explode('.', $path));
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<int> $segments
     */
    private function update_at(array &$blocks, array $segments, string $replacement): true|\WP_Error {
        $target = array_shift($segments);
        $visible_index = 0;

        foreach ($blocks as &$block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            if ($visible_index !== $target) {
                ++$visible_index;
                continue;
            }

            if ([] !== $segments) {
                $inner = ArrayKey::list_of_maps($block['innerBlocks'] ?? null);
                $result = $this->update_at($inner, $segments, $replacement);

                if (true === $result) {
                    $block['innerBlocks'] = $inner;
                }

                return $result;
            }

            $name = (string) ($block['blockName'] ?? '');

            if (!in_array($name, self::EDITABLE_BLOCKS, true)) {
                return new \WP_Error(
                    'awpt_pattern_text_block_not_editable',
                    sprintf(__('Block %s is not an editable text slot.', 'agent-wordpress-terminal'), $name),
                    ['status' => 409],
                );
            }

            $inner_html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
            $match_count = 0;
            $updated = preg_replace_callback(
                '/^(\s*<([a-z][a-z0-9-]*)\b[^>]*>).*?(<\/\2>\s*)$/is',
                static fn(array $matches): string => $matches[1] . $replacement . $matches[3],
                $inner_html,
                1,
                $match_count,
            );

            if (!is_string($updated) || 0 === $match_count) {
                return new \WP_Error(
                    'awpt_pattern_text_markup_unavailable',
                    __(
                        'The selected text block does not have a replaceable outer HTML wrapper.',
                        'agent-wordpress-terminal',
                    ),
                    ['status' => 409],
                );
            }

            $block['innerHTML'] = $updated;
            $block['innerContent'] = [$updated];

            return true;
        }

        return new \WP_Error(
            'awpt_pattern_text_path_not_found',
            __('The selected pattern text block path was not found.', 'agent-wordpress-terminal'),
            ['status' => 409],
        );
    }

    private function error(string $code, string $message, int|string $index): \WP_Error {
        return new \WP_Error($code, $message, ['status' => 400, 'update_index' => (int) $index]);
    }
}
