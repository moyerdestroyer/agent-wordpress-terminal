<?php

/**
 * Canonicalizes Media Library URLs in image-bearing Gutenberg blocks.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Models can reliably select attachment IDs but must not reconstruct WordPress
 * filenames or generated image-size URLs.
 */
final class AttachmentBlockUrlNormalizer {
    private const SUPPORTED_BLOCKS = ['core/image', 'core/cover', 'core/media-text'];

    /**
     * @param array<string, mixed> $block
     * @param array<array-key, mixed> $attrs
     * @return int The repaired attachment ID, or zero when no repair was needed.
     */
    public function normalize(array &$block, array $attrs, string $name): int {
        if (!in_array($name, self::SUPPORTED_BLOCKS, true)) {
            return 0;
        }

        $attachment_id = $this->attachment_id($block, $attrs);

        if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
            return 0;
        }

        $attrs = $this->with_attachment_id($attrs, $attachment_id, $name);
        $block['attrs'] = $attrs;

        $canonical_url = $this->canonical_url($attachment_id, $attrs, $name);

        if ('' === $canonical_url) {
            return 0;
        }

        $changed = $this->replace_image_src($block, $canonical_url);

        if ('core/cover' === $name && (string) ($attrs['url'] ?? '') !== $canonical_url) {
            $attrs['url'] = $canonical_url;
            $block['attrs'] = $attrs;
            $changed = true;
        }

        return $changed ? $attachment_id : 0;
    }

    /** @param array<string, mixed> $block @param array<array-key, mixed> $attrs */
    private function attachment_id(array $block, array $attrs): int {
        $from_attrs = (int) ($attrs['id'] ?? $attrs['mediaId'] ?? 0);

        if ($from_attrs > 0 && wp_attachment_is_image($from_attrs)) {
            return $from_attrs;
        }

        $html = $this->static_html($block);
        $matches = [];

        if (preg_match('/\bwp-image-(\d+)\b/', $html, $matches)) {
            $from_class = (int) ($matches[1] ?? 0);

            if ($from_class > 0 && wp_attachment_is_image($from_class)) {
                return $from_class;
            }
        }

        if (!function_exists('attachment_url_to_postid')) {
            return 0;
        }

        if (preg_match('/<img\b[^>]*\bsrc=(["\'])(.*?)\1/is', $html, $matches)) {
            $from_url = absint(attachment_url_to_postid(html_entity_decode($matches[2] ?? '', ENT_QUOTES | ENT_HTML5)));

            if ($from_url > 0 && wp_attachment_is_image($from_url)) {
                return $from_url;
            }
        }

        return 0;
    }

    /** @param array<array-key, mixed> $attrs @return array<array-key, mixed> */
    private function with_attachment_id(array $attrs, int $attachment_id, string $name): array {
        if ('core/media-text' === $name) {
            $attrs['mediaId'] = $attachment_id;
            $attrs['mediaType'] = 'image';

            return $attrs;
        }

        $attrs['id'] = $attachment_id;

        return $attrs;
    }

    /** @param array<string, mixed> $block */
    private function static_html(array $block): string {
        $html = (string) ($block['innerHTML'] ?? '');

        if (!is_array($block['innerContent'] ?? null)) {
            return $html;
        }

        foreach (array_keys($block['innerContent']) as $key) {
            $part = ArrayKey::as_string(ArrayKey::passthrough($block['innerContent'][$key] ?? null));

            if (null !== $part) {
                $html .= "\n" . $part;
            }
        }

        return $html;
    }

    /** @param array<array-key, mixed> $attrs */
    private function canonical_url(int $attachment_id, array $attrs, string $name): string {
        unset($attrs, $name);
        $url = wp_get_attachment_url($attachment_id);

        if (!is_string($url) || '' === $url) {
            $url = wp_get_attachment_image_url($attachment_id, 'full');
        }

        return is_string($url) ? $url : '';
    }

    /** @param array<string, mixed> $block */
    private function replace_image_src(array &$block, string $canonical_url): bool {
        $changed = false;
        $replace = static function (string $html) use ($canonical_url, &$changed): string {
            $updated = preg_replace_callback(
                '/<img\b[^>]*>/i',
                static function (array $match) use ($canonical_url, &$changed): string {
                    $tag = $match[0] ?? '';
                    $src = [];

                    if (
                        preg_match('/\bsrc=(["\'])(.*?)\1/is', $tag, $src)
                        && html_entity_decode($src[2] ?? '', ENT_QUOTES | ENT_HTML5) === $canonical_url
                    ) {
                        return $tag;
                    }

                    $updated_tag = preg_replace('/\s+(?:srcset|sizes)=(["\']).*?\1/is', '', $tag);
                    $updated_tag = is_string($updated_tag) ? $updated_tag : $tag;
                    $escaped_url = esc_url($canonical_url);

                    if ([] !== $src) {
                        $updated_tag = str_replace($src[0], 'src=' . $src[1] . $escaped_url . $src[1], $updated_tag);
                    } else {
                        $updated_tag = (string) preg_replace(
                            '/^<img\b/i',
                            '<img src="' . $escaped_url . '"',
                            $updated_tag,
                            1,
                        );
                    }

                    $changed = true;

                    return $updated_tag;
                },
                $html,
                1,
            );

            return is_string($updated) ? $updated : $html;
        };

        $this->mutate_static_html($block, $replace);
        $this->mutate_freeform_children($block, $replace);

        return $changed;
    }

    /** @param array<string, mixed> $block @param callable(string): string $mutator */
    private function mutate_freeform_children(array &$block, callable $mutator): void {
        if (!is_array($block['innerBlocks'] ?? null)) {
            return;
        }

        foreach (array_keys($block['innerBlocks']) as $key) {
            $inner = ArrayKey::as_map($block['innerBlocks'][$key] ?? null);

            if ([] === $inner || null !== ($inner['blockName'] ?? null)) {
                continue;
            }

            $this->mutate_static_html($inner, $mutator);
            $block['innerBlocks'][$key] = $inner;
        }
    }

    /** @param array<string, mixed> $block @param callable(string): string $mutator */
    private function mutate_static_html(array &$block, callable $mutator): void {
        $block['innerHTML'] = $mutator((string) ($block['innerHTML'] ?? ''));

        if (!is_array($block['innerContent'] ?? null)) {
            return;
        }

        foreach (array_keys($block['innerContent']) as $key) {
            $part = ArrayKey::as_string(ArrayKey::passthrough($block['innerContent'][$key] ?? null));

            if (null === $part) {
                continue;
            }

            $block['innerContent'][$key] = $mutator($part);
        }
    }
}
