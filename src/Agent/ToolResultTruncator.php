<?php

/**
 * Bounds tool output size for provider context and persistence.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ArrayKey;
use AWPT\Support\PatternCandidateProjector;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Truncates large ability outputs while preserving structured summaries.
 */
final class ToolResultTruncator {
    private const PROVIDER_MAX_CHARS = 12_000;
    private const GET_BLOCK_PROVIDER_MAX_CHARS = 24_000;
    /** Review-queue trees (20k) must reach the model complete; outline stubs caused bad plans. */
    private const BLOCK_TREE_PROVIDER_MAX_CHARS = 64_000;
    private const STORAGE_MAX_CHARS = 64_000;
    private const META_VALUE_MAX_CHARS = 4_096;

    public function for_provider(string $tool, mixed $output): mixed {
        if ('awpt/read-pattern' === $tool && is_array($output)) {
            // Raw content is what the model adapts. The normalized tree repeats
            // the same composition and can more than double prompt size.
            unset($output['blocks']);
        }

        $max_chars = match ($tool) {
            'awpt/get-block' => self::GET_BLOCK_PROVIDER_MAX_CHARS,
            'awpt/read-block-tree', 'awpt/analyze-page' => self::BLOCK_TREE_PROVIDER_MAX_CHARS,
            default => self::PROVIDER_MAX_CHARS,
        };

        return $this->truncate($tool, $output, $max_chars, 'provider');
    }

    public function for_storage(string $tool, mixed $output): mixed {
        return $this->truncate($tool, $output, self::STORAGE_MAX_CHARS, 'storage');
    }

    /**
     * @param 'provider'|'storage' $channel
     */
    private function truncate(string $tool, mixed $output, int $max_chars, string $channel): mixed {
        if (ToolRegistry::is_proposal_ability($tool) && is_array($output)) {
            // The complete proposal remains available to the action card and
            // Tools UI through the storage channel. Repeating original and
            // candidate post_content in every provider round can add hundreds
            // of kilobytes to an otherwise small review loop.
            return 'storage' === $channel ? $output : $this->proposal_checkpoint($tool, $output);
        }

        if (is_string($output)) {
            return $this->clip_string($output, $max_chars);
        }

        if (!is_array($output)) {
            return $output;
        }

        $output = $this->shrink($tool, $output, $channel);
        $encoded = (string) wp_json_encode($output);

        if (mb_strlen($encoded, 'UTF-8') <= $max_chars) {
            return $output;
        }

        if ('awpt/read-block-tree' === $tool) {
            return $this->slice_complete_block_tree(ArrayKey::string_map($output), $max_chars, strlen($encoded));
        }

        if ('awpt/analyze-page' === $tool && is_array($output['block_tree'] ?? null)) {
            unset($output['plain_text']);
            $encoded = (string) wp_json_encode($output);

            if (mb_strlen($encoded, 'UTF-8') <= $max_chars) {
                return $output;
            }

            $sliced = $this->slice_complete_block_tree(
                [
                    'blocks' => $output['block_tree'],
                    'count' => $output['count'] ?? count($output['block_tree']),
                ],
                $max_chars,
                strlen($encoded),
            );
            $output['block_tree'] = $sliced['blocks'] ?? [];
            $output['truncated'] = true;
            $output['remaining_paths'] = $sliced['remaining_paths'] ?? [];
            $output['next'] = $sliced['next'] ?? '';
            unset($output['block_tree_flat_index']);

            return $output;
        }

        return $this->build_summary($tool, $output, strlen($encoded));
    }

