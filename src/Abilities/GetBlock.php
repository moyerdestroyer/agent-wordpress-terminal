<?php

/**
 * awpt/get-block ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

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
                    'path' => [
                        'type' => 'string',
                        'description' => __(
                            'Dotted zero-based visible block path, e.g. 0 or 2.1.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['id', 'path'],
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
        $post_id = (int) ($input['id'] ?? 0);

        return $post_id > 0 && current_user_can('read_post', $post_id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $post_id = (int) ($input['id'] ?? 0);
        $path = sanitize_text_field((string) ($input['path'] ?? ''));
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return new \WP_Error('awpt_post_not_found', __('Post not found.', 'agent-wordpress-terminal'));
        }

        $tree = BlockTree::from_content($post->post_content);
        $block = $tree->get_block($path);

        if (null === $block) {
            return new \WP_Error('awpt_block_not_found', __('Block path was not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $summary = null;

        foreach ($tree->flat_list(null, 500) as $item) {
            if (($item['path'] ?? '') === $path) {
                $summary = $item;
                break;
            }
        }

        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

        return [
            'id' => $post_id,
            'path' => $path,
            'name' => $block['blockName'] ?? '',
            'attrs' => $attrs,
            'fingerprint' => BlockTree::fingerprint($block),
            'text_excerpt' => is_array($summary) ? (string) ($summary['text_excerpt'] ?? '') : '',
            'attributes_summary' => is_array($summary) && is_array($summary['attributes_summary'] ?? null)
                ? $summary['attributes_summary']
                : [],
            'inner_count' => count($inner_blocks),
        ];
    }
}
