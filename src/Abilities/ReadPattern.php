<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AgentFeedback;
use AWPT\Domain\PatternTemplateExpander;
use AWPT\Support\BlockTree;
use AWPT\Support\PatternCatalog;

if (!defined('ABSPATH')) {
    exit();
}

/** Reads a pattern's raw block composition before the agent reuses it. */
final class ReadPattern implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-pattern',
            'label' => __('Read Pattern', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads one registered or reusable pattern with its Gutenberg block tree.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'purpose' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional concrete layout role or compatibility question this read should answer.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['name'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_read(array $input): bool {
        unset($input);
        return current_user_can('edit_posts');
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        $name = sanitize_text_field((string) ($input['name'] ?? ''));
        $catalog = new PatternCatalog();
        $resolved = $catalog->resolve_name($name);

        if (null === $resolved) {
            $suggestions = $catalog->suggestions($name, 8);

            return new \WP_Error(
                'awpt_pattern_not_found',
                __(
                    'Pattern not found. Use an exact name from awpt/list-patterns (do not invent slugs).',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 404,
                    'requested_name' => $name,
                    'suggested_patterns' => array_map(static fn(array $item): array => [
                        'name' => (string) ($item['name'] ?? ''),
                        'title' => (string) ($item['title'] ?? ''),
                        'owner' => (string) ($item['owner'] ?? ''),
                    ], $suggestions),
                    'recommended_next_tools' => [
                        [
                            'tool' => 'awpt/list-patterns',
                            'input' => ['search' => '', 'max' => 24],
                        ],
                    ],
                ],
            );
        }

        $pattern = $resolved['pattern'];
        $canonical = $resolved['resolved_name'];
        $source_content = (string) ($pattern['content'] ?? '');
        $expanded = new PatternTemplateExpander($catalog)->expand($canonical);
        $content = is_wp_error($expanded) ? $source_content : $expanded;
        $tree = BlockTree::from_content($content);
        $summary = $catalog->summary($pattern);
        $domain = is_array($summary['domain'] ?? null) ? $summary['domain'] : [];

        return array_merge($summary, [
            'content' => $content,
            'source_content' => $source_content,
            'content_mode' => is_wp_error($expanded) ? 'source' : 'expanded_editable',
            'resolved_from' => $resolved['resolved_from'],
            'blocks' => $tree->normalized(),
            'design_dependencies' => $catalog->design_dependencies($content),
            'adaptation_contract' => [
                'preferred_mode' => 'materialized',
                'authoring' => __(
                    'For ordinary pages, use exact pattern_replacements against this expanded editable content and append only genuinely new blocks. Use adapted mode when a complete custom document is warranted.',
                    'agent-wordpress-terminal',
                ),
                'structural_dependencies' => is_array($domain['required_blocks'] ?? null)
                    ? $domain['required_blocks']
                    : [],
                'server_behavior' => __(
                    'If an adapted draft omits a declared structural dependency entirely, AWPT restores the exact source block before validation. A draft without pattern_name remains fully freeform.',
                    'agent-wordpress-terminal',
                ),
            ],
            'agent_feedback' => AgentFeedback::make(
                'ready',
                __('The exact pattern structure is available for adaptation or insertion.', 'agent-wordpress-terminal'),
                [
                    'next_actions' => [[
                        'ability' => 'awpt/validate-composition',
                        'reason' => __(
                            'Validate the complete adapted composition before staging.',
                            'agent-wordpress-terminal',
                        ),
                        'input' => [
                            'content' => '<complete adapted block markup>',
                            'pattern_name' => $name,
                        ],
                    ]],
                ],
            ),
        ]);
    }
}
