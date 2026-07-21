<?php

/**
 * Content fields for staged action payloads.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\PostContentSanitizer;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Sanitizes post/block/meta fields on staged actions.
 */
final class ActionContentPayloadSanitizer {
    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $clean, array $payload): array {
        $clean = $this->copy_text_fields($clean, $payload);
        $clean = $this->copy_html_fields($clean, $payload);
        $clean = $this->copy_preview_fields($clean, $payload);
        $clean = $this->copy_meta_fields($clean, $payload);
        $clean = $this->copy_agent_rationale($clean, $payload);
        /** @var array<string, mixed> $clean */

        return $this->copy_block_fields($clean, $payload);
    }

    /** @param array<string, mixed> $clean @param array<string, mixed> $payload @return array<string, mixed> */
    private function copy_agent_rationale(array $clean, array $payload): array {
        if (is_array($payload['proposal_manifest'] ?? null)) {
            $manifest = $payload['proposal_manifest'];
            $clean['proposal_manifest'] = [
                'approach' => sanitize_textarea_field((string) ($manifest['approach'] ?? '')),
                'requirements' => array_values(array_filter(array_map(
                    static fn(mixed $item): ?array => is_array($item)
                        ? array_map(
                            static fn(mixed $value): string => sanitize_textarea_field(
                                is_scalar($value) ? (string) $value : '',
                            ),
                            array_filter($item, static fn(mixed $value): bool => is_scalar($value)),
                        )
                        : null,
                    is_array($manifest['requirements'] ?? null) ? $manifest['requirements'] : [],
                ))),
                'assumptions' => array_values(array_map(
                    static fn(mixed $item): string => sanitize_textarea_field(is_scalar($item) ? (string) $item : ''),
                    is_array($manifest['assumptions'] ?? null) ? $manifest['assumptions'] : [],
                )),
            ];
        }

        if (is_array($payload['decision_trace'] ?? null)) {
            $clean['decision_trace'] = array_values(array_map(static fn(mixed $item): string => sanitize_textarea_field(
                is_scalar($item) ? (string) $item : '',
            ), $payload['decision_trace']));
        }

        if (is_array($payload['repairs_applied'] ?? null)) {
            $clean['repairs_applied'] = array_values(array_filter(array_map(static function (mixed $item): ?array {
                if (!is_array($item)) {
                    return null;
                }

                return [
                    'kind' => sanitize_key((string) ($item['kind'] ?? '')),
                    'block_path' => sanitize_text_field((string) ($item['block_path'] ?? '')),
                    'block_name' => sanitize_text_field((string) ($item['block_name'] ?? '')),
                    'description' => sanitize_textarea_field((string) ($item['description'] ?? '')),
                ];
            }, $payload['repairs_applied'])));
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_text_fields(array $clean, array $payload): array {
        foreach ([
            'post_title',
            'post_type',
            'post_status',
            'original_post_title',
            'original_post_status',
            'pattern_name',
            'pattern_mode',
            'pattern_title',
            'pattern_source',
            'required_pattern_prefix',
            'template_type',
            'template_area',
            'post_name',
            'page_template',
            'global_styles_theme',
        ] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = sanitize_text_field((string) $payload[$key]);
        }

        if (array_key_exists('affected', $payload)) {
            $clean['affected'] = sanitize_textarea_field((string) $payload['affected']);
        }

        if (array_key_exists('post_parent', $payload)) {
            $clean['post_parent'] = absint(is_scalar($payload['post_parent']) ? $payload['post_parent'] : 0);
        }

        if (array_key_exists('required_attachment_ids', $payload) && is_array($payload['required_attachment_ids'])) {
            $ids = array_map(static fn(mixed $value): int => absint(
                is_scalar($value) ? $value : 0,
            ), $payload['required_attachment_ids']);
            $clean['required_attachment_ids'] = array_values(array_unique(array_filter($ids)));
        }

        if (array_key_exists('required_minimum_library_images', $payload)) {
            $clean['required_minimum_library_images'] = min(
                20,
                absint(
                    is_scalar($payload['required_minimum_library_images'])
                        ? $payload['required_minimum_library_images']
                        : 0,
                ),
            );
        }

        if (array_key_exists('required_minimum_visuals', $payload)) {
            $clean['required_minimum_visuals'] = min(
                20,
                absint(is_scalar($payload['required_minimum_visuals']) ? $payload['required_minimum_visuals'] : 0),
            );
        }

        if (array_key_exists('required_links', $payload) && is_array($payload['required_links'])) {
            $links = array_map(static fn(mixed $value): string => esc_url_raw(
                is_scalar($value) ? (string) $value : '',
            ), $payload['required_links']);
            $clean['required_links'] = array_values(array_unique(array_filter($links)));
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_html_fields(array $clean, array $payload): array {
        foreach (['post_content', 'original_post_content'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = PostContentSanitizer::for_staged_update((string) $payload[$key]);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_preview_fields(array $clean, array $payload): array {
        if (array_key_exists('preview_url', $payload)) {
            $clean['preview_url'] = esc_url_raw((string) $payload['preview_url']);
        }

        if (array_key_exists('preview_autosave_id', $payload)) {
            $clean['preview_autosave_id'] = absint(
                is_scalar($payload['preview_autosave_id']) ? $payload['preview_autosave_id'] : 0,
            );
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_meta_fields(array $clean, array $payload): array {
        if (array_key_exists('post_meta', $payload) && is_array($payload['post_meta'])) {
            $clean['post_meta'] = $this->sanitize_meta_map($payload['post_meta']);
        }

        if (array_key_exists('original_post_meta', $payload) && is_array($payload['original_post_meta'])) {
            $clean['original_post_meta'] = $this->sanitize_meta_map($payload['original_post_meta']);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_block_fields(array $clean, array $payload): array {
        $clean = $this->copy_block_identity_fields($clean, $payload);

        if (array_key_exists('attrs', $payload) && is_array($payload['attrs'])) {
            $clean['attrs'] = $this->sanitize_attrs_map($payload['attrs']);
        }

        if (array_key_exists('block', $payload) && is_array($payload['block'])) {
            $clean['block'] = $this->sanitize_block_definition($payload['block']);
        }

        if (array_key_exists('blocks', $payload) && is_array($payload['blocks'])) {
            $blocks = [];

            foreach (\AWPT\Support\ArrayKey::list_of_maps($payload['blocks']) as $block) {
                $sanitized = $this->sanitize_block_definition($block);

                if ('' !== $sanitized['blockName']) {
                    $blocks[] = $sanitized;
                }
            }

            $clean['blocks'] = $blocks;
        }

        if (array_key_exists('inserted_paths', $payload) && is_array($payload['inserted_paths'])) {
            $paths = [];

            foreach (\AWPT\Support\ArrayKey::list_of_strings($payload['inserted_paths']) as $path) {
                $value = sanitize_text_field($path);

                if (1 === preg_match('/^\d+(?:\.\d+)*$/', $value)) {
                    $paths[] = $value;
                }
            }

            $clean['inserted_paths'] = $paths;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_block_identity_fields(array $clean, array $payload): array {
        foreach (['block_path', 'block_name', 'expected_fingerprint', 'inserted_path'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $clean[$key] = sanitize_text_field((string) $payload[$key]);
        }

        if (array_key_exists('position', $payload)) {
            $clean['position'] = sanitize_key((string) $payload['position']);
        }

        return $clean;
    }

    /**
     * @param array<array-key, mixed> $block
     * @return array<string, mixed>
     */

    private function sanitize_block_definition(array $block): array {
        $name = sanitize_text_field((string) ($block['blockName'] ?? ''));
        $attrs = $this->sanitize_attrs_map(\AWPT\Support\ArrayKey::as_map($block['attrs'] ?? null));
        $inner_html = is_string($block['innerHTML'] ?? null) ? wp_kses_post($block['innerHTML']) : '';
        $inner_blocks = [];

        foreach (\AWPT\Support\ArrayKey::list_of_maps($block['innerBlocks'] ?? null) as $inner) {
            $inner_blocks[] = $this->sanitize_block_definition($inner);
        }

        return [
            'blockName' => $name,
            'attrs' => $attrs,
            'innerHTML' => $inner_html,
            'innerBlocks' => $inner_blocks,
            'innerContent' => [] === $inner_blocks ? [$inner_html] : array_fill(0, count($inner_blocks), null),
        ];
    }

    /**
     * @param array<array-key, mixed> $meta
     * @return array<string, string|int|float|bool>
     */
    private function sanitize_meta_map(array $meta): array {
        $clean = [];

        foreach (array_keys($meta) as $key) {
            $meta_key = sanitize_key((string) $key);

            if ('' === $meta_key) {
                continue;
            }

            $value = \AWPT\Support\ArrayKey::passthrough($meta[$key] ?? null);

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$meta_key] = $value;
                continue;
            }

            $clean[$meta_key] = sanitize_text_field((string) $value);
        }

        return $clean;
    }

    private function sanitize_attr_value(mixed $value): mixed {
        if (is_array($value)) {
            $clean = [];

            foreach (array_keys($value) as $key) {
                $clean[$key] = $this->sanitize_attr_value(\AWPT\Support\ArrayKey::passthrough($value[$key] ?? null));
            }

            return $clean;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
            return $value;
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * @param array<array-key, mixed> $attrs
     * @return array<string, mixed>
     */
    public function sanitize_attrs_map(array $attrs): array {
        $clean = [];

        foreach (array_keys(\AWPT\Support\ArrayKey::string_map($attrs)) as $key) {
            if ('' === $key) {
                continue;
            }

            $clean[$key] = $this->sanitize_attr_value(\AWPT\Support\ArrayKey::passthrough($attrs[$key] ?? null));
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
}
