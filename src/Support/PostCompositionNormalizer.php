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
    public function __construct(
        private readonly AttachmentBlockUrlNormalizer $attachment_urls = new AttachmentBlockUrlNormalizer(),
    ) {}

    /**
     * @return array{content: string, repairs: list<array{kind: string, block_path: string, block_name: string, description: string}>}
     */
    public function normalize(string $content): array {
        if (!str_contains($content, '<!-- wp:')) {
            return ['content' => $content, 'repairs' => []];
        }

        $attribute_repair = $this->repair_unclosed_attribute_json($content);
        $content = $attribute_repair['content'];
        $repairs = $attribute_repair['repaired']
            ? [[
                'kind' => 'block_attribute_json',
                'block_path' => '',
                'block_name' => '',
                'description' => __(
                    'Closed an unambiguous missing JSON object delimiter in a block comment.',
                    'agent-wordpress-terminal',
                ),
            ]] : [];

        $blocks = BlockTree::from_content($content)->blocks();
        $blocks = $this->normalize_blocks($blocks, $repairs);

        if ([] === $repairs) {
            return ['content' => $content, 'repairs' => []];
        }

        return ['content' => new BlockTreePathHelpers()->serialize($blocks), 'repairs' => $repairs];
    }

    /** @return array{content: string, repaired: bool} */
    private function repair_unclosed_attribute_json(string $content): array {
        $repaired = false;
        $normalized = preg_replace_callback(
            '/<!--\\s+wp:([^\\s]+)\\s+(\\{.*?\\})\\s*-->/s',
            static function (array $match) use (&$repaired): string {
                $json = $match[2] ?? '';

                if (is_array(json_decode($json, true))) {
                    return $match[0];
                }

                $balance = 0;
                $quoted = false;
                $escaped = false;

                foreach (str_split($json) as $char) {
                    if ($quoted) {
                        if (!$escaped && '\\' === $char) {
                            $escaped = true;
                            continue;
                        }
                        if (!$escaped && '"' === $char) {
                            $quoted = false;
                        }
                        $escaped = false;
                        continue;
                    }
                    if ('"' === $char) {
                        $quoted = true;
                    } elseif ('{' === $char) {
                        ++$balance;
                    } elseif ('}' === $char) {
                        --$balance;
                    }
                }

                if ($quoted || $balance < 1 || $balance > 3) {
                    return $match[0];
                }

                $candidate = $json . str_repeat('}', $balance);

                if (!is_array(json_decode($candidate, true))) {
                    return $match[0];
                }

                $repaired = true;

                return '<!-- wp:' . $match[1] . ' ' . $candidate . ' -->';
            },
            $content,
        );

        return ['content' => is_string($normalized) ? $normalized : $content, 'repaired' => $repaired];
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
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : $attrs;
            }

            $repaired_attachment_id = $this->attachment_urls->normalize($block, $attrs, $name);
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : $attrs;

            if ($repaired_attachment_id > 0) {
                $repairs[] = [
                    'kind' => 'canonical_attachment_url',
                    'block_path' => $path,
                    'block_name' => $name,
                    'description' => sprintf(
                        'Used the canonical Media Library URL for attachment #%d.',
                        $repaired_attachment_id,
                    ),
                ];
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

            if ('core/list' === $name) {
                $this->normalize_list_items($block, $path, $repairs);
            }

            if ('core/button' === $name) {
                $this->normalize_button_link($block, $attrs, $path, $repairs);
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
     * Restore the canonical link element when a model puts a button label
     * directly inside the core/button wrapper.
     *
     * @param array<string, mixed> $block
     * @param array<array-key, mixed> $attrs
     * @param list<array{kind: string, block_path: string, block_name: string, description: string}> $repairs
     */
    private function normalize_button_link(array &$block, array $attrs, string $path, array &$repairs): void {
        $inner_html = (string) ($block['innerHTML'] ?? '');

        if (str_contains($inner_html, 'wp-block-button__link')) {
            return;
        }

        $match = [];

        if (!preg_match(
            '/^(\s*<div\b[^>]*\bclass=("|\')[^"\']*\bwp-block-button\b[^"\']*\2[^>]*>)(.*)(<\/div>\s*)$/is',
            $inner_html,
            $match,
        )) {
            return;
        }

        $label = trim($match[3] ?? '');

        if (
            '' === $label
            || preg_match(
                '/<(?:address|article|aside|blockquote|div|footer|form|h[1-6]|header|main|nav|ol|p|section|table|ul)\b/i',
                $label,
            )
        ) {
            return;
        }

        $classes = ['wp-block-button__link'];
        $text_color = sanitize_key((string) ($attrs['textColor'] ?? ''));
        $background_color = sanitize_key((string) ($attrs['backgroundColor'] ?? ''));
        $gradient = sanitize_key((string) ($attrs['gradient'] ?? ''));

        if ('' !== $text_color) {
            $classes[] = 'has-' . $text_color . '-color';
            $classes[] = 'has-text-color';
        }

        if ('' !== $background_color) {
            $classes[] = 'has-' . $background_color . '-background-color';
            $classes[] = 'has-background';
        }

        if ('' !== $gradient) {
            $classes[] = 'has-' . $gradient . '-gradient-background';
            $classes[] = 'has-background';
        }

        $classes[] = 'wp-element-button';
        $anchor_attributes = ' class="' . esc_attr(implode(' ', array_values(array_unique($classes)))) . '"';
        $url = trim((string) ($attrs['url'] ?? ''));

        if ('' !== $url) {
            $anchor_attributes .= ' href="' . esc_url($url) . '"';
        }

        if ('_blank' === (string) ($attrs['linkTarget'] ?? '')) {
            $anchor_attributes .= ' target="_blank"';
        }

        $rel = trim((string) ($attrs['rel'] ?? ''));

        if ('' !== $rel) {
            $anchor_attributes .= ' rel="' . esc_attr($rel) . '"';
        }

        $replacement = ($match[1] ?? '') . '<a' . $anchor_attributes . '>' . $label . '</a>' . ($match[4] ?? '');

        // A Button is a leaf block. Rebuild its static slots together so both
        // WordPress's parser and lightweight test parsers serialize it once.
        $block['innerBlocks'] = [];
        $block['innerHTML'] = $replacement;
        $block['innerContent'] = [$replacement];

        $repairs[] = [
            'kind' => 'button_link_markup',
            'block_path' => $path,
            'block_name' => 'core/button',
            'description' => __(
                'Wrapped the button label in the canonical button link element.',
                'agent-wordpress-terminal',
            ),
        ];
    }

    /**
     * Convert legacy bare-&lt;li&gt; lists into nested core/list-item blocks.
     *
     * @param array<string, mixed> $block
     * @param list<array{kind: string, block_path: string, block_name: string, description: string}> $repairs
     */
    private function normalize_list_items(array &$block, string $path, array &$repairs): void {
        $inner_blocks = new BlockTreePathHelpers()->inner_blocks($block);
        $inner_html = (string) ($block['innerHTML'] ?? '');

        if ($this->has_named_inner_block($inner_blocks, 'core/list-item')) {
            return;
        }

        if (!str_contains(strtolower($inner_html), '<li')) {
            return;
        }

        $matches = [];

        if (false === preg_match_all('/<li\b[^>]*>.*?<\/li>/is', $inner_html, $matches) || [] === $matches[0]) {
            return;
        }

        $items = [];

        foreach ($matches[0] as $li_html) {
            if ('' === $li_html) {
                continue;
            }

            $items[] = [
                'blockName' => 'core/list-item',
                'attrs' => [],
                'innerBlocks' => [],
                'innerHTML' => $li_html,
                'innerContent' => [$li_html],
            ];
        }

        if ([] === $items) {
            return;
        }

        $tag = (bool) preg_match('/<\s*ol\b/i', $inner_html) ? 'ol' : 'ul';
        $class_match = [];
        $class_attr = '';

        if (preg_match('/<\s*' . $tag . '\b[^>]*\bclass=("|\')(.*?)\1/i', $inner_html, $class_match)) {
            $class_attr = ' class=' . $class_match[1] . $class_match[2] . $class_match[1];
        } elseif (!str_contains(strtolower($inner_html), 'wp-block-list')) {
            $class_attr = ' class="wp-block-list"';
        }

        $open = '<' . $tag . $class_attr . '>';
        $close = '</' . $tag . '>';
        $inner_content = [$open];

        foreach ($items as $_) {
            $inner_content[] = null;
        }

        $inner_content[] = $close;
        $joined_items = implode('', array_map(static fn(array $item): string => $item['innerHTML'], $items));

        $block['innerBlocks'] = $items;
        $block['innerContent'] = $inner_content;
        $block['innerHTML'] = $open . $joined_items . $close;

        $repairs[] = [
            'kind' => 'list_item_delimiters',
            'block_path' => $path,
            'block_name' => 'core/list',
            'description' => __(
                'Wrapped bare list items in core/list-item block delimiters.',
                'agent-wordpress-terminal',
            ),
        ];
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

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    private function has_named_inner_block(array $blocks, string $name): bool {
        foreach ($blocks as $inner) {
            if ($name === (string) ($inner['blockName'] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