    /**
     * @param array<array-key, mixed> $output
     * @param 'provider'|'storage'    $channel
     * @return array<array-key, mixed>
     */
    private function shrink(string $tool, array $output, string $channel): array {
        if ('provider' === $channel && 'awpt/recommend-patterns' === $tool) {
            $output['recommendations'] = new PatternCandidateProjector()->many(
                ArrayKey::list_of_maps($output['recommendations'] ?? null),
                6,
                9_000,
            );
            $output['provider_projection'] = 'pattern_candidates_v1';
        }

        if ('awpt/finalize-proposal-review' === $tool) {
            // Finalization is a control-plane receipt. The accepted action can
            // be very large and is reloaded from ActionRepository by ID; never
            // let it obscure the decision fields in provider or stored output.
            unset($output['action']);
            $output['summary'] = $this->clip_string((string) ($output['summary'] ?? ''), 1_500);
        }

        if (in_array($tool, ['awpt/read-content', 'core/read-content'], true)) {
            $output['content'] = $this->clip_string((string) ($output['content'] ?? ''), 6_000);
            $output['content_raw'] = $this->clip_string((string) ($output['content_raw'] ?? ''), 6_000);
            $output['content_rendered'] = $this->clip_string((string) ($output['content_rendered'] ?? ''), 6_000);
            $output['plain_text'] = $this->clip_string((string) ($output['plain_text'] ?? ''), 4_000);
            $output['meta'] = $this->shrink_meta_map($output['meta'] ?? []);
        }

        if ('awpt/read-attachment-document' === $tool) {
            $output['content'] = $this->clip_string((string) ($output['content'] ?? ''), 10_000);
        }

        if ('awpt/read-block-tree' === $tool) {
            $blocks = is_array($output['blocks'] ?? null) ? $output['blocks'] : [];
            $compact = new \AWPT\Support\BlockTreeView()->compact_for_evidence(
                \AWPT\Support\ArrayKey::list_of_maps($blocks),
                self::BLOCK_TREE_PROVIDER_MAX_CHARS - 2_000,
                false,
            );
            $output['blocks'] = $compact['blocks'];
            $output['count'] = (int) $compact['count'];

            if (true === ($compact['flat_index'] ?? false)) {
                $output['flat_index'] = true;
            }
        }

        if ('awpt/analyze-page' === $tool) {
            $blocks = is_array($output['block_tree'] ?? null) ? $output['block_tree'] : [];

            if ([] !== $blocks) {
                $compact = new \AWPT\Support\BlockTreeView()->compact_for_evidence(
                    \AWPT\Support\ArrayKey::list_of_maps($blocks),
                    self::BLOCK_TREE_PROVIDER_MAX_CHARS - 4_000,
                    false,
                );
                $output['block_tree'] = $compact['blocks'];

                if (true === ($compact['flat_index'] ?? false)) {
                    $output['block_tree_flat_index'] = true;
                }
            }

            // Prefer the tree. Clip prose only after children are kept.
            $output['plain_text'] = $this->clip_string((string) ($output['plain_text'] ?? ''), 8_000);

            foreach (['headings', 'shortcodes', 'forms', 'custom_blocks', 'recommended_next_actions'] as $key) {
                if (!array_key_exists($key, $output)) {
                    continue;
                }

                $output[$key] = $this->clip_array_items($output[$key] ?? [], 24);
            }
        }

        if ('awpt/read-proposal' === $tool) {
            $payload = is_array($output['payload'] ?? null) ? $output['payload'] : [];
            $output['payload'] = array_intersect_key($payload, array_flip([
                'operation',
                'post_id',
                'post_title',
                'post_type',
                'post_status',
                'pattern_name',
                'pattern_mode',
                'featured_image_id',
                'required_attachment_ids',
                'required_minimum_library_images',
                'required_minimum_visuals',
            ]));
            unset($output['removed_action_ids']);
        }

        if ('awpt/list-content' === $tool) {
            $output['items'] = $this->clip_array_items($output['items'] ?? [], 25);
        }

        if ('awpt/list-patterns' === $tool) {
            $output['patterns'] = $this->clip_array_items($output['patterns'] ?? [], 24);
            $output['suggested_patterns'] = $this->clip_array_items($output['suggested_patterns'] ?? [], 12);
        }

        if (in_array($tool, ['awpt/search-knowledge', 'awpt/knowledge-auto-retrieval'], true)) {
            if (array_key_exists('results', $output)) {
                $output['results'] = $this->clip_array_items($output['results'], 8);
            }

            if (array_key_exists('items', $output)) {
                $output['items'] = $this->clip_array_items($output['items'], 8);
            }

            if (array_key_exists('known_matches', $output)) {
                $output['known_matches'] = $this->clip_array_items($output['known_matches'], 4);
            }
        }

        if ('awpt/read-theme-file' === $tool) {
            // Never ship a full minified stylesheet into the model or transcript.
            $output['content'] = $this->clip_string((string) ($output['content'] ?? ''), 4_000);
            $output['matches'] = $this->clip_array_items($output['matches'] ?? [], 6);
            unset($output['absolute_path']);
            $output['note'] = $this->clip_string((string) ($output['note'] ?? ''), 500);
        }

        if ('awpt/inspect-frontend' === $tool) {
            $output['html_snippet'] = $this->clip_string((string) ($output['html_snippet'] ?? ''), 2_500);
            $output['body_excerpt'] = $this->clip_string((string) ($output['body_excerpt'] ?? ''), 600);
            $output['class_inventory'] = $this->clip_array_items($output['class_inventory'] ?? [], 16);
            $output['stylesheets'] = $this->clip_array_items($output['stylesheets'] ?? [], 12);
        }

        if ('awpt/inspect-rendered-element' === $tool) {
            // The screenshot is delivered as a separate multimodal evidence
            // message; never duplicate its base64 payload in JSON tool output.
            unset($output['screenshot_data']);
            $output['elements'] = $this->clip_array_items($output['elements'] ?? [], 24);
            $output['html_snippet'] = $this->clip_string((string) ($output['html_snippet'] ?? ''), 2_000);
        }

        if ('awpt/list-knowledge-sources' === $tool) {
            $output['samples'] = $this->clip_array_items($output['samples'] ?? [], 16);
        }

        return $output;
    }

