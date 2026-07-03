<?php

/**
 * awpt/read-block-tree ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

defined('ABSPATH') || exit();

/**
 * Returns parsed Gutenberg block structure for a post.
 */
final class ReadBlockTree
{
    /**
     * Register the ability.
     */
    public function register(): void
    {
        wp_register_ability('awpt/read-block-tree', [
            'label' => __('Read Block Tree', 'agent-wordpress-terminal'),
            'description' => __('Returns parsed Gutenberg block structure for a post.', 'agent-wordpress-terminal'),
            'category' => 'awpt',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => __('Post ID.', 'agent-wordpress-terminal'),
                    ],
                ],
                'required' => ['id'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'blocks' => ['type' => 'array'],
                    'count' => ['type' => 'integer'],
                ],
            ],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => true,
                    'destructive' => false,
                ],
            ],
        ]);
    }

    /**
     * Permission check.
     *
     * @param array<string, mixed> $input Ability input.
     */
    public function can_read(array $input): bool
    {
        $post_id = (int) ($input['id'] ?? 0);

        return $post_id > 0 && current_user_can('read_post', $post_id);
    }

    /**
     * Execute the ability.
     *
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error
    {
        $post_id = (int) ($input['id'] ?? 0);
        $post = get_post($post_id);

        if (!$post) {
            return new \WP_Error('awpt_post_not_found', __('Post not found.', 'agent-wordpress-terminal'));
        }

        $blocks = parse_blocks((string) $post->post_content);

        return [
            'blocks' => $this->normalize_blocks($blocks),
            'count' => $this->count_blocks($blocks),
        ];
    }

    /**
     * Normalize blocks for agent consumption.
     *
     * @param array<int, array<string, mixed>> $blocks Parsed blocks.
     * @return array<int, array<string, mixed>>
     */
    private function normalize_blocks(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $block) {
            if (!\AWPT\Support\BlockTree::has_block_name($block)) {
                continue;
            }

            $normalized[] = [
                'name' => $block['blockName'],
                'attributes' => $block['attrs'] ?? [],
                'inner' => $this->normalize_blocks($block['innerBlocks'] ?? []),
            ];
        }

        return $normalized;
    }

    /**
     * Count blocks recursively.
     *
     * @param array<int, array<string, mixed>> $blocks Parsed blocks.
     */
    private function count_blocks(array $blocks): int
    {
        $count = 0;

        foreach ($blocks as $block) {
            if (!\AWPT\Support\BlockTree::has_block_name($block)) {
                continue;
            }

            ++$count;
            $count += $this->count_blocks($block['innerBlocks'] ?? []);
        }

        return $count;
    }
}
