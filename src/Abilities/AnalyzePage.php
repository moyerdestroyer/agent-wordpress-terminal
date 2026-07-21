<?php

/**
 * awpt/analyze-page ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns an agent-friendly page brief.
 */
final class AnalyzePage implements AbilityInterface {
    /**
     * Register the ability.
     */
    public function register(): void {
        wp_register_ability('awpt/analyze-page', [
            'label' => __('Analyze Page', 'agent-wordpress-terminal'),
            'description' => __(
                'Returns an agent-friendly page brief with structure and risk signals.',
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
     * Permission check.
     *
     * @param array<string, mixed> $input Ability input.
     */
    public function can_read(array $input): bool {
        $post_id = (int) ($input['id'] ?? 0);

        return $post_id > 0 && current_user_can('read_post', $post_id);
    }

    /**
     * Execute the ability.
     *
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $post_id = (int) ($input['id'] ?? 0);
        $post = get_post($post_id);

        if (!$post) {
            return new \WP_Error('awpt_post_not_found', __('Post not found.', 'agent-wordpress-terminal'));
        }

        $blocks = parse_blocks($post->post_content);
        $block_tree = new ReadBlockTree()->execute(['id' => $post_id]);
        $plain_text = wp_strip_all_tags($post->post_content);
        $headings = $this->extract_headings($blocks);
        $shortcodes = $this->extract_shortcodes($post->post_content);
        $forms = $this->detect_forms($blocks);
        $custom = $this->detect_custom_blocks($blocks);
        $risk_level = $this->assess_risk($forms, $shortcodes, $custom);

        if (is_wp_error($block_tree)) {
            return $block_tree;
        }

        return [
            'title' => get_the_title($post),
            'status' => $post->post_status,
            'url' => get_permalink($post),
            'plain_text' => $plain_text,
            'block_tree' => $block_tree['blocks'],
            'headings' => $headings,
            'shortcodes' => $shortcodes,
            'forms' => $forms,
            'custom_blocks' => $custom,
            'risk_level' => $risk_level,
            'recommended_next_actions' => $this->recommend_actions($risk_level, $headings),
        ];
    }

    /**
     * Extract heading blocks.
     *
     * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
     * @return array<int, string>
     */
    private function extract_headings(array $blocks): array {
        $headings = [];

        foreach ($blocks as $block) {
            if (str_starts_with((string) ($block['blockName'] ?? ''), 'core/heading')) {
                $headings[] = wp_strip_all_tags((string) ($block['innerHTML'] ?? ''));
            }

            $headings = array_merge(
                $headings,
                $this->extract_headings(\AWPT\Support\ArrayKey::list_of_maps($block['innerBlocks'] ?? null)),
            );
        }

        return array_values(array_filter($headings));
    }

    /**
     * Extract shortcode names from content.
     *
     * @param string $content Post content.
     * @return array<int, string>
     */
    private function extract_shortcodes(string $content): array {
        $matches = [];
        preg_match_all('/\[(\w+)/', $content, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Detect form-like blocks.
     *
     * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
     * @return array<int, string>
     */
    private function detect_forms(array $blocks): array {
        $forms = [];

        foreach ($blocks as $block) {
            $name = (string) ($block['blockName'] ?? '');

            if (str_contains($name, 'form') || str_contains($name, 'contact')) {
                $forms[] = $name;
            }

            $forms = array_merge(
                $forms,
                $this->detect_forms(\AWPT\Support\ArrayKey::list_of_maps($block['innerBlocks'] ?? null)),
            );
        }

        return array_values(array_unique($forms));
    }

    /**
     * Detect non-core blocks.
     *
     * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
     * @return array<int, string>
     */
    private function detect_custom_blocks(array $blocks): array {
        $custom = [];

        foreach ($blocks as $block) {
            $name = (string) ($block['blockName'] ?? '');

            if ('' !== $name && !str_starts_with($name, 'core/')) {
                $custom[] = $name;
            }

            $custom = array_merge(
                $custom,
                $this->detect_custom_blocks(\AWPT\Support\ArrayKey::list_of_maps($block['innerBlocks'] ?? null)),
            );
        }

        return array_values(array_unique($custom));
    }

    /**
     * Assess page risk level.
     *
     * @param array<int, string> $forms Form blocks.
     * @param array<int, string> $shortcodes Shortcodes.
     * @param array<int, string> $custom Custom blocks.
     */
    private function assess_risk(array $forms, array $shortcodes, array $custom): string {
        if ([] !== $forms || count($shortcodes) > 2) {
            return 'medium';
        }

        if ([] !== $custom) {
            return 'low';
        }

        return 'low';
    }

    /**
     * Suggest next actions based on analysis.
     *
     * @param string               $risk_level Risk level.
     * @param array<int, string>   $headings Headings found.
     * @return array<int, string>
     */
    private function recommend_actions(string $risk_level, array $headings): array {
        $actions = [
            __('Review block structure for layout improvements.', 'agent-wordpress-terminal'),
        ];

        if ([] === $headings) {
            $actions[] = __('Add headings to improve page structure.', 'agent-wordpress-terminal');
        }

        if ('medium' === $risk_level) {
            $actions[] = __('Verify forms and shortcodes before making layout changes.', 'agent-wordpress-terminal');
        }

        return $actions;
    }
}