    /**
     * Deterministic provider-facing receipt for a staged proposal.
     *
     * @param array<array-key, mixed> $output
     * @return array<string, mixed>
     */
    private function proposal_checkpoint(string $tool, array $output): array {
        $payload = ArrayKey::as_map($output['payload'] ?? null);
        $candidate = (string) ($payload['post_content'] ?? '');
        $original = (string) ($payload['original_post_content'] ?? '');
        $payload_summary = [];

        foreach ([
            'operation',
            'post_id',
            'post_title',
            'post_type',
            'post_status',
            'pattern_name',
            'pattern_mode',
            'pattern_title',
            'block_path',
            'expected_fingerprint',
            'affected',
            'presentation_requires_h1',
            'featured_image_id',
            'required_attachment_ids',
            'required_minimum_library_images',
            'required_minimum_visuals',
        ] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $payload_summary[$key] = $payload[$key];
        }

        foreach ([
            'replaced_paths',
            'inserted_paths',
            'batch_changes',
            'repairs_applied',
            'validation_findings',
        ] as $key) {
            if (!is_array($payload[$key] ?? null)) {
                continue;
            }

            $payload_summary[$key] = $this->compact_proposal_items($payload[$key]);
        }

        if (is_array($payload['agent_feedback'] ?? null)) {
            $payload_summary['agent_feedback'] = $payload['agent_feedback'];
        }

        $checkpoint = [
            'tool' => $tool,
            'provider_projection' => 'proposal_checkpoint_v1',
            'id' => (int) ($output['id'] ?? $output['action_id'] ?? 0),
            'title' => $this->clip_string((string) ($output['title'] ?? ''), 500),
            'description' => $this->clip_string((string) ($output['description'] ?? ''), 1_500),
            'status' => (string) ($output['status'] ?? ''),
            'payload' => $payload_summary,
            'candidate' => $this->content_checkpoint($candidate),
            'original' => $this->content_checkpoint($original),
            'full_payload' => [
                'stored' => true,
                'inspect_with' => 'awpt/read-proposal',
            ],
        ];

        foreach (['revised_action_id', 'revision_kind', 'removed_action_ids', 'revision_context'] as $key) {
            if (!array_key_exists($key, $output)) {
                continue;
            }

            $checkpoint[$key] = $output[$key];
        }

        return $checkpoint;
    }

    /**
     * @param array<array-key, mixed> $items
     * @return list<mixed>
     */
    private function compact_proposal_items(array $items): array {
        $compacted = [];

        foreach (array_slice(array_values($items), 0, 100) as $item) {
            if (!is_array($item)) {
                $compacted[] = is_string($item) ? $this->clip_string($item, 500) : $item;
                continue;
            }

            $entry = array_intersect_key($item, array_flip([
                'kind',
                'block_path',
                'path',
                'expected_fingerprint',
                'fingerprint',
                'name',
                'slot_id',
                'position',
                'attachment_id',
                'code',
                'severity',
                'message',
            ]));
            if (is_string($entry['message'] ?? null)) {
                $entry['message'] = $this->clip_string($entry['message'], 500);
            }
            $compacted[] = $entry;
        }

        return $compacted;
    }

