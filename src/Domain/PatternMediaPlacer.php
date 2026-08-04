<?php

/**
 * Intentional Media Library placement in a materialized pattern.
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

/** Inserts image blocks at agent-selected block paths instead of guessing placement. */
final class PatternMediaPlacer {
    /**
     * @param array<array-key, mixed> $placements
     */
    public function apply(string $content, array $placements): string|\WP_Error {
        if ([] === $placements) {
            return $content;
        }

        $normalized = [];

        foreach ($placements as $index => $placement) {
            if (!is_array($placement)) {
                return $this->error(
                    'awpt_invalid_media_placement',
                    __('Media placements must be objects.', 'agent-wordpress-terminal'),
                    $index,
                );
            }

            $raw_attachment_id = $placement['attachment_id'] ?? 0;
            $attachment_id = absint(is_scalar($raw_attachment_id) ? $raw_attachment_id : 0);
            $path = sanitize_text_field((string) ($placement['block_path'] ?? ''));
            $position = sanitize_key((string) ($placement['position'] ?? 'after'));
            $placement_kind = sanitize_key((string) ($placement['placement'] ?? 'insert'));

            if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
                return $this->error(
                    'awpt_invalid_media_placement_attachment',
                    __('A media placement must reference a valid image attachment.', 'agent-wordpress-terminal'),
                    $index,
                );
            }

            if (!in_array($placement_kind, ['insert', 'featured_cover'], true)) {
                return $this->error(
                    'awpt_invalid_media_placement_kind',
                    __('Media placement must be insert or featured_cover.', 'agent-wordpress-terminal'),
                    $index,
                );
            }

            if ('featured_cover' === $placement_kind && !preg_match('/^\d+(?:\.\d+)*$/', $path)) {
                return $this->error(
                    'awpt_invalid_media_placement_path',
                    __(
                        'A featured Cover placement requires its exact media-slot block_path.',
                        'agent-wordpress-terminal',
                    ),
                    $index,
                );
            }

            if (
                'insert' === $placement_kind
                && !in_array(
                    $position,
                    [BlockTree::POSITION_BEFORE, BlockTree::POSITION_AFTER, BlockTree::POSITION_APPEND],
                    true,
                )
            ) {
                return $this->error(
                    'awpt_invalid_media_placement_position',
                    __('Media placement position must be before, after, or append.', 'agent-wordpress-terminal'),
                    $index,
                );
            }

            if (
                'insert' === $placement_kind
                && BlockTree::POSITION_APPEND !== $position
                && !preg_match('/^\d+(?:\.\d+)*$/', $path)
            ) {
                return $this->error(
                    'awpt_invalid_media_placement_path',
                    __(
                        'Before and after media placements require a dotted numeric block_path.',
                        'agent-wordpress-terminal',
                    ),
                    $index,
                );
            }

            $normalized[] = [
                'attachment_id' => $attachment_id,
                'block_path' => $path,
                'position' => $position,
                'placement' => $placement_kind,
                'alt' => sanitize_text_field((string) ($placement['alt'] ?? '')),
                'input_index' => (int) $index,
            ];
        }

        // Paths refer to the original expanded pattern. Work from the end of the
        // document toward the start, and reverse equal anchors, so insertions do
        // not invalidate later targets and input order remains visible.
        usort($normalized, static function (array $left, array $right): int {
            if ($left['block_path'] === $right['block_path']) {
                if (
                    BlockTree::POSITION_APPEND === $left['position']
                    && BlockTree::POSITION_APPEND === $right['position']
                ) {
                    return $left['input_index'] <=> $right['input_index'];
                }

                return $right['input_index'] <=> $left['input_index'];
            }

            return strnatcmp($right['block_path'], $left['block_path']);
        });

        foreach ($normalized as $placement) {
            if ('featured_cover' === $placement['placement']) {
                $result = $this->bind_featured_cover($content, (string) $placement['block_path']);

                if (is_wp_error($result)) {
                    return $result;
                }

                $content = $result;
                continue;
            }

            $block = $this->image_block((int) $placement['attachment_id'], (string) $placement['alt']);

            if (is_wp_error($block)) {
                return $block;
            }

            $result = BlockTree::from_content($content)->insert_block(
                (string) $placement['block_path'],
                $block,
                (string) $placement['position'],
            );

            if (is_wp_error($result)) {
                return $result;
            }

            $content = $result['content'];
        }

