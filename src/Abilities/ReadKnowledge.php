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
final class ReadKnowledge
{
    public function register(): void
    {
        wp_register_ability('awpt/read-knowledge', [
            'label' => __('Read Knowledge', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads a specific Core Knowledge or legacy guideline record by WordPress post ID.',
                'agent-wordpress-terminal',
            ),
            'category' => 'awpt',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => __('Knowledge post ID.', 'agent-wordpress-terminal'),
                    ],
                ],
                'required' => ['id'],
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
     * @param array<string, mixed> $input Ability input.
     */
    public function can_read(array $input): bool
    {
        $post_id = (int) ($input['id'] ?? 0);

        return $post_id > 0 && current_user_can('read_post', $post_id);
    }

    /**
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error
    {
        $source = (new KnowledgeRepository())->read_knowledge_post((int) ($input['id'] ?? 0));

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
}
