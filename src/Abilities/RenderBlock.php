<?php

/**
 * awpt/render-block ability.
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
 * Renders one block or a full post to HTML via render_block().
 */
final class RenderBlock {
    private const MAX_HTML = 12_000;

    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/render-block',
            'label' => __('Render Block', 'agent-wordpress-terminal'),
            'description' => __(
                'Renders a Gutenberg block (or the full post when path is omitted) to HTML using WordPress render_block().',
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
                            'Optional dotted block path. Omit to render all top-level blocks.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['id'],
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

        if (!function_exists('render_block')) {
            return new \WP_Error('awpt_render_unavailable', __(
                'Block rendering is not available on this site.',
                'agent-wordpress-terminal',
            ));
        }

        $tree = BlockTree::from_content((string) $post->post_content);
        $html = '';
        $block_name = null;
        $rendered = 0;

        if ('' !== $path) {
            $block = $tree->get_block($path);

            if (null === $block) {
                return new \WP_Error(
                    'awpt_block_not_found',
                    __('Block path was not found.', 'agent-wordpress-terminal'),
                    ['status' => 404],
                );
            }

            $html = $this->render_one($block);
            $block_name = is_string($block['blockName'] ?? null) ? $block['blockName'] : null;
            $rendered = 1;
        } else {
            foreach ($tree->blocks() as $block) {
                if (!BlockTree::has_block_name($block)) {
                    continue;
                }

                $html .= $this->render_one($block);
                ++$rendered;
            }
        }

        $truncated = false;

        if (strlen($html) > self::MAX_HTML) {
            $html = substr($html, 0, self::MAX_HTML) . "\n<!-- awpt:truncated -->";
            $truncated = true;
        }

        return [
            'id' => $post_id,
            'path' => '' !== $path ? $path : null,
            'block_name' => $block_name,
            'rendered_blocks' => $rendered,
            'html' => $html,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param array<string, mixed> $block
     */
    private function render_one(array $block): string {
        /** @var array{attrs?: array<array-key, mixed>, blockName?: null|string, innerBlocks?: array<array-key, array<array-key, mixed>>, innerContent?: array<array-key, mixed>, innerHTML?: string} $for_render */
        $for_render = $block;

        return render_block($for_render);
    }
}
