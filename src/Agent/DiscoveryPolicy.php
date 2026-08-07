<?php

/**
 * Evidence-gain policy for bounded agent discovery.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Moves content turns from discovery to composition without imposing a fixed read count.
 */
final class DiscoveryPolicy {
    private const DISCOVERY_SECONDS = 45;

    /**
     * @param array<int, array<string, mixed>> $all_calls
     * @param array<int, array<string, mixed>> $latest_calls
     * @param array{content_turn?: bool, presentation_edit?: bool} $context
     * @return array{compose: bool, reason: string, coverage: list<string>}
     */
    public function decide(
        string $user_message,
        array $all_calls,
        array $latest_calls,
        int $elapsed_seconds,
        array $context = [],
    ): array {
        $is_content_turn = true === ($context['content_turn'] ?? false);

        if (!$is_content_turn || $this->has_successful_proposal($all_calls)) {
            return ['compose' => false, 'reason' => '', 'coverage' => []];
        }

        $coverage = $this->coverage($all_calls);
        // Redesign: know the page, then require pattern *structure* (or honest empty/fallback).
        // Recommend alone is not enough when recommendations were non-empty (Ollie-inspired).
        $presentation_edit = true === ($context['presentation_edit'] ?? false);
        if ($presentation_edit) {
            $knows_page =
                in_array('page_analysis', $coverage, true)
                || in_array('content_read', $coverage, true)
                || in_array('block_tree', $coverage, true);
            $has_structure =
                in_array('pattern_structure', $coverage, true)
                || in_array('pattern_draft', $coverage, true);
            $honest_empty_catalog =
                in_array('pattern_consulted', $coverage, true)
                && !in_array('pattern_recommendation', $coverage, true);
            $custom_fallback = in_array('custom_fallback', $coverage, true);
            $pattern_ready = $has_structure || $custom_fallback || $honest_empty_catalog;

            if (!$knows_page || !$pattern_ready) {
                return ['compose' => false, 'reason' => '', 'coverage' => $coverage];
            }

            $reason = $has_structure
                ? 'The focused page and loaded theme pattern structure are available for a theme-enhanced redesign.'
                : (
                    $custom_fallback
                        ? 'Pattern preparation returned custom_fallback; a bespoke redesign may be staged.'
                        : 'Pattern consultation returned no suitable recommendations; a bespoke redesign may be staged.'
                );

            return [
                'compose' => true,
                'reason' => $reason,
                'coverage' => $coverage,
            ];
        }
        $media_required = (bool) preg_match('/\b(media library|images?|photos?|gallery)\b/i', $user_message);
        $prepared = in_array('pattern_draft', $coverage, true);
        $custom_fallback = in_array('custom_fallback', $coverage, true);
        $proposal_revision = in_array('proposal_revision', $coverage, true);
        $base_complete =
            $proposal_revision
            || $custom_fallback
            || $prepared
            || in_array('pattern_inventory', $coverage, true)
            && in_array('pattern_structure', $coverage, true)
            && (!$media_required || in_array('media_inventory', $coverage, true));

        if (!$base_complete) {
            return ['compose' => false, 'reason' => '', 'coverage' => $coverage];
        }

        if ($prepared) {
            return [
                'compose' => true,
                'reason' => 'A compatible full-document pattern, editable slots, and requested media candidates are prepared.',
                'coverage' => $coverage,
            ];
        }

        if ($proposal_revision) {
            return [
                'compose' => true,
                'reason' => 'The current staged proposal and its path-addressed revision context are verified.',
                'coverage' => $coverage,
            ];
        }

        if ($custom_fallback) {
            return [
                'compose' => true,
                'reason' => 'Pattern preparation verified that the explicit custom request should use the unrestricted composition path.',
                'coverage' => $coverage,
            ];
        }

        // A requested Media Library image is already exact, actionable evidence.
        // Do not spend another provider hop refining theme guidance after a usable
        // pattern and the attachment inventory have both been read. The compose
        // phase has the pattern markup and can stage the page immediately.
        if ($media_required) {
            return [
                'compose' => true,
                'reason' => 'The requested Media Library asset and a usable page pattern are verified.',
                'coverage' => $coverage,
            ];
        }

        if ($elapsed_seconds >= self::DISCOVERY_SECONDS) {
            return [
                'compose' => true,
                'reason' => 'The discovery allowance is complete and the required composition evidence is available.',
                'coverage' => $coverage,
            ];
        }

        if ($this->latest_query_is_exhausted($latest_calls)) {
            return [
                'compose' => true,
                'reason' => 'The latest Knowledge refinement returned no new chunks.',
                'coverage' => $coverage,
            ];
        }

        if ($this->all_latest_signatures_were_seen($all_calls, $latest_calls)) {
            return [
                'compose' => true,
                'reason' => 'The latest discovery batch repeated evidence already gathered.',
                'coverage' => $coverage,
            ];
        }

        if ($this->purposeful_new_research($latest_calls)) {
            return ['compose' => false, 'reason' => '', 'coverage' => $coverage];
        }

        return [
            'compose' => true,
            'reason' => 'The required layout, theme, and requested media evidence is available.',
            'coverage' => $coverage,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $calls
     * @return list<string>
     */
    private function coverage(array $calls): array {
        $coverage = [];

        foreach ($calls as $call) {
            $tool = (string) ($call['tool'] ?? '');
            $input = is_array($call['input'] ?? null) ? $call['input'] : [];

            if ('awpt/inspect-rendered-element' === $tool) {
                $coverage['rendered_inspection'] = true;
            }

            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            if (in_array(
                $tool,
                ['awpt/analyze-page', 'awpt/read-block-tree', 'awpt/list-blocks', 'awpt/read-content'],
                true,
            )) {
                $coverage['page_analysis'] = true;
            } elseif ('awpt/list-patterns' === $tool) {
                $coverage['pattern_inventory'] = true;
            } elseif ('awpt/recommend-patterns' === $tool) {
                $coverage['pattern_consulted'] = true;
                $output = is_array($call['output'] ?? null) ? $call['output'] : [];

                if ([] !== ($output['recommendations'] ?? [])) {
                    $coverage['pattern_recommendation'] = true;
                }
            } elseif ('awpt/prepare-pattern-draft' === $tool) {
                $output = is_array($call['output'] ?? null) ? $call['output'] : [];
                $mode = sanitize_key((string) ($output['mode'] ?? ''));

                if ('pattern' === $mode) {
                    $coverage['pattern_inventory'] = true;
                    $coverage['pattern_structure'] = true;
                    $coverage['pattern_draft'] = true;

                    if ([] !== ($output['media'] ?? [])) {
                        $coverage['media_inventory'] = true;
                    }
                } elseif ('custom_fallback' === $mode) {
                    $coverage['custom_fallback'] = true;
                }
            } elseif ('awpt/prepare-pattern-change' === $tool) {
                $output = is_array($call['output'] ?? null) ? $call['output'] : [];
                $mode = sanitize_key((string) ($output['mode'] ?? ''));

                if (in_array($mode, ['replace', 'insert'], true)) {
                    $coverage['pattern_inventory'] = true;
                    $coverage['pattern_structure'] = true;
                    $coverage['pattern_change'] = true;

                    if ([] !== ($output['media'] ?? [])) {
                        $coverage['media_inventory'] = true;
                    }
                } elseif ('custom_fallback' === $mode) {
                    $coverage['custom_fallback'] = true;
                }
            } elseif ('awpt/read-pattern' === $tool) {
                $coverage['pattern_structure'] = true;
                $output = is_array($call['output'] ?? null) ? $call['output'] : [];
                $scope = sanitize_key((string) ($output['composition_scope'] ?? 'section'));
                $coverage['pattern_role:' . $scope] = true;
            } elseif ('awpt/read-proposal' === $tool) {
                $output = is_array($call['output'] ?? null) ? $call['output'] : [];

                if ([] !== ($output['revision_context'] ?? [])) {
                    $coverage['proposal_revision'] = true;
                }
            } elseif ('awpt/list-content' === $tool && 'attachment' === (string) ($input['post_type'] ?? '')) {
                $coverage['media_inventory'] = true;
            } elseif ('awpt/search-knowledge' === $tool) {
                $coverage['knowledge_refinement'] = true;
            } elseif ('awpt/read-theme-file' === $tool) {
                $coverage['theme_file'] = true;
            }
        }

        return array_values(array_keys($coverage));
    }

    /** @param array<int, array<string, mixed>> $calls */
    private function purposeful_new_research(array $calls): bool {
        $pattern_reads = array_values(array_filter(
            $calls,
            static fn(array $call): bool => 'awpt/read-pattern' === (string) ($call['tool'] ?? ''),
        ));

        foreach ($calls as $call) {
            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            $tool = (string) ($call['tool'] ?? '');
            $input = is_array($call['input'] ?? null) ? $call['input'] : [];
            $output = is_array($call['output'] ?? null) ? $call['output'] : [];
            $purpose = trim((string) ($input['purpose'] ?? ''));

            if ('awpt/search-knowledge' === $tool && '' !== $purpose && (int) ($output['novel_count'] ?? 0) > 0) {
                return true;
            }

            if ('awpt/read-pattern' === $tool && 1 === count($pattern_reads) && '' !== $purpose) {
                $dependencies = is_array($output['design_dependencies'] ?? null) ? $output['design_dependencies'] : [];

                if (true === ($dependencies['requires_theme_research'] ?? false)) {
                    return true;
                }
            }

            if ('awpt/read-theme-file' === $tool && '' !== trim((string) ($input['query'] ?? '')) && '' !== $purpose) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $calls */
    private function latest_query_is_exhausted(array $calls): bool {
        foreach ($calls as $call) {
            if ('awpt/search-knowledge' !== (string) ($call['tool'] ?? '')) {
                continue;
            }

            $output = is_array($call['output'] ?? null) ? $call['output'] : [];

            if (true === ($output['exhausted'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $all
     * @param array<int, array<string, mixed>> $latest
     */
    private function all_latest_signatures_were_seen(array $all, array $latest): bool {
        if ([] === $latest || count($all) <= count($latest)) {
            return false;
        }

        $prior = array_slice($all, 0, count($all) - count($latest));
        $seen = [];

        foreach ($prior as $call) {
            $seen[$this->signature($call)] = true;
        }

        foreach ($latest as $call) {
            if (!array_key_exists($this->signature($call), $seen)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $call */
    private function signature(array $call): string {
        $input = is_array($call['input'] ?? null) ? $call['input'] : [];
        unset($input['session_id']);
        ksort($input);

        return (string) ($call['tool'] ?? '') . ':' . hash('sha256', (string) wp_json_encode($input));
    }

    /** @param array<int, array<string, mixed>> $calls */
    private function has_successful_proposal(array $calls): bool {
        foreach ($calls as $call) {
            if (
                'success' === (string) ($call['status'] ?? '')
                && ToolRegistry::is_proposal_ability((string) ($call['tool'] ?? ''))
            ) {
                return true;
            }
        }

        return false;
    }
}
