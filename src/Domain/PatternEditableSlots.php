<?php

/**
 * Compact editable text slots for materialized patterns.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;
use AWPT\Support\BlockTree;

if (!defined('ABSPATH')) {
    exit();
}

/** Presents editable rich-text leaves without returning an entire block document. */
final class PatternEditableSlots {
    /** Dynamic collection descendants describe posts, not static page copy. */
    private const DYNAMIC_CONTAINERS = [
        'core/query',
        'core/post-template',
        'core/comments-query-loop',
    ];

    private PatternMetadataCatalog $catalog;

    public function __construct(?PatternMetadataCatalog $catalog = null) {
        $this->catalog = $catalog ?? new PatternMetadataCatalog();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function from_content(string $content, int $max = 100): array {
        $slots = [];
        $ordinals = [];
        $this->walk(
            BlockTree::from_content($content)->blocks(),
            [
                'parent_path' => '',
                'local_parent' => '',
                'pattern' => '',
                'instance' => '',
            ],
            max(1, min(200, $max)),
            $slots,
            $ordinals,
        );

        return $slots;
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $slots
     */
    private function walk(array $blocks, array $state, int $max, array &$slots, array &$ordinals): void {
        $parent_path = (string) ($state['parent_path'] ?? '');
        $local_parent = (string) ($state['local_parent'] ?? '');
        $inherited_pattern = (string) ($state['pattern'] ?? '');
        $inherited_instance = (string) ($state['instance'] ?? '');
        $visible_index = 0;

        foreach ($blocks as $block) {
            if (count($slots) >= $max) {
                return;
            }

            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            $path = '' === $parent_path ? (string) $visible_index : $parent_path . '.' . $visible_index;
            $name = (string) ($block['blockName'] ?? '');
            ++$visible_index;
            $attrs = ArrayKey::as_map($block['attrs'] ?? null);
            $metadata = ArrayKey::as_map($attrs['metadata'] ?? null);
            $pattern = sanitize_text_field((string) ($metadata['patternName'] ?? $inherited_pattern));
            $instance = sanitize_text_field((string) ($metadata['patternInstance'] ?? $inherited_instance));

            if ('' === $parent_path) {
                $ordinal_key = '' !== $instance ? $instance : $pattern;
                $ordinal = (int) ($ordinals[$ordinal_key] ?? 0);
                $ordinals[$ordinal_key] = $ordinal + 1;
                $local_path = (string) $ordinal;
            } else {
                $local_path = '' === $local_parent
                    ? (string) ($visible_index - 1)
                    : $local_parent . '.' . ($visible_index - 1);
            }

            if (PatternTextUpdater::is_replaceable_slot($block)) {
                $inner_html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
                $text = trim(html_entity_decode(wp_strip_all_tags($inner_html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                // Empty paragraphs and headings are intentional authoring slots
                // in starter layouts. Returning them prevents a valid shell
                // pattern from becoming an unusable zero-slot composition.
                $slot = [
                    'block_path' => $path,
                    'block_name' => $name,
                    'current_text' => mb_substr($text, 0, 1_000, 'UTF-8'),
                ];
                $contract = $this->contract($pattern, $local_path);

                if ([] !== $contract) {
                    $slot += [
                        'slot_id' => (string) $contract['id'],
                        'label' => (string) $contract['label'],
                        'required' => ArrayKey::rest_bool($contract['required']),
                        'max_characters' => (int) $contract['max_characters'],
                        'description' => (string) $contract['description'],
                        'pattern_name' => $pattern,
                    ];
                }

                $slots[] = $slot;
            }

            if (in_array($name, self::DYNAMIC_CONTAINERS, true)) {
                continue;
            }

            $inner_blocks = ArrayKey::list_of_maps($block['innerBlocks'] ?? null);
            $this->walk(
                $inner_blocks,
                [
                    'parent_path' => $path,
                    'local_parent' => $local_path,
                    'pattern' => $pattern,
                    'instance' => $instance,
                ],
                $max,
                $slots,
                $ordinals,
            );
        }
    }

    /** @return array<string, mixed> */
    private function contract(string $pattern, string $local_path): array {
        if ('' === $pattern) {
            return [];
        }

        foreach (ArrayKey::list_of_maps($this->catalog->get($pattern)['slots'] ?? null) as $slot) {
            if ($local_path === (string) ($slot['block_path'] ?? '')) {
                return $slot;
            }
        }

        return [];
    }
}
