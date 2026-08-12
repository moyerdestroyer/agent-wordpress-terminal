<?php

/**
 * Resolves whether a block-theme template supplies the document H1.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/** Keeps post-title guidance aligned with the active template instead of assuming. */
final class ThemePostTitleStrategy {
    public const CONTENT_REQUIRED = 'content_h1_required';
    public const TEMPLATE_PROVIDES = 'template_provides_h1';
    public const UNKNOWN = 'unknown';

    public function for_post_type(string $post_type, string $page_template = ''): string {
        if (!function_exists('get_block_template')) {
            return self::UNKNOWN;
        }

        $slug = 'post' === $post_type ? 'single' : 'page';

        if ('page' === $post_type && '' !== $page_template && 'default' !== $page_template) {
            $path = str_replace('\\', '/', $page_template);
            $slug = pathinfo(basename($path), PATHINFO_FILENAME);
        }

        $template = get_block_template(get_stylesheet() . '//' . $slug, 'wp_template');
        $content = is_object($template) ? $template->content : '';

        if ('' === trim($content)) {
            return self::UNKNOWN;
        }

        return $this->content_has_post_title($content) ? self::TEMPLATE_PROVIDES : self::CONTENT_REQUIRED;
    }

    public function content_has_h1(string $content): bool {
        return $this->blocks_have_h1(parse_blocks($content));
    }

    /** @param array<int|string, mixed> $blocks */
    private function blocks_have_h1(array $blocks): bool {
        foreach ($blocks as $raw_block) {
            $block = ArrayKey::as_map($raw_block);

            if ('core/heading' === (string) ($block['blockName'] ?? '')) {
                $attrs = ArrayKey::as_map($block['attrs'] ?? null);

                if (1 === (int) ($attrs['level'] ?? 2)) {
                    return true;
                }
            }

            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

            if ([] !== $inner && $this->blocks_have_h1($inner)) {
                return true;
            }
        }

        return false;
    }

    private function content_has_post_title(string $content, array $seen = []): bool {
        return $this->blocks_have_post_title(parse_blocks($content), $seen);
    }

    /** @param array<int|string, mixed> $blocks */
    private function blocks_have_post_title(array $blocks, array $seen): bool {
        foreach ($blocks as $raw_block) {
            $block = ArrayKey::as_map($raw_block);

            if ('core/post-title' === (string) ($block['blockName'] ?? '')) {
                return true;
            }

            if ('core/pattern' === (string) ($block['blockName'] ?? '')) {
                $attrs = ArrayKey::as_map($block['attrs'] ?? null);
                $slug = sanitize_text_field((string) ($attrs['slug'] ?? ''));

                if ('' !== $slug && !in_array($slug, $seen, true)) {
                    $pattern_content = $this->pattern_content($slug);

                    if ('' !== $pattern_content && $this->content_has_post_title($pattern_content, [...$seen, $slug])) {
                        return true;
                    }
                }
            }

            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

            if ([] !== $inner && $this->blocks_have_post_title($inner, $seen)) {
                return true;
            }
        }

        return false;
    }

    private function pattern_content(string $slug): string {
        if (!class_exists('WP_Block_Patterns_Registry')) {
            return '';
        }

        $registry = \WP_Block_Patterns_Registry::get_instance();

        if (method_exists($registry, 'get_registered')) {
            $pattern = $registry->get_registered($slug);

            return is_array($pattern) ? (string) ($pattern['content'] ?? '') : '';
        }

        foreach ($registry->get_all_registered() as $pattern) {
            if ((string) ($pattern['name'] ?? '') === $slug) {
                return (string) ($pattern['content'] ?? '');
            }
        }

        return '';
    }
}
