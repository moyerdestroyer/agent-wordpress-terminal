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
            'update_attrs' => 0,
            'replace_text' => 0,
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
            $path = sanitize_text_field((string) ($change['block_path'] ?? ''));
            $fingerprint = sanitize_text_field((string) ($change['expected_fingerprint'] ?? ''));

            if (!in_array($kind, ['update_attrs', 'replace_text', 'remove', 'insert'], true)) {
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

            if ('' === $path || '' === $fingerprint || [] !== $prior_kinds && !$compatible_shared_anchor) {
                return $this->error(
                    'awpt_invalid_block_batch_target',
                    __(
                        'Each path may have one content change plus any number of insertions anchored before or after it.',
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

            if ('update_attrs' === $kind) {
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

                $normalized['attrs'] = $attrs;
            } elseif ('replace_text' === $kind) {
                if (!array_key_exists('content', $change)) {
                    return $this->error(
                        'awpt_block_batch_content_required',
                        __('A text change needs replacement content.', 'agent-wordpress-terminal'),
                        $index,
                        $path,
                    );
                }

                $normalized['content'] = wp_kses_post((string) $change['content']);
            } elseif ('insert' === $kind) {
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
                $inner_html = wp_kses_post((string) ($change['inner_html'] ?? ''));
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
            if ('update_attrs' !== $change['kind']) {
                continue;
            }

            $attrs = is_array($change['attrs'] ?? null) ? $change['attrs'] : [];
            $result = BlockTree::from_content($working)->update_attrs(
                (string) $change['block_path'],
                $attrs,
                (string) $change['expected_fingerprint'],
            );

            if (is_wp_error($result)) {
                return $result;
            }

            $working = $result['content'];
        }

        foreach ($validated as $change) {
            if ('replace_text' !== $change['kind']) {
                continue;
            }

            $result = new PatternTextUpdater()->apply($working, [[
                'block_path' => (string) $change['block_path'],
                'content' => (string) $change['content'],
            ]]);

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
            $path_order = self::compare_paths((string) $right['block_path'], (string) $left['block_path']);

            return 0 !== $path_order
                ? $path_order
                : (int) ($right['change_index'] ?? 0) <=> (int) ($left['change_index'] ?? 0);
        });

        foreach ($structural as $change) {
            $tree = BlockTree::from_content($working);
            $result = 'remove' === $change['kind']
                ? $tree->remove_block((string) $change['block_path'])
                : $tree->insert_block(
                    (string) $change['block_path'],
                    is_array($change['block'] ?? null) ? $change['block'] : [],
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
}
