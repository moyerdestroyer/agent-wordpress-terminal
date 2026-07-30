<?php

/**
 * awpt/read-knowledge ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Knowledge\KnowledgeRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads one Core Knowledge or legacy guideline post.
 */
final class ReadKnowledge implements AbilityInterface {
    public function register(): void {
        wp_register_ability('awpt/read-knowledge', [
            'label' => __('Read Knowledge', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads a Core Knowledge or legacy guideline WordPress post by post ID. Use source_post_id from search-knowledge for core_knowledge/legacy_guideline hits — not the search result id (that is a chunk row id). For site content use read-content; for theme docs use read-theme-file.',
                'agent-wordpress-terminal',
            ),
            'category' => 'awpt',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => __(
                            'WordPress post ID of a Core Knowledge or legacy guideline record (search-knowledge field source_post_id). Do not pass the search result id/chunk id.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'source_post_id' => [
                        'type' => 'integer',
                        'description' => __(
                            'Alias for id. Prefer this when copying from a search-knowledge hit.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                // Accept either id or source_post_id; execute validates a positive post ID.
            ],
            'output_schema' => [
                'type' => 'object',
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
     * Capability gate only. Missing/invalid IDs and unreadable posts are handled in
     * execute so the agent gets "not found" / "wrong type" instead of a false permission denial.
     *
     * @param array<string, mixed> $input Ability input.
     */
    public function can_read(array $input): bool {
        unset($input);

        return current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $post_id = $this->resolve_post_id($input);

        if ($post_id <= 0) {
            return new \WP_Error(
                'awpt_invalid_knowledge_id',
                __(
                    'A positive WordPress post ID is required. Use source_post_id from an awpt/search-knowledge hit for core_knowledge or legacy_guideline sources — not the search result id (chunk row id). Site content uses awpt/read-content; theme docs use awpt/read-theme-file.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 400],
            );
        }

        $source = new KnowledgeRepository()->read_knowledge_post($post_id);

        if (is_wp_error($source)) {
            return $source;
        }

        return [
            'id' => (int) ($source['post_id'] ?? 0),
            'source_kind' => (string) ($source['kind'] ?? ''),
            'label' => (string) ($source['label'] ?? ''),
            'uri' => (string) ($source['uri'] ?? ''),
            'content' => (string) ($source['content'] ?? ''),
            'metadata' => is_array($source['metadata'] ?? null) ? $source['metadata'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $input Ability input.
     */
    private function resolve_post_id(array $input): int {
        $id = (int) ($input['id'] ?? 0);

        if ($id > 0) {
            return $id;
        }

        return (int) ($input['source_post_id'] ?? 0);
    }
}
