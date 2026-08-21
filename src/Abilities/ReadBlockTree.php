<?php

/**
 * awpt/read-block-tree ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Support\ArrayKey;
use AWPT\Support\BlockTree;
use AWPT\Support\BlockTreeView;
use AWPT\Support\PageSectionModel;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns parsed Gutenberg block structure for a post.
 */
final class ReadBlockTree implements AbilityInterface {
    /**
     * Register the ability.
     */
    public function register(): void {
        wp_register_ability('awpt/read-block-tree', [
            'label' => __('Read Block Tree', 'agent-wordpress-terminal'),
            'description' => __(
                'Returns parsed Gutenberg block structure, top-level sections, fingerprints, and a compact page brief (title, status, url). Use awpt/get-block for one leaf’s inner HTML.',
                'agent-wordpress-terminal',
            ),
            'category' => 'awpt',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => __('Post ID.', 'agent-wordpress-terminal'),
                    ],
                    'action_id' => [
                        'type' => 'integer',
                        'description' => __(
                            'Optional internal staged candidate ID. When present, reads its proposed post_content instead of the live post.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'path' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional dotted path of one complete subtree to return (e.g. 13). Prefer this over re-reading the whole page.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'paths' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => __(
                            'Optional list of dotted paths. Each returned node includes its children.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'blocks' => ['type' => 'array'],
                    'count' => ['type' => 'integer'],
                    'path_format' => ['type' => 'string'],
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
    public function can_read(array $input): bool {
        $post_id = $this->post_id($input);

        return $post_id > 0 && current_user_can('read_post', $post_id);
    }

    /**
     * Execute the ability.
     *
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $action_id = (int) ($input['action_id'] ?? 0);
        $post_id = $this->post_id($input);
        $post = get_post($post_id);

        if (!$post) {
            return new \WP_Error('awpt_post_not_found', __('Post not found.', 'agent-wordpress-terminal'));
        }

        $content = $post->post_content;

        if ($action_id > 0) {
            $action = new ActionRepository()->format_action($action_id);
            $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];
            $content = (string) ($payload['post_content'] ?? '');

            if ('' === $content) {
                return new \WP_Error(
                    'awpt_candidate_content_missing',
                    __('The staged candidate does not contain readable post content.', 'agent-wordpress-terminal'),
                    ['status' => 409],
                );
            }
        }

        $tree = BlockTree::from_content($content);
        $normalized = $tree->normalized();
        $requested = $this->requested_paths($input);

        if ([] !== $requested) {
            $view = new BlockTreeView();
            $subtrees = $view->subtrees_at_paths(ArrayKey::list_of_maps($normalized), $requested);

            if (count($subtrees) !== count($requested)) {
                return new \WP_Error(
                    'awpt_block_not_found',
                    __('A requested block path was not found.', 'agent-wordpress-terminal'),
                    ['paths' => $requested],
                );
            }

            $path_set = array_fill_keys($requested, true);
            $top_level = array_values(array_filter(
                PageSectionModel::from_tree($tree, [
                    'title' => $post->post_title,
                    'post_type' => $post->post_type,
                ]),
                static fn(mixed $section): bool => isset($path_set[(string) ($section['path'] ?? '')]),
            ));

            return [
                'blocks' => $subtrees,
                'count' => count($subtrees),
                'path_format' => __('Dotted zero-based visible block path, e.g. 0 or 2.1.', 'agent-wordpress-terminal'),
                'top_level_sections' => $top_level,
                'requested_paths' => $requested,
                'action_id' => $action_id > 0 ? $action_id : null,
            ];
        }

        $top_level = PageSectionModel::from_tree($tree, [
            'title' => $post->post_title,
            'post_type' => $post->post_type,
        ]);

        return [
            'blocks' => $normalized,
            'count' => $tree->count(),
            'path_format' => __('Dotted zero-based visible block path, e.g. 0 or 2.1.', 'agent-wordpress-terminal'),
            'top_level_sections' => $top_level,
            'title' => get_the_title($post),
            'status' => $post->post_status,
            'url' => get_permalink($post),
            'action_id' => $action_id > 0 ? $action_id : null,
            'prepare_pattern_change_hint' => __(
                'To swap a section, call awpt/propose-pattern-replace with path and intent (the server prepares). Optional: awpt/prepare-pattern-change to preview slots first.',
                'agent-wordpress-terminal',
            ),
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

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function requested_paths(array $input): array {
        $paths = [];
        $single = trim((string) ($input['path'] ?? ''));

        if ('' !== $single) {
            $paths[] = $single;
        }

        foreach (is_array($input['paths'] ?? null) ? $input['paths'] : [] as $path) {
            $path = trim((string) $path);

            if ('' !== $path) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }
}