        return $content;
    }

    private function bind_featured_cover(string $content, string $path): string|\WP_Error {
        $blocks = BlockTree::from_content($content)->blocks();
        $segments = new BlockTreePathHelpers()->path_segments($path);

        if ([] === $segments || !$this->update_featured_cover($blocks, $segments)) {
            return new \WP_Error(
                'awpt_featured_cover_slot_invalid',
                __('The selected media slot is not a Cover block at that path.', 'agent-wordpress-terminal'),
                ['status' => 400, 'block_path' => $path],
            );
        }

        return new BlockTreePathHelpers()->serialize($blocks);
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<int> $segments
     */
    private function update_featured_cover(array &$blocks, array $segments): bool {
        $target = array_shift($segments);
        $visible_index = 0;

        foreach ($blocks as &$block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            if ($visible_index++ !== $target) {
                continue;
            }

            if ([] !== $segments) {
                $inner = ArrayKey::list_of_maps($block['innerBlocks'] ?? null);
                $updated = $this->update_featured_cover($inner, $segments);

                if ($updated) {
                    $block['innerBlocks'] = $inner;
                }

                return $updated;
            }

            if ('core/cover' !== (string) ($block['blockName'] ?? '')) {
                return false;
            }

            $attrs = ArrayKey::as_map($block['attrs'] ?? null);
            $attrs['useFeaturedImage'] = true;
            unset($attrs['id'], $attrs['url'], $attrs['isDark']);
            $block['attrs'] = $attrs;
            $this->normalize_featured_cover_html($block);

            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $block */
    private function normalize_featured_cover_html(array &$block): void {
        $normalize = static function (string $html): string {
            $html = (string) preg_replace('/<img\b[^>]*\bwp-block-cover__image-background\b[^>]*>\s*/i', '', $html);
            $html = (string) preg_replace_callback(
                '/class=("|\')(.*?)\1/i',
                static function (array $match): string {
                    $classes = preg_split('/\s+/', trim((string) ($match[2] ?? '')));
                    $classes = array_values(array_filter(
                        is_array($classes) ? $classes : [],
                        static fn(string $class): bool => 'is-light' !== $class,
                    ));

                    return (
                        'class=' . (string) ($match[1] ?? '"') . implode(' ', $classes) . (string) ($match[1] ?? '"')
                    );
                },
                $html,
                1,
            );

            return $html;
        };

        $block['innerHTML'] = $normalize((string) ($block['innerHTML'] ?? ''));

        $inner_blocks = ArrayKey::list_of_maps($block['innerBlocks'] ?? null);

        if ([] !== $inner_blocks && [] === array_filter($inner_blocks, [BlockTree::class, 'has_block_name'])) {
            $block['innerBlocks'] = [];
            $block['innerContent'] = [$block['innerHTML']];
        }

        if (!is_array($block['innerContent'] ?? null)) {
            return;
        }

        foreach ($block['innerContent'] as &$part) {
            if (is_string($part)) {
                $part = $normalize($part);
            }
        }

        unset($part);
    }

    /** @return array<string, mixed>|\WP_Error */
    private function image_block(int $attachment_id, string $requested_alt): array|\WP_Error {
        // Persist the canonical Media Library URL. WordPress can return a
        // generated intermediate-size URL for `large`, but that URL is not the
        // attachment's identity and may not be present in content inventory.
        // Rendering still honors sizeSlug; integrity checks can now resolve the
        // block deterministically by attachment ID and original URL.
        $url = (string) wp_get_attachment_url($attachment_id);

        if ('' === $url) {
            $fallback = wp_get_attachment_image_url($attachment_id, 'large');
            $url = is_string($fallback) ? $fallback : '';
        }

        if ('' === $url) {
            return new \WP_Error(
                'awpt_media_placement_url_unavailable',
                __('The selected Media Library image has no usable URL.', 'agent-wordpress-terminal'),
                ['status' => 409, 'attachment_id' => $attachment_id],
            );
        }

        $alt = '' !== $requested_alt
            ? $requested_alt
            : trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
        $inner_html = sprintf(
            '<figure class="wp-block-image size-large"><img src="%1$s" alt="%2$s" class="wp-image-%3$d"/></figure>',
            esc_url($url),
            esc_attr($alt),
            $attachment_id,
        );

        return [
            'blockName' => 'core/image',
            'attrs' => [
                'id' => $attachment_id,
                'sizeSlug' => 'large',
                'linkDestination' => 'none',
            ],
            'innerBlocks' => [],
            'innerHTML' => $inner_html,
            'innerContent' => [$inner_html],
        ];
    }

    private function error(string $code, string $message, int|string $index): \WP_Error {
        return new \WP_Error($code, $message, ['status' => 400, 'placement_index' => (int) $index]);
    }
}