    /** @return array{present: bool, sha256: string, bytes: int, blocks: int, words: int} */
    private function content_checkpoint(string $content): array {
        $plain = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($content)));

        return [
            'present' => '' !== $content,
            'sha256' => '' !== $content ? hash('sha256', $content) : '',
            'bytes' => strlen($content),
            'blocks' => '' !== $content
                ? new \AWPT\Support\BlockTreeView()->count(\AWPT\Support\BlockTree::from_content($content)->blocks())
                : 0,
            'words' => '' !== $plain ? str_word_count($plain) : 0,
        ];
    }

    /**
     * @param array<array-key, mixed> $output
     * @return array<string, mixed>
     */
    private function build_summary(string $tool, array $output, int $original_bytes): array {
        $summary = [
            'tool' => $tool,
            'summary' => __('Tool output was truncated for size.', 'agent-wordpress-terminal'),
            'truncated' => true,
            'original_bytes' => $original_bytes,
        ];

        foreach ([
            'id',
            'action_id',
            'accepted',
            'decision',
            'post_id',
            'title',
            'type',
            'status',
            'url',
            'count',
            'total',
            'query',
            'error',
        ] as $key) {
            if (!array_key_exists($key, $output)) {
                continue;
            }

            $summary[$key] = $output[$key];
        }

        if (in_array($tool, ['awpt/read-content', 'core/read-content'], true)) {
            $summary['plain_text'] = $this->clip_string(
                (string) ($output['plain_text'] ?? $output['content_raw'] ?? $output['content_rendered'] ?? ''),
                1_500,
            );
        }

        if ('awpt/analyze-page' === $tool) {
            foreach (['headings', 'risk_level', 'recommended_next_actions'] as $key) {
                if (!array_key_exists($key, $output)) {
                    continue;
                }

                $summary[$key] = is_array($output[$key]) ? $this->clip_array_items($output[$key], 16) : $output[$key];
            }

            if (is_array($output['block_tree'] ?? null) && [] !== $output['block_tree']) {
                $summary['block_tree'] = $output['block_tree'];
            } else {
                $summary['plain_text'] = $this->clip_string((string) ($output['plain_text'] ?? ''), 800);
            }
        }

        if ('awpt/list-content' === $tool) {
            $summary['items'] = $this->clip_array_items($output['items'] ?? [], 10);
        }

        return $summary;
    }

    /**
     * Return complete top-level sections that fit, never an outline-only stub.
     *
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private function slice_complete_block_tree(array $output, int $max_chars, int $original_bytes): array {
        $blocks = \AWPT\Support\ArrayKey::list_of_maps($output['blocks'] ?? []);
        $sliced = new \AWPT\Support\BlockTreeView()->complete_sections_within_budget($blocks, max(
            2_000,
            $max_chars - 1_500,
        ));
        $remaining = array_values(array_filter($sliced['remaining_paths'], 'is_string'));
        $included = $sliced['blocks'];
        $next = '' === implode('', $remaining)
            ? ''
            : sprintf(
                /* translators: %s: comma-separated remaining block paths */
                __(
                    'These sections are complete (children and heading levels included). Call awpt/read-block-tree with path set to a remaining path (%s), or awpt/get-block on a named path.',
                    'agent-wordpress-terminal',
                ),
                implode(', ', $remaining),
            );

        $payload = [
            'blocks' => $included,
            'count' => count($included),
            'truncated' => [] !== $remaining,
            'original_bytes' => $original_bytes,
            'remaining_paths' => $remaining,
        ];

        if ('' !== $next) {
            $payload['next'] = $next;
        }

        foreach (['id', 'post_id', 'path_format'] as $key) {
            if (!array_key_exists($key, $output)) {
                continue;
            }

            $payload[$key] = $output[$key];
        }

        $sections = is_array($output['top_level_sections'] ?? null) ? $output['top_level_sections'] : [];

        if ([] !== $sections && [] !== $included) {
            $included_paths = [];

            foreach ($included as $block) {
                $included_paths[(string) ($block['path'] ?? '')] = true;
            }

            $payload['top_level_sections'] = array_values(array_filter(
                $sections,
                static fn(mixed $section): bool => (
                    is_array($section) && isset($included_paths[(string) ($section['path'] ?? '')])
                ),
            ));
        }

        return $payload;
    }

    /**
     * Whether a provider-facing read-block-tree result is a complete tree
     * (has children), not an outline stub that still needs remaining paths.
     */
    public static function provider_tree_is_complete(mixed $output): bool {
        if (!is_array($output)) {
            return false;
        }

        if (is_array($output['remaining_paths'] ?? null) && [] !== $output['remaining_paths']) {
            return false;
        }

        $blocks = $output['blocks'] ?? null;

        return is_array($blocks) && [] !== $blocks;
    }

    private function clip_string(string $value, int $max_chars): string {
        if (mb_strlen($value, 'UTF-8') <= $max_chars) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $max_chars - 1), 'UTF-8')) . '…';
    }

    /**
     * @return list<mixed>
     */
    private function clip_array_items(mixed $value, int $limit): array {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_slice($value, 0, $limit));
    }

    /**
     * @return array<string, mixed>
     */
    private function shrink_meta_map(mixed $meta): array {
        if (!is_array($meta)) {
            return [];
        }

        $trimmed = [];

        foreach ($meta as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $trimmed[$key] = $this->shrink_meta_value($value);
        }

        return $trimmed;
    }

    private function shrink_meta_value(mixed $value): mixed {
        if (is_string($value)) {
            return $this->clip_string($value, self::META_VALUE_MAX_CHARS);
        }

        if (!is_array($value)) {
            return $value;
        }

        $encoded = (string) wp_json_encode($value);

        if (mb_strlen($encoded, 'UTF-8') <= self::META_VALUE_MAX_CHARS) {
            return $value;
        }

        return $this->clip_string($encoded, self::META_VALUE_MAX_CHARS);
    }
}
