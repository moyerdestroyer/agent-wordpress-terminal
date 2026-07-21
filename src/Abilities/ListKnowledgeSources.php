<?php

/**
 * awpt/list-knowledge-sources ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\KnowledgeIndexRepository;
use AWPT\Knowledge\KnowledgeIndexer;
use AWPT\Knowledge\KnowledgeIndexerStatus;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lists what the local Knowledge index contains so the agent can discover paths.
 */
final class ListKnowledgeSources implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/list-knowledge-sources',
            'label' => __('List Knowledge Sources', 'agent-wordpress-terminal'),
            'description' => __(
                'Summarizes the local Knowledge index: source counts by kind, stale flag, and sample labels (e.g. theme:docs/...). Use before search-knowledge when you need to see what is indexed on this site.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sample' => [
                        'type' => 'integer',
                        'description' => __(
                            'Max sample labels to return (default 16, max 50).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'kind' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional source_kind filter (filesystem, wp_content, core_knowledge, …).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_list'],
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
    public function can_list(array $input): bool {
        unset($input);

        return current_user_can('edit_posts');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array {
        $sample = max(1, min(50, (int) ($input['sample'] ?? 16)));
        $kind = sanitize_key((string) ($input['kind'] ?? ''));
        $status = new KnowledgeIndexerStatus()->build();
        $index = new KnowledgeIndexRepository();

        return [
            'retrieval_available' => KnowledgeIndexer::retrieval_is_available(),
            'stale' => (bool) ($status['stale'] ?? false),
            'needs_rebuild' => (bool) ($status['needs_rebuild'] ?? false),
            'source_count' => (int) ($status['source_count'] ?? 0),
            'chunk_count' => (int) ($status['chunk_count'] ?? 0),
            'source_kinds' => is_array($status['source_kinds'] ?? null) ? $status['source_kinds'] : [],
            'last_indexed_at' => (string) ($status['last_indexed_at'] ?? ''),
            'samples' => $index->sample_source_labels($sample, $kind),
            'hint' => __(
                'Search with awpt/search-knowledge using tokens from sample labels (e.g. theme:docs or a path fragment). Then awpt/read-theme-file with a relative path from a theme: label.',
                'agent-wordpress-terminal',
            ),
        ];
    }
}
