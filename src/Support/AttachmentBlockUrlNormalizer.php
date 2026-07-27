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

        $attachment_id = (int) ($attrs['id'] ?? $attrs['mediaId'] ?? 0);

        if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
            return 0;
        }

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

    /** @param array<array-key, mixed> $attrs */
    private function canonical_url(int $attachment_id, array $attrs, string $name): string {
        $size = match ($name) {
            'core/image' => sanitize_key((string) ($attrs['sizeSlug'] ?? 'large')),
            'core/media-text' => sanitize_key((string) ($attrs['mediaSizeSlug'] ?? 'full')),
            default => 'full',
        };
        $url = wp_get_attachment_image_url($attachment_id, '' !== $size ? $size : 'full');

        if (!is_string($url) || '' === $url) {
            $url = wp_get_attachment_url($attachment_id);
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
