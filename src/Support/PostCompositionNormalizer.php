<?php

/**
 * Repairs unambiguous Gutenberg serialization mistakes in generated content.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Keeps model-authored copy and composition intact while repairing block markup
 * that has one canonical representation in the editor.
 */
final class PostCompositionNormalizer {
    /**
     * @return array{content: string, repairs: list<array{kind: string, block_path: string, block_name: string, description: string}>}
     */
    public function normalize(string $content): array {
        if (!str_contains($content, '<!-- wp:')) {
            return ['content' => $content, 'repairs' => []];
        }

        $blocks = BlockTree::from_content($content)->blocks();
        $repairs = [];
        $blocks = $this->normalize_blocks($blocks, $repairs);

        if ([] === $repairs) {
            return ['content' => $content, 'repairs' => []];
        }

        return ['content' => new BlockTreePathHelpers()->serialize($blocks), 'repairs' => $repairs];
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param list<array{kind: string, block_path: string, block_name: string, description: string}> $repairs
     * @return array<int, array<string, mixed>>
     */
    private function normalize_blocks(array $blocks, array &$repairs, string $parent_path = ''): array {
        foreach ($blocks as $index => $block) {
            $path = '' === $parent_path ? (string) $index : $parent_path . '.' . $index;
            $name = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            if (in_array($name, ['core/group', 'core/cover'], true)) {
                $this->normalize_wrapper($block, $attrs, $path, $name, $repairs);
            }

            if ('core/cover' === $name && (int) ($attrs['id'] ?? 0) > 0) {
                $class_name = 'wp-image-' . (int) $attrs['id'];

                if ($this->ensure_image_class($block, $class_name)) {
                    $repairs[] = $this->image_class_repair('cover_image_class', $path, $name, $class_name);
                }
            }

            if ('core/media-text' === $name && (int) ($attrs['mediaId'] ?? 0) > 0) {
                $class_name = 'size-' . sanitize_key((string) ($attrs['mediaSizeSlug'] ?? 'full'));

                if ($this->ensure_image_class($block, $class_name)) {
                    $repairs[] = $this->image_class_repair('media_text_size_class', $path, $name, $class_name);
                }
            }

            $inner_blocks = new BlockTreePathHelpers()->inner_blocks($block);

            if ([] !== $inner_blocks) {
                $block['innerBlocks'] = $this->normalize_blocks(array_values($inner_blocks), $repairs, $path);
            }

            $blocks[$index] = $block;
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<array-key, mixed> $attrs
     * @param list<array{kind: string, block_path: string, block_name: string, description: string}> $repairs
     */
    private function normalize_wrapper(array &$block, array $attrs, string $path, string $name, array &$repairs): void {
        $inner_html = ltrim((string) ($block['innerHTML'] ?? ''));
        $match = [];

        if (!preg_match('/^<([a-z][a-z0-9-]*)\b/i', $inner_html, $match)) {
            return;
        }

        $actual_tag = strtolower($match[1] ?? '');
        $allowed_tags = ['div', 'section', 'main', 'header', 'footer', 'aside', 'nav', 'article'];

        if (!in_array($actual_tag, $allowed_tags, true)) {
            return;
        }

        $declared_tag = strtolower((string) ($attrs['tagName'] ?? 'div'));

        if ($declared_tag === $actual_tag) {
            return;
        }

        if (array_key_exists('tagName', $attrs) && in_array($declared_tag, $allowed_tags, true)) {
            $this->replace_outer_wrapper_tag($block, $actual_tag, $declared_tag);
            $description = sprintf(
                'Changed the saved wrapper from <%1$s> to the declared <%2$s>.',
                $actual_tag,
                $declared_tag,
            );
        } else {
            $attrs['tagName'] = $actual_tag;
            $block['attrs'] = $attrs;
            $description = sprintf('Recorded tagName "%s" to match the saved wrapper.', $actual_tag);
        }

        $repairs[] = [
            'kind' => 'wrapper_tag_alignment',
            'block_path' => $path,
            'block_name' => $name,
            'description' => $description,
        ];
    }

    /** @param array<string, mixed> $block */
    private function replace_outer_wrapper_tag(array &$block, string $from, string $to): void {
        $replace = static function (string $html) use ($from, $to): string {
            $updated = preg_replace('/^(\s*)<' . preg_quote($from, '/') . '\b/i', '$1<' . $to, $html, 1);
            $updated = is_string($updated) ? $updated : $html;
            $updated = preg_replace('/<\/' . preg_quote($from, '/') . '>(\s*)$/i', '</' . $to . '>$1', $updated, 1);

            return is_string($updated) ? $updated : $html;
        };

        $this->mutate_static_html($block, $replace);
    }

    /** @param array<string, mixed> $block */
    private function ensure_image_class(array &$block, string $class_name): bool {
        if (str_contains((string) ($block['innerHTML'] ?? ''), $class_name)) {
            return false;
        }

        $changed = false;
        $add_class = static function (string $html) use ($class_name, &$changed): string {
            $updated = preg_replace_callback(
                '/<img\b[^>]*>/i',
                static function (array $match) use ($class_name, &$changed): string {
                    $tag = $match[0] ?? '';

                    $class_match = [];

                    if (preg_match('/\bclass=("|\')(.*?)\1/is', $tag, $class_match)) {
                        $existing = $class_match[2] ?? '';

                        $existing_classes = preg_split('/\s+/', trim($existing));

                        if (in_array($class_name, false !== $existing_classes ? $existing_classes : [], true)) {
                            return $tag;
                        }

                        $replacement =
                            'class=' . $class_match[1] . trim($existing . ' ' . $class_name) . $class_match[1];
                        $changed = true;

                        return str_replace($class_match[0], $replacement, $tag);
                    }

                    $changed = true;

                    return (string) preg_replace('/^<img\b/i', '<img class="' . $class_name . '"', $tag, 1);
                },
                $html,
                1,
            );

            return is_string($updated) ? $updated : $html;
        };

        $this->mutate_static_html($block, $add_class);

        return $changed;
    }

    /** @return array{kind: string, block_path: string, block_name: string, description: string} */
    private function image_class_repair(string $kind, string $path, string $name, string $class_name): array {
        return [
            'kind' => $kind,
            'block_path' => $path,
            'block_name' => $name,
            'description' => sprintf('Added the canonical %s class to the block image.', $class_name),
        ];
    }

    /** @param array<string, mixed> $block @param callable(string): string $mutator */
    private function mutate_static_html(array &$block, callable $mutator): void {
        $block['innerHTML'] = $mutator((string) ($block['innerHTML'] ?? ''));

        if (!is_array($block['innerContent'] ?? null)) {
            return;
        }

        foreach ($block['innerContent'] as &$part) {
            if (!is_string($part)) {
                continue;
            }

            $part = $mutator($part);
        }

        unset($part);
    }
}
