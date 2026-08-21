<?php

/**
 * Same-turn rendered verification for staged proposals.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Abilities\InspectRenderedElement;
use AWPT\Support\ArrayKey;
use AWPT\Support\BlockTree;

if (!defined('ABSPATH')) {
    exit();
}

final class ProposalPreviewVerifier {
    /**
     * @param array<int, array<string, mixed>> $proposal_calls
     * @return array{tool_call: array<string, mixed>, message: array<string, mixed>}|null
     */
    public function verify(array $proposal_calls): ?array {
        $proposal = end($proposal_calls);

        if (!is_array($proposal)) {
            return null;
        }

        $output = is_array($proposal['output'] ?? null) ? $proposal['output'] : [];
        $action_id = (int) ($output['id'] ?? 0);
        $payload = is_array($output['payload'] ?? null) ? $output['payload'] : [];
        /** @var array<string, mixed> $payload */
        $preview_url = (string) ($payload['preview_url'] ?? '');

        if ($action_id <= 0) {
            return null;
        }

        $input = [
            'action_id' => $action_id,
            'selector' => $this->selector_for_payload($payload),
            'include_screenshot' => true,
        ];
        $inspection = '' !== $preview_url ? new InspectRenderedElement()->execute($input) : null;
        $render_error = is_wp_error($inspection) ? $inspection->get_error_message() : '';
        $rendered = is_array($inspection) && true === ($inspection['rendered'] ?? false);
        $before = $this->document_metrics((string) ($payload['original_post_content'] ?? ''));
        $candidate_content = (string) ($payload['post_content'] ?? '');
        $candidate = $this->document_metrics($candidate_content);
        $candidate['actionable_findings'] = $this->actionable_findings($candidate_content);
        $comparison = [
            'action_id' => $action_id,
            'candidate_sha256' => hash('sha256', (string) ($payload['post_content'] ?? '')),
            'before' => $before,
            'candidate' => $candidate,
            'semantic_diff' => [
                'a_prefix_paragraphs' => [
                    'before' => (int) ($before['a_prefix_paragraphs'] ?? 0),
                    'candidate' => (int) ($candidate['a_prefix_paragraphs'] ?? 0),
                ],
                'removed_links' => array_values(array_diff(
                    ArrayKey::list_of_strings($before['links'] ?? null),
                    ArrayKey::list_of_strings($candidate['links'] ?? null),
                )),
                'added_links' => array_values(array_diff(
                    ArrayKey::list_of_strings($candidate['links'] ?? null),
                    ArrayKey::list_of_strings($before['links'] ?? null),
                )),
                'removed_numbers' => array_values(array_diff(
                    ArrayKey::list_of_strings($before['numbers'] ?? null),
                    ArrayKey::list_of_strings($candidate['numbers'] ?? null),
                )),
                'added_numbers' => array_values(array_diff(
                    ArrayKey::list_of_strings($candidate['numbers'] ?? null),
                    ArrayKey::list_of_strings($before['numbers'] ?? null),
                )),
            ],
            'rendered' => $rendered,
            'render_error' => $render_error,
        ];
        if (is_array($inspection)) {
            $comparison['rendered_evidence'] = new ToolResultTruncator()->for_provider(
                'awpt/inspect-rendered-element',
                $inspection,
            );
        }

        $verification_instruction = $rendered
            ? "Review this rendered evidence against the user's exact request."
            : 'A rendered screenshot was unavailable, so do not claim visual verification. The before/candidate metrics and any static heading outline remain authoritative semantic evidence.';
        $text =
            "Automatic internal Improve-candidate review packet:\n"
            . (string) wp_json_encode($comparison)
            . "\n"
            . $verification_instruction
            . ' Compare it with the approved plan and reviewer request. Check headings, short standalone labels/prefixes, raw HTML, links, numbers, and preserved copy. If it is not satisfactory, revise with the most targeted proposal tool and review the replacement candidate. If it is satisfactory, call awpt/finalize-proposal-review with decision accept. If it cannot be made satisfactory, call that tool with decision abandon. Do not finish with prose alone.';
        $visual = is_array($inspection) ? new RenderedInspectionVisualEvidence()->build($inspection) : null;
        $content = is_array($visual) && is_array($visual['content'] ?? null)
            ? [
                ['type' => 'text', 'text' => $text],
                ...$visual['content'],
            ]
            : $text;
        $provider_call_id = 'awpt_visual_verify_' . wp_generate_password(8, false);

        return [
            'tool_call' => [
                'tool' => 'awpt/inspect-rendered-element',
                'input' => $input,
                'output' => $comparison,
                'status' => 'success',
                'provider_call_id' => $provider_call_id,
            ],
            'message' => [
                'role' => 'user',
                'content' => $content,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function document_metrics(string $content): array {
        $plain = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($content)));
        $heading_matches = [];
        $paragraph_matches = [];
        $link_matches = [];
        $number_matches = [];
        preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $content, $heading_matches, PREG_SET_ORDER);
        preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $content, $paragraph_matches, PREG_SET_ORDER);
        preg_match_all('/\bhref=["\']([^"\']+)["\']/i', $content, $link_matches);
        preg_match_all('/(?<![\pL\pN])\$?\d[\d,.]*%?/u', $plain, $number_matches);
        $headings = [];
        $short_paragraphs = [];

        foreach (array_slice($heading_matches, 0, 80) as $heading) {
            $headings[] = [
                'level' => (int) ($heading[1] ?? 0),
                'text' => trim(wp_strip_all_tags($heading[2] ?? '')),
            ];
        }

        foreach ($paragraph_matches as $paragraph) {
            $text = trim(wp_strip_all_tags($paragraph[1] ?? ''));

            if ('' !== $text && mb_strlen($text) <= 80) {
                $short_paragraphs[] = $text;
            }
        }

        return [
            'block_count' => count(parse_blocks($content)),
            'word_count' => '' === $plain ? 0 : str_word_count($plain),
            'headings' => $headings,
            'short_paragraphs' => array_slice($short_paragraphs, 0, 80),
            'a_prefix_paragraphs' => count(array_filter(
                $short_paragraphs,
                static fn(string $text): bool => 1 === preg_match('/^A\s*:/i', $text),
            )),
            'html_block_count' => substr_count($content, '<!-- wp:html'),
            'links' => array_values(array_unique(array_slice($link_matches[1] ?? [], 0, 80))),
            'numbers' => array_values(array_unique(array_map(
                static fn(string $number): string => rtrim($number, '.,'),
                array_slice($number_matches[0] ?? [], 0, 80),
            ))),
        ];
    }

    /**
     * Return exact candidate paths/fingerprints for deterministic checks. The
     * model remains responsible for deciding whether each finding violates the
     * request; AWPT merely makes the evidence directly editable.
     *
     * @return list<array<string, mixed>>
     */
    private function actionable_findings(string $content): array {
        if ('' === trim($content)) {
            return [];
        }

        $tree = BlockTree::from_content($content);
        $findings = [];

        foreach ($tree->flat_list(null, 500) as $summary) {
            $path = (string) ($summary['path'] ?? '');
            $name = (string) ($summary['name'] ?? '');
            $text = trim((string) ($summary['text_excerpt'] ?? ''));
            $block = $tree->get_block($path);

            if (!is_array($block)) {
                continue;
            }

            $fingerprint = (string) ($summary['fingerprint'] ?? BlockTree::fingerprint($block));
            $attrs = ArrayKey::as_map($block['attrs'] ?? null);
            $children = ArrayKey::list_of_maps($block['innerBlocks'] ?? null);
            $base = [
                'path' => $path,
                'name' => $name,
                'fingerprint' => $fingerprint,
                'text_excerpt' => $text,
            ];

            if (1 === preg_match('/^A\s*:\s*$/iu', $text)) {
                $findings[] = ['kind' => 'standalone_answer_prefix', ...$base];
            } elseif (
                '' !== $text
                && mb_strlen($text, 'UTF-8') <= 24
                && 1 === preg_match('/^[\p{L}\p{N} .&\/-]+:\s*$/u', $text)
            ) {
                $findings[] = ['kind' => 'short_standalone_label', ...$base];
            }

            if ('core/heading' === $name) {
                $findings[] = [
                    'kind' => 'heading',
                    'level' => max(1, min(6, (int) ($attrs['level'] ?? 2))),
                    ...$base,
                ];
            }

            if ('' === $text && in_array($name, ['core/heading', 'core/html', 'core/list', 'core/paragraph'], true)) {
                $finding = [
                    'kind' => 'empty_or_textless_block',
                    'child_count' => count($children),
                    ...$base,
                ];

                if ([] !== $children) {
                    $finding['children'] = $this->child_summaries($tree, $path, count($children));
                }
                $findings[] = $finding;
            }

            if (count($findings) >= 160) {
                break;
            }
        }

        return $findings;
    }

    /** @return list<array<string, mixed>> */
    private function child_summaries(BlockTree $tree, string $parent_path, int $count): array {
        $children = [];

        for ($index = 0; $index < min(100, $count); ++$index) {
            $path = $parent_path . '.' . $index;
            $block = $tree->get_block($path);

            if (!is_array($block)) {
                continue;
            }

            $children[] = [
                'path' => $path,
                'name' => (string) ($block['blockName'] ?? ''),
                'fingerprint' => BlockTree::fingerprint($block),
                'text_excerpt' => mb_substr(
                    trim(wp_strip_all_tags((string) ($block['innerHTML'] ?? ''))),
                    0,
                    120,
                    'UTF-8',
                ),
            ];
        }

        return $children;
    }

    /** @param array<string, mixed> $payload */
    private function selector_for_payload(array $payload): string {
        $block_name = trim((string) ($payload['block_name'] ?? ''));

        if ('' === $block_name || !str_contains($block_name, '/')) {
            return '';
        }

        [$namespace, $name] = array_pad(explode('/', $block_name, 2), 2, '');
        $prefix = 'core' === $namespace ? 'wp-block' : 'wp-block-' . sanitize_html_class($namespace);
        $class = trim($prefix . '-' . sanitize_html_class($name), '-');

        return '' !== $class ? '.' . $class : '';
    }
}
