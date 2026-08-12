<?php

/**
 * Restores non-negotiable structure from an explicitly selected pattern.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * An adapted pattern remains an editable, model-authored document.  Its declared
 * structural dependencies, however, should not disappear merely because the
 * model rewrote a visual section.  This completer restores only top-level
 * source blocks that contain a missing declared dependency; it never replaces
 * authored blocks or guesses at a layout when no pattern was selected.
 */
final class PatternStructureCompleter {
    private PatternMetadataCatalog $catalog;

    public function __construct(?PatternMetadataCatalog $catalog = null) {
        $this->catalog = $catalog ?? new PatternMetadataCatalog();
    }

    /**
     * @return array{content: string, repairs: list<string>}
     */
    public function complete(string $pattern_name, string $pattern_content, string $content): array {
        $required = ArrayKey::list_of_strings($this->catalog->get($pattern_name)['required_blocks'] ?? null);

        if ([] === $required || '' === trim($pattern_content) || '' === trim($content)) {
            return ['content' => $content, 'repairs' => []];
        }

        $document = parse_blocks($content);
        $source = parse_blocks($pattern_content);
        $present = $this->block_names($document);
        $append = [];
        $repairs = [];

        foreach ($required as $name) {
            if (isset($present[$name])) {
                continue;
            }

            $source_root = $this->top_level_source_block_containing($source, $name);

            if (null === $source_root) {
                continue;
            }

            $source_names = $this->block_names([$source_root]);
            $duplicate = false;

            foreach (array_keys($source_names) as $source_name) {
                if (!(isset($present[$source_name]) && $source_name === $name)) {
                    continue;
                }

                $duplicate = true;
                break;
            }

            if ($duplicate) {
                continue;
            }

            $append[] = $source_root;
            $present = array_replace($present, $source_names);
            $repairs[] = 'restored_pattern_required_block:' . $name;
        }

        if ([] === $append) {
            return ['content' => $content, 'repairs' => []];
        }

        /** @var array<int|string, array{attrs: array<array-key, mixed>, blockName: null|string, innerBlocks: array<array-key, array<array-key, mixed>>, innerContent: array<array-key, mixed>, innerHTML: string}> $serializable */
        $serializable = [...$document, ...$append];

        return ['content' => serialize_blocks($serializable), 'repairs' => $repairs];
    }

    /** @param array<int|string, mixed> $blocks @return array<string, true> */
    private function block_names(array $blocks): array {
        $names = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = (string) ($block['blockName'] ?? '');

            if ('' !== $name) {
                $names[$name] = true;
            }

            $names = array_replace(
                $names,
                $this->block_names(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : []),
            );
        }

        return $names;
    }

    /** @param array<int|string, mixed> $source @return array<string, mixed>|null */
    private function top_level_source_block_containing(array $source, string $required): ?array {
        foreach ($source as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (isset($this->block_names([$block])[$required])) {
                return $block;
            }
        }

        return null;
    }
}
