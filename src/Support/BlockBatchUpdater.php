<?php

/**
 * Atomic path-addressed block batches.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Database\ActionPayloadSanitizer;
use AWPT\Domain\PatternTextUpdater;

if (!defined('ABSPATH')) {
    exit();
}

/** Applies a verified batch without asking the provider to resend a document. */
final class BlockBatchUpdater {
    /**
     * Build an operation-derived description that cannot promise edits absent from the batch.
     *
     * @param list<array<string, mixed>> $changes
     */
    public function describe(array $changes): string {
        $counts = [
            'set' => 0,
            'update_attrs' => 0,
            'replace_text' => 0,
            'replace_inner_html' => 0,
            'update_block' => 0,
            'remove' => 0,
            'insert' => 0,
        ];

        foreach ($changes as $change) {
            $kind = (string) ($change['kind'] ?? '');

            if (array_key_exists($kind, $counts)) {
                ++$counts[$kind];
            }
        }

        $parts = [];

        if ($counts['set'] > 0) {
            $parts[] = sprintf(
                _n('%d block update', '%d block updates', $counts['set'], 'agent-wordpress-terminal'),
                $counts['set'],
            );
        }

        if ($counts['update_attrs'] > 0) {
            $parts[] = sprintf(
                _n('%d attribute update', '%d attribute updates', $counts['update_attrs'], 'agent-wordpress-terminal'),
                $counts['update_attrs'],
            );
        }

        if ($counts['replace_text'] > 0) {
            $parts[] = sprintf(
                _n('%d text replacement', '%d text replacements', $counts['replace_text'], 'agent-wordpress-terminal'),
                $counts['replace_text'],
            );
        }

        if ($counts['replace_inner_html'] > 0) {
            $parts[] = sprintf(
                _n(
                    '%d saved HTML replacement',
                    '%d saved HTML replacements',
                    $counts['replace_inner_html'],
                    'agent-wordpress-terminal',
                ),
                $counts['replace_inner_html'],
            );
        }

        if ($counts['update_block'] > 0) {
            $parts[] = sprintf(
                _n(
                    '%d combined block update',
                    '%d combined block updates',
                    $counts['update_block'],
                    'agent-wordpress-terminal',
                ),
                $counts['update_block'],
            );
        }

        if ($counts['remove'] > 0) {
            $parts[] = sprintf(
                _n('%d block removal', '%d block removals', $counts['remove'], 'agent-wordpress-terminal'),
                $counts['remove'],
            );
        }

        if ($counts['insert'] > 0) {
            $parts[] = sprintf(
                _n('%d block insertion', '%d block insertions', $counts['insert'], 'agent-wordpress-terminal'),
                $counts['insert'],
            );
        }

        return sprintf(
            _n(
                'Staged %1$d verified block change: %2$s.',
                'Staged %1$d verified block changes: %2$s.',
                count($changes),
                'agent-wordpress-terminal',
            ),
            count($changes),
            implode(', ', $parts),
        );
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array{content: string, changes: list<array<string, mixed>>}|\WP_Error
     */
    public function apply(string $content, array $changes): array|\WP_Error {
        if ([] === $changes || count($changes) > 100) {
            return new \WP_Error(
                'awpt_invalid_block_batch_size',
                __('A block batch must contain between 1 and 100 changes.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $original = BlockTree::from_content($content);
        $validated = [];
        $seen_paths = [];

        foreach ($changes as $index => $change) {
            $kind = sanitize_key((string) ($change['kind'] ?? ''));
            $path = sanitize_text_field((string) ($change['block_path'] ?? $change['path'] ?? ''));
            $fingerprint = sanitize_text_field(
                (string) ($change['expected_fingerprint'] ?? $change['fingerprint'] ?? ''),
            );

            if (!in_array(
                $kind,
                ['set', 'update_attrs', 'replace_text', 'replace_inner_html', 'update_block', 'remove', 'insert'],
                true,
            )) {
                return $this->error(
                    'awpt_invalid_block_batch_kind',
                    __('Unsupported block batch change.', 'agent-wordpress-terminal'),
                    $index,
                    $path,
                );
            }

            $prior_kinds = is_array($seen_paths[$path] ?? null) ? $seen_paths[$path] : [];
            $combined_kinds = [...$prior_kinds, $kind];
            $non_insert_kinds = array_values(array_filter(
                $combined_kinds,
                static fn(string $candidate): bool => 'insert' !== $candidate,
            ));
            $compatible_shared_anchor = count($non_insert_kinds) <= 1 && !in_array('remove', $non_insert_kinds, true);
            // Allow remove+insert on one path as an in-place block type swap.
            $compatible_replace =
                1 === count(array_filter(
                    $combined_kinds,
                    static fn(string $candidate): bool => 'remove' === $candidate,
                ))
                && 1 === count(array_filter(
                    $combined_kinds,
                    static fn(string $candidate): bool => 'insert' === $candidate,
                ))
                && count($combined_kinds) === (count($non_insert_kinds) + 1)
                && ['remove'] === $non_insert_kinds;

            if (
                '' === $path
                || '' === $fingerprint
                || [] !== $prior_kinds && !$compatible_shared_anchor && !$compatible_replace
            ) {
                return $this->error(
                    'awpt_invalid_block_batch_target',
                    __(
                        'Each path may have one non-insertion mutation plus any number of anchored insertions, or a remove+insert swap. '
                        . 'Use kind set with attrs and/or html for combined edits on the same path.',
                        'agent-wordpress-terminal',
                    ),
                    $index,
                    $path,
                );
            }

            $block = $original->get_block($path);

            if (!is_array($block)) {
                return $this->error(
                    'awpt_block_not_found',
                    __('A batch target block was not found.', 'agent-wordpress-terminal'),
                    $index,
                    $path,
                    404,
                );
            }

            if (!hash_equals($fingerprint, BlockTree::fingerprint($block))) {
                $current_fingerprint = BlockTree::fingerprint($block);

                return new \WP_Error(
                    'awpt_block_fingerprint_mismatch',
                    __('A batch target changed since it was inspected.', 'agent-wordpress-terminal'),
                    [
                        'status' => 409,
                        'change_index' => $index,
                        'block_path' => $path,
                        'received_fingerprint' => $fingerprint,
                        'current_fingerprint' => $current_fingerprint,
                        'remediation' => __(
                            'Copy current_fingerprint exactly; fingerprints are 64 characters and must not be shortened.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                );
            }

            $normalized = [
                'kind' => $kind,
                'block_path' => $path,
                'expected_fingerprint' => $fingerprint,
                'block_name' => (string) ($block['blockName'] ?? ''),
                'change_index' => $index,
            ];

            if ('set' === $kind) {
                $set = $this->normalize_set($change, $block, $index, $path);

                if (is_wp_error($set)) {
                    return $set;
                }

                $normalized = array_merge($normalized, $set);
            }

            if (in_array($kind, ['update_attrs', 'update_block'], true)) {
                $attrs = is_array($change['attrs'] ?? null)
                    ? new ActionPayloadSanitizer()->sanitize_attrs_map($change['attrs'])
                    : [];

                if ([] === $attrs) {
                    return $this->error(
                        'awpt_empty_block_attrs',
                        __('An attribute change needs at least one attribute.', 'agent-wordpress-terminal'),
                        $index,
                        $path,
                    );
                }

                $attribute_error = $this->validate_registered_attributes((string) ($block['blockName'] ?? ''), $attrs);

                if (is_wp_error($attribute_error)) {
                    return $this->with_change_context($attribute_error, $index, $path);
                }

                $normalized['attrs'] = $attrs;
            }

            if (in_array($kind, ['replace_text', 'update_block'], true)) {
                if (!array_key_exists('content', $change)) {
                    return $this->error(
                        'awpt_block_batch_content_required',
                        __('A text change needs replacement content.', 'agent-wordpress-terminal'),
                        $index,
                        $path,
                    );
                }

                $normalized['content'] = wp_kses_post((string) $change['content']);
            }

            if ('replace_inner_html' === $kind) {
                if (!array_key_exists('inner_html', $change)) {
                    return $this->error(
                        'awpt_block_batch_inner_html_required',
                        __('A saved HTML replacement needs inner_html.', 'agent-wordpress-terminal'),
                        $index,
                        $path,
                    );
                }

                $inner_html = new BlockInnerHtmlUpdater()->validate($block, (string) $change['inner_html']);

                if (is_wp_error($inner_html)) {
                    return $this->with_change_context($inner_html, $index, $path);
                }

                $normalized['inner_html'] = $inner_html;
            }

            if ('insert' === $kind) {
                $block_name = sanitize_text_field((string) ($change['block_name'] ?? ''));

                if (1 !== preg_match('/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/', $block_name)) {
                    return $this->error(
                        'awpt_invalid_block_name',
                        __('An inserted block needs a valid namespace/block-name.', 'agent-wordpress-terminal'),
                        $index,
                        $path,
                    );
                }

                $position = sanitize_key((string) ($change['position'] ?? BlockTree::POSITION_BEFORE));

                if (!in_array($position, [BlockTree::POSITION_BEFORE, BlockTree::POSITION_AFTER], true)) {
                    return $this->error(
                        'awpt_invalid_block_position',
                        __(
                            'A batch insertion position must be before or after its verified anchor.',
                            'agent-wordpress-terminal',
                        ),
                        $index,
                        $path,
                    );
                }

                $attrs = is_array($change['attrs'] ?? null)
                    ? new ActionPayloadSanitizer()->sanitize_attrs_map($change['attrs'])
                    : [];
                $attribute_error = $this->validate_registered_attributes($block_name, $attrs);

                if (is_wp_error($attribute_error)) {
                    return $this->with_change_context($attribute_error, $index, $path);
                }

                $content_text = trim((string) ($change['content'] ?? ''));
                $inner_html = wp_kses_post((string) ($change['inner_html'] ?? ''));

                if (in_array($block_name, ['core/heading', 'core/paragraph'], true) && '' === $inner_html) {
                    if ('' === $content_text) {
                        return new \WP_Error(
                            'awpt_block_insert_content_required',
                            __('An inserted heading or paragraph needs non-empty content.', 'agent-wordpress-terminal'),
                            [
                                'status' => 400,
                                'change_index' => $index,
                                'block_path' => $path,
                                'recovery' => __(
                                    'Retry the same insert with content on the insert row. Do not add a set change for the anchor.',
                                    'agent-wordpress-terminal',
                                ),
                                'retry_example' => [
                                    'changes' => [[
                                        'kind' => 'insert',
                                        'block_path' => $path,
                                        'expected_fingerprint' => $fingerprint,
                                        'position' => $position,
                                        'block_name' => $block_name,
                                        'attrs' => 'core/heading' === $block_name ? ['level' => 1] : [],
                                        'content' => 'Use the verified page title or required copy',
                                    ]],
                                ],
                            ],
                        );
                    }

                    if ('core/heading' === $block_name) {
                        $level = max(1, min(6, (int) ($attrs['level'] ?? 2)));
                        $attrs['level'] = $level;
                        $inner_html = sprintf('<h%1$d>%2$s</h%1$d>', $level, wp_kses_post($content_text));
                    } else {
                        $inner_html = '<p>' . wp_kses_post($content_text) . '</p>';
                    }
                }

                $normalized['block_name'] = $block_name;
                $normalized['position'] = $position;
                $normalized['attrs'] = $attrs;
                $normalized['inner_html'] = $inner_html;
                $normalized['block'] = new BlockTreeEditor()->normalize_block([
                    'blockName' => $block_name,
                    'attrs' => $attrs,
                    'innerHTML' => $inner_html,
                    'innerBlocks' => [],
                    'innerContent' => [$inner_html],
                ]);
            }

            $seen_paths[$path] = [...$prior_kinds, $kind];
            $validated[] = $normalized;
        }

        $working = $content;

        foreach ($validated as $change) {
            if (!in_array($change['kind'], ['set', 'update_attrs', 'update_block'], true)) {
                continue;
            }

            if ('set' === $change['kind'] && !isset($change['attrs'])) {
                continue;
            }

            $attrs = ArrayKey::as_map($change['attrs'] ?? null);
            $result = BlockTree::from_content($working)->update_attrs(
                $change['block_path'],
                $attrs,
                $change['expected_fingerprint'],
            );

            if (is_wp_error($result)) {
                return $result;
            }

            $working = $result['content'];
        }

        foreach ($validated as $change) {
            if (
                !in_array($change['kind'], ['replace_text', 'update_block'], true)
                && !('set' === $change['kind'] && isset($change['content']) && !isset($change['inner_html']))
            ) {
                continue;
            }

            $result = new PatternTextUpdater()->apply($working, [[
                'block_path' => $change['block_path'],
                'content' => (string) ($change['content'] ?? ''),
            ]]);

            if (is_wp_error($result)) {
                return $result;
            }

            $working = $result;
        }

        foreach ($validated as $change) {
            if (
                'replace_inner_html' !== $change['kind']
                && !('set' === $change['kind'] && isset($change['inner_html']))
            ) {
                continue;
            }

            $result = new BlockInnerHtmlUpdater()->apply(
                $working,
                $change['block_path'],
                (string) ($change['inner_html'] ?? ''),
            );

            if (is_wp_error($result)) {
                return $result;
            }

            $working = $result;
        }

        $structural = array_values(array_filter($validated, static fn(array $change): bool => in_array(
            $change['kind'],
            ['remove', 'insert'],
            true,
        )));
        usort($structural, static function (array $left, array $right): int {
            $path_order = self::compare_paths($right['block_path'], $left['block_path']);

            return 0 !== $path_order ? $path_order : (int) $right['change_index'] <=> (int) $left['change_index'];
        });

        foreach ($structural as $change) {
            $tree = BlockTree::from_content($working);
            $result = 'remove' === $change['kind']
                ? $tree->remove_block($change['block_path'])
                : $tree->insert_block(
                    $change['block_path'],
                    ArrayKey::as_map($change['block'] ?? null),
                    (string) ($change['position'] ?? BlockTree::POSITION_BEFORE),
                );

            if (is_wp_error($result)) {
                return $result;
            }

            $working = $result['content'];
        }

        $validated = array_map(static function (array $change): array {
            unset($change['block'], $change['change_index']);

            return $change;
        }, $validated);

        return ['content' => $working, 'changes' => $validated];
    }

    /**
     * @param array<string, mixed> $change
     * @param array<string, mixed> $block
     * @return array<string, mixed>|\WP_Error
     */
    private function normalize_set(array $change, array $block, int $index, string $path): array|\WP_Error {
        $attrs = is_array($change['attrs'] ?? null)
            ? new ActionPayloadSanitizer()->sanitize_attrs_map($change['attrs'])
            : [];
        $html = (string) ($change['html'] ?? $change['inner_html'] ?? '');
        $text = (string) ($change['text'] ?? $change['content'] ?? '');
        $out = [];
        $block_name = (string) ($block['blockName'] ?? '');

        // Models often put rich text in attrs.content; that is not a registered block attr.
        if (isset($attrs['content']) && is_string($attrs['content'])) {
            if ('' === $text && '' === $html) {
                $text = $attrs['content'];
            }

            unset($attrs['content']);
        }

        if ([] !== $attrs) {
            $attribute_error = $this->validate_registered_attributes($block_name, $attrs);

            if (is_wp_error($attribute_error)) {
                // If html/text can still mutate the leaf, drop invalid attrs instead of failing the whole set.
                if ('' === $html && '' === $text) {
                    return $this->with_change_context($attribute_error, $index, $path);
                }

                $attribute_error = null;
            } else {
                $out['attrs'] = $attrs;
            }
        }

        if ('' !== $html) {
            $inner_html = new BlockInnerHtmlUpdater()->validate($block, $html);

            if (is_wp_error($inner_html)) {
                $inspect = new BlockInnerHtmlUpdater()->inspect($block);

                if ($inspect['editable']) {
                    return $this->with_change_context($inner_html, $index, $path);
                }

                $text = '' !== $text ? $text : wp_strip_all_tags($html);
            } else {
                $out['inner_html'] = $inner_html;
            }
        }

        if ('' !== $text && !isset($out['inner_html'])) {
            $out['content'] = wp_kses_post($text);
        }

        if ([] === $out) {
            return $this->error(
                'awpt_empty_block_set',
                __('A set change needs attrs and/or html (or text).', 'agent-wordpress-terminal'),
                $index,
                $path,
            );
        }

        return $out;
    }

    private static function compare_paths(string $left, string $right): int {
        $left_parts = array_map('intval', explode('.', $left));
        $right_parts = array_map('intval', explode('.', $right));
        $length = max(count($left_parts), count($right_parts));

        for ($index = 0; $index < $length; ++$index) {
            $comparison = ($left_parts[$index] ?? -1) <=> ($right_parts[$index] ?? -1);

            if (0 !== $comparison) {
                return $comparison;
            }
        }

        return 0;
    }

    private function error(string $code, string $message, int $index, string $path, int $status = 400): \WP_Error {
        return new \WP_Error($code, $message, [
            'status' => $status,
            'change_index' => $index,
            'block_path' => $path,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function validate_registered_attributes(string $block_name, array $attrs): ?\WP_Error {
        if (!class_exists('WP_Block_Type_Registry')) {
            return null;
        }

        $type = \WP_Block_Type_Registry::get_instance()->get_registered($block_name);

        if (!is_object($type)) {
            return null;
        }

        $registered = is_array($type->attributes ?? null) ? array_keys($type->attributes) : [];
        $unknown = array_values(array_diff(array_keys($attrs), $registered));

        if ([] === $unknown) {
            return null;
        }

        return new \WP_Error(
            'awpt_unknown_block_attribute',
            sprintf(
                __('Block %1$s does not declare attribute(s): %2$s.', 'agent-wordpress-terminal'),
                $block_name,
                implode(', ', $unknown),
            ),
            [
                'status' => 400,
                'block_name' => $block_name,
                'unknown_attributes' => $unknown,
                'allowed_attributes' => $registered,
                'recommended_next_tools' => ['awpt/get-block', 'awpt/propose-block-batch-update'],
            ],
        );
    }

    private function with_change_context(\WP_Error $error, int $index, string $path): \WP_Error {
        $raw_data = $error->get_error_data();
        $data = is_array($raw_data) ? $raw_data : [];

        return new \WP_Error(
            $error->get_error_code(),
            $error->get_error_message(),
            array_merge($data, ['change_index' => $index, 'block_path' => $path]),
        );
    }
}
