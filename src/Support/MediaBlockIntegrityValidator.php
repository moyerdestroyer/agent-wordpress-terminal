<?php

/**
 * Rejects unresolved same-site Media Library URLs without guessing replacements.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

final class MediaBlockIntegrityValidator {
    /** @var list<string> */
    private const SUPPORTED_BLOCKS = ['core/image', 'core/cover', 'core/media-text'];

    public function validate(string $content): ?\WP_Error {
        return $this->validate_blocks(parse_blocks($content));
    }

    /** @param array<int|string, mixed> $blocks */
    private function validate_blocks(array $blocks, string $parent_path = ''): ?\WP_Error {
        foreach (array_keys($blocks) as $index) {
            $block = ArrayKey::as_map($blocks[$index] ?? null);

            if ([] === $block) {
                continue;
            }

            $path = '' === $parent_path ? (string) $index : $parent_path . '.' . $index;
            $name = (string) ($block['blockName'] ?? '');

            if (in_array($name, self::SUPPORTED_BLOCKS, true)) {
                $candidate_urls = ArrayKey::list_of_strings($this->candidate_urls($block));

                foreach ($candidate_urls as $url) {
                    if ($this->is_unresolved_local_upload($url)) {
                        return new \WP_Error(
                            'awpt_unresolved_local_media_url',
                            sprintf(
                                /* translators: 1: block path, 2: block name, 3: image URL. */
                                __(
                                    'Block %1$s (%2$s) references an unresolved Media Library URL: %3$s.',
                                    'agent-wordpress-terminal',
                                ),
                                $path,
                                $name,
                                $url,
                            ),
                            [
                                'status' => 400,
                                'block_path' => $path,
                                'block_name' => $name,
                                'url' => $url,
                                'recovery' => __(
                                    'Do not invent Media Library IDs or keep unresolved same-site /wp-content/uploads/ URLs. Prefer: (1) omit optional image/cover media when no verified attachment exists, or (2) declare pattern_unfit_code media_unavailable and adapt without those photos. Only use a verified attachment ID with its exact canonical URL from Media Library evidence. AWPT will not guess an image from a filename or featured image.',
                                    'agent-wordpress-terminal',
                                ),
                                'recommended_next_tools' => [
                                    [
                                        'tool' => 'awpt/propose-content-update',
                                        'reason' => __(
                                            'Retry without unresolved local media URLs: omit optional images, or use pattern_unfit_code media_unavailable for text-only adaptation.',
                                            'agent-wordpress-terminal',
                                        ),
                                    ],
                                ],
                            ],
                        );
                    }
                }
            }

            $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            $inner_error = $this->validate_blocks($inner_blocks, $path);

            if ($inner_error instanceof \WP_Error) {
                return $inner_error;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $block @return list<string> */
    private function candidate_urls(array $block): array {
        /** @var list<string> $urls */
        $urls = [];
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

        if (is_string($attrs['url'] ?? null) && '' !== $attrs['url']) {
            $urls[] = $attrs['url'];
        }

        $html = (string) ($block['innerHTML'] ?? '');
        $matches = [];

        if (preg_match_all('/<img\b[^>]*\bsrc=(["\'])(.*?)\1/is', $html, $matches)) {
            foreach (ArrayKey::list_of_strings($matches[2] ?? []) as $url) {
                $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
            }
        }

        return array_values(array_unique($urls));
    }

    private function is_unresolved_local_upload(string $url): bool {
        if ('' === $url || !str_contains($url, '/wp-content/uploads/')) {
            return false;
        }

        $home_host = $this->host(home_url('/'));
        $url_host = $this->host($url);

        if ('' !== $url_host && '' !== $home_host && strtolower($url_host) !== strtolower($home_host)) {
            return false;
        }

        return !function_exists('attachment_url_to_postid') || absint(attachment_url_to_postid($url)) <= 0;
    }

    private function host(string $url): string {
        $parsed = wp_parse_url($url);

        if (!is_array($parsed) || !is_string($parsed['host'] ?? null)) {
            return '';
        }

        return $parsed['host'];
    }
}
