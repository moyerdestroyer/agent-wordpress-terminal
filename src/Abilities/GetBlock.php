<?php

/**
 * awpt/get-block ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Support\BlockInnerHtmlUpdater;
use AWPT\Support\BlockTree;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns one Gutenberg block by dotted path.
 */
final class GetBlock implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/get-block',
            'label' => __('Get Block', 'agent-wordpress-terminal'),
            'description' => __(
                'Returns a single Gutenberg block from a post by dotted path from awpt/read-block-tree or awpt/list-blocks.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => __('Post ID.', 'agent-wordpress-terminal'),
                    ],
                    'action_id' => [
                        'type' => 'integer',
                        'description' => __('Optional internal staged candidate ID.', 'agent-wordpress-terminal'),
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => __(
                            'Dotted zero-based visible block path, e.g. 0 or 2.1.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['path'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function can_read(array $input): bool {
        $post_id = $this->post_id($input);

        return $post_id > 0 && current_user_can('read_post', $post_id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $action_id = (int) ($input['action_id'] ?? 0);
        $post_id = $this->post_id($input);
        $path = sanitize_text_field((string) ($input['path'] ?? ''));
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error('awpt_post_not_found', __('Post not found.', 'agent-wordpress-terminal'));
        }

        $content = $post->post_content;

        if ($action_id > 0) {
            $action = new ActionRepository()->format_action($action_id);
            $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];
            $content = (string) ($payload['post_content'] ?? '');
        }

        $tree = BlockTree::from_content($content);
        $block = $tree->get_block($path);

        if (null === $block) {
            return new \WP_Error('awpt_block_not_found', __('Block path was not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $summary = null;

        foreach ($tree->flat_list(null, 500) as $item) {
            if (($item['path'] ?? '') !== $path) {
                continue;
            }

            $summary = $item;
            break;
        }

        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        $inner_html = new BlockInnerHtmlUpdater()->inspect($block);
        $normalized = new \AWPT\Support\BlockTreeView()->subtree_at_path($tree->normalized(), $path);
        $children = is_array($normalized) && is_array($normalized['inner'] ?? null)
            ? array_map(
                static fn(array $child): array => array_intersect_key($child, array_flip([
                    'path',
                    'name',
                    'fingerprint',
                    'text_excerpt',
                    'attributes_summary',
                ])),
                array_slice($normalized['inner'], 0, 100),
            )
            : [];

        return [
            'id' => $post_id,
            'action_id' => $action_id > 0 ? $action_id : null,
            'path' => $path,
            'name' => $block['blockName'] ?? '',
            'attrs' => $attrs,
            'fingerprint' => BlockTree::fingerprint($block),
            'text_excerpt' => is_array($summary) ? (string) ($summary['text_excerpt'] ?? '') : '',
            'attributes_summary' => is_array($summary) && is_array($summary['attributes_summary'] ?? null)
                ? $summary['attributes_summary']
                : [],
            'inner_count' => count($inner_blocks),
            'inner_html' => $inner_html['inner_html'],
            'inner_html_editable' => $inner_html['editable'],
            'inner_html_truncated' => $inner_html['truncated'],
            'inner_html_editability_reason' => $inner_html['reason'],
            'children' => $children,
            'next' => !$inner_html['editable'] && [] !== $children
                ? __(
                    'Edit a returned child path with its exact fingerprint, or inspect that child with awpt/get-block first.',
                    'agent-wordpress-terminal',
                )
                : '',
        ];
    }

    /** @param array<string, mixed> $input */
    private function post_id(array $input): int {
        $action_id = (int) ($input['action_id'] ?? 0);

        if ($action_id <= 0) {
            return (int) ($input['id'] ?? 0);
        }

        $action = new ActionRepository()->format_action($action_id);

        if (
            null === $action
            || !in_array((string) ($action['status'] ?? ''), ['verifying', 'proposed', 'approved'], true)
        ) {
            return 0;
        }

        $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];

        return (int) ($payload['post_id'] ?? 0);
    }
}
