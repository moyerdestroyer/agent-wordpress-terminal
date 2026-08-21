<?php

/**
 * Content fields for staged action payloads.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\ArrayKey;
use AWPT\Support\PostContentSanitizer;
use AWPT\Support\ResourceValueSanitizer;

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
        $clean = $this->copy_review_undo_fields($clean, $payload);
        $clean = $this->copy_agent_rationale($clean, $payload);
        /** @var array<string, mixed> $clean */

        return $this->copy_block_fields($clean, $payload);
    }

    /** @param array<string, mixed> $clean @param array<string, mixed> $payload @return array<string, mixed> */
    private function copy_review_undo_fields(array $clean, array $payload): array {
        if (is_array($payload['review_undo_snapshot'] ?? null)) {
            $snapshot = $payload['review_undo_snapshot'];
            $clean['review_undo_snapshot'] = [
                'post_title' => sanitize_text_field((string) ($snapshot['post_title'] ?? '')),
                'post_content' => PostContentSanitizer::for_staged_update((string) ($snapshot['post_content'] ?? '')),
                'post_status' => sanitize_key((string) ($snapshot['post_status'] ?? '')),
                'meta' => new ResourceValueSanitizer()->sanitize_object(
                    is_array($snapshot['meta'] ?? null) ? $snapshot['meta'] : [],
                ),
            ];
        }

        if ('' !== (string) ($payload['review_applied_fingerprint'] ?? '')) {
            $clean['review_applied_fingerprint'] = sanitize_text_field((string) $payload['review_applied_fingerprint']);
        }

        return $clean;
    }

    /** @param array<array-key, mixed> $clean @param array<string, mixed> $payload @return array<string, mixed> */
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

        return $this->copy_composition_context(ArrayKey::string_map($clean), ArrayKey::string_map($payload));
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_composition_context(array $clean, array $payload): array {
        $clean = $this->copy_domain_validation($clean, $payload);

        if (!is_array($payload['composition_context'] ?? null)) {
            return $clean;
        }

        $context = $payload['composition_context'];
        $clean['composition_context'] = [
            'policy' => sanitize_key((string) ($context['policy'] ?? '')),
            'theme_name' => sanitize_text_field((string) ($context['theme_name'] ?? '')),
            'stylesheet' => sanitize_key((string) ($context['stylesheet'] ?? '')),
            'template' => sanitize_key((string) ($context['template'] ?? '')),
            'pattern_name' => sanitize_text_field((string) ($context['pattern_name'] ?? '')),
            'pattern_owner' => sanitize_key((string) ($context['pattern_owner'] ?? '')),
            'fallback_used' => filter_var($context['fallback_used'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'fallback_reason' => sanitize_textarea_field((string) ($context['fallback_reason'] ?? '')),
            'design_context_hash' => sanitize_text_field((string) ($context['design_context_hash'] ?? '')),
            'design_catalog_hash' => sanitize_text_field((string) ($context['design_catalog_hash'] ?? '')),
            'pattern_catalog_hash' => sanitize_text_field((string) ($context['pattern_catalog_hash'] ?? '')),
            'design_sources' => new ResourceValueSanitizer()->sanitize_object(
                is_array($context['design_sources'] ?? null) ? $context['design_sources'] : [],
            ),
            'guidance_ids' => array_values(array_filter(array_map(
                static fn(mixed $id): string => sanitize_key(is_scalar($id) ? (string) $id : ''),
                is_array($context['guidance_ids'] ?? null) ? $context['guidance_ids'] : [],
            ))),
        ];

        return $clean;
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function copy_domain_validation(array $clean, array $payload): array {
        if ('' !== (string) ($payload['ruleset_hash'] ?? '')) {
            $clean['ruleset_hash'] = sanitize_text_field((string) $payload['ruleset_hash']);
        }

        if (is_array($payload['agent_feedback'] ?? null)) {
            $clean['agent_feedback'] = new ResourceValueSanitizer()->sanitize_object($payload['agent_feedback']);
        }

        if (is_array($payload['safe_fixes'] ?? null)) {
            $clean['safe_fixes'] = array_values(array_filter(array_map(static function (mixed $fix): ?array {
                if (!is_array($fix)) {
                    return null;
                }

                return [
                    'id' => sanitize_key((string) ($fix['id'] ?? '')),
                    'description' => sanitize_textarea_field((string) ($fix['description'] ?? '')),
                    'block_path' => sanitize_text_field((string) ($fix['block_path'] ?? '')),
                    'before_hash' => sanitize_text_field((string) ($fix['before_hash'] ?? '')),
                    'after_hash' => sanitize_text_field((string) ($fix['after_hash'] ?? '')),
                    'applied' => filter_var($fix['applied'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }, $payload['safe_fixes'])));
        }

        if (is_array($payload['validation_findings'] ?? null)) {
            $clean['validation_findings'] = array_values(array_filter(array_map(
                static function (mixed $finding): ?array {
                    if (!is_array($finding)) {
                        return null;
                    }

                    return [
                        'severity' => sanitize_key((string) ($finding['severity'] ?? 'warning')),
                        'code' => sanitize_key((string) ($finding['code'] ?? '')),
                        'message' => sanitize_textarea_field((string) ($finding['message'] ?? '')),
                        'rule_id' => sanitize_key((string) ($finding['rule_id'] ?? '')),
                        'block_path' => sanitize_text_field((string) ($finding['block_path'] ?? '')),
                        'source' => sanitize_text_field((string) ($finding['source'] ?? '')),
                        'suggestion' => sanitize_textarea_field((string) ($finding['suggestion'] ?? '')),
                        'pack_id' => sanitize_key((string) ($finding['pack_id'] ?? '')),
                        'expected' => is_scalar($finding['expected'] ?? null)
                            ? sanitize_text_field((string) $finding['expected'])
                            : '',
                        'actual' => is_scalar($finding['actual'] ?? null)
                            ? sanitize_text_field((string) $finding['actual'])
                            : '',
                        'docs' => sanitize_text_field((string) ($finding['docs'] ?? '')),
                    ];
                },
                $payload['validation_findings'],
            )));
        }

        if (is_array($payload['composition_manifest']['patterns'] ?? null)) {
            $clean['composition_manifest'] = [
                'patterns' => array_values(array_filter(array_map(static function (mixed $pattern): ?array {
                    if (!is_array($pattern)) {
                        return null;
                    }

                    $name = sanitize_text_field((string) ($pattern['name'] ?? ''));

                    if ('' === $name) {
                        return null;
                    }

                    return [
                        'name' => $name,
                        'block_path' => sanitize_text_field((string) ($pattern['block_path'] ?? '')),
                        'mode' => sanitize_key((string) ($pattern['mode'] ?? '')),
                        'source_hash' => sanitize_text_field((string) ($pattern['source_hash'] ?? '')),
                        'pack_id' => sanitize_key((string) ($pattern['pack_id'] ?? '')),
                        'pack_version' => sanitize_text_field((string) ($pattern['pack_version'] ?? '')),
                    ];
                }, $payload['composition_manifest']['patterns']))),
            ];
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
            'pattern_owner',
            'pattern_fallback_reason',
            'pattern_unfit_code',
            'required_pattern_prefix',
            'preparation_id',
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

        if (array_key_exists('presentation_requires_h1', $payload)) {
            $clean['presentation_requires_h1'] = true === $payload['presentation_requires_h1'];
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

        if (array_key_exists('required_document_ids', $payload) && is_array($payload['required_document_ids'])) {
            $ids = array_map(static fn(mixed $value): int => absint(
                is_scalar($value) ? $value : 0,
            ), $payload['required_document_ids']);
            $clean['required_document_ids'] = array_values(array_unique(array_filter($ids)));
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

        if (array_key_exists('replaced_paths', $payload) && is_array($payload['replaced_paths'])) {
            $paths = [];

            foreach (\AWPT\Support\ArrayKey::list_of_strings($payload['replaced_paths']) as $path) {
                $value = sanitize_text_field($path);

                if (1 === preg_match('/^\d+(?:\.\d+)*$/', $value)) {
                    $paths[] = $value;
                }
            }

            $clean['replaced_paths'] = $paths;
        }

        if (array_key_exists('batch_changes', $payload) && is_array($payload['batch_changes'])) {
            $clean['batch_changes'] = array_values(array_filter(array_map(function (mixed $change): ?array {
                if (!is_array($change)) {
                    return null;
                }

                $kind = sanitize_key((string) ($change['kind'] ?? ''));
                $path = sanitize_text_field((string) ($change['block_path'] ?? ''));

                if (
                    !in_array(
                        $kind,
                        ['update_attrs', 'replace_text', 'replace_inner_html', 'update_block', 'remove', 'insert'],
                        true,
                    )
                    || '' === $path
                ) {
                    return null;
                }

                $item = [
                    'kind' => $kind,
                    'block_path' => $path,
                    'expected_fingerprint' => sanitize_text_field((string) ($change['expected_fingerprint'] ?? '')),
                    'block_name' => sanitize_text_field((string) ($change['block_name'] ?? '')),
                ];

                if (is_array($change['attrs'] ?? null)) {
                    $item['attrs'] = $this->sanitize_attrs_map($change['attrs']);
                }

                if (array_key_exists('content', $change)) {
                    $item['content'] = wp_kses_post((string) $change['content']);
                }

                if (in_array($kind, ['replace_inner_html', 'insert'], true)) {
                    $item['inner_html'] = wp_kses_post((string) ($change['inner_html'] ?? ''));
                }

                if ('insert' === $kind) {
                    $item['position'] = sanitize_key((string) ($change['position'] ?? 'before'));
                }

                return $item;
            }, $payload['batch_changes'])));
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

        // Preserve Gutenberg innerContent (wrapper HTML + null child slots). Replacing
        // it with all-null placeholders drops container markup so apply-time rebuilds
        // for pattern_insert/pattern_replace lose group/columns wrappers that preview
        // still shows from post_content.
        $inner_content = $this->sanitize_inner_content(
            is_array($block['innerContent'] ?? null) ? $block['innerContent'] : null,
            $inner_html,
            count($inner_blocks),
        );

        return [
            'blockName' => $name,
            'attrs' => $attrs,
            'innerHTML' => $inner_html,
            'innerBlocks' => $inner_blocks,
            'innerContent' => $inner_content,
        ];
    }

    /**
     * @param array<array-key, mixed>|null $inner_content
     * @return list<null|string>
     */
    private function sanitize_inner_content(?array $inner_content, string $inner_html, int $inner_block_count): array {
        $inner_block_count = max(0, $inner_block_count);

        if (null === $inner_content || [] === $inner_content) {
            return 0 === $inner_block_count ? [$inner_html] : array_fill(0, $inner_block_count, null);
        }

        $clean = [];
        $null_slots = 0;

        foreach ($inner_content as $part) {
            if (null === $part) {
                $clean[] = null;
                ++$null_slots;
                continue;
            }

            if (is_string($part) || is_scalar($part)) {
                $clean[] = wp_kses_post((string) $part);
            }
        }

        // If the stored map under-counts children, pad nulls so serialize_block stays aligned.
        while ($null_slots < $inner_block_count) {
            $clean[] = null;
            ++$null_slots;
        }

        if ([] === $clean) {
            return 0 === $inner_block_count ? [$inner_html] : array_fill(0, $inner_block_count, null);
        }

        return $clean;
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
