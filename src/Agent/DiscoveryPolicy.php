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
     * @return array{compose: bool, reason: string, coverage: list<string>}
     */
    public function decide(
        string $user_message,
        array $all_calls,
        array $latest_calls,
        int $elapsed_seconds,
        bool $is_content_turn,
    ): array {
        if (!$is_content_turn || $this->has_successful_proposal($all_calls)) {
            return ['compose' => false, 'reason' => '', 'coverage' => []];
        }

        $coverage = $this->coverage($all_calls);
        $media_required = (bool) preg_match('/\b(media library|images?|photos?|gallery)\b/i', $user_message);
        $base_complete =
            in_array('pattern_inventory', $coverage, true)
            && in_array('pattern_structure', $coverage, true)
            && (!$media_required || in_array('media_inventory', $coverage, true));

        if (!$base_complete) {
            return ['compose' => false, 'reason' => '', 'coverage' => $coverage];
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
            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            $tool = (string) ($call['tool'] ?? '');
            $input = is_array($call['input'] ?? null) ? $call['input'] : [];

            if ('awpt/list-patterns' === $tool) {
                $coverage['pattern_inventory'] = true;
            } elseif ('awpt/read-pattern' === $tool) {
                $coverage['pattern_structure'] = true;
                $output = is_array($call['output'] ?? null) ? $call['output'] : [];
                $scope = sanitize_key((string) ($output['composition_scope'] ?? 'section'));
                $coverage['pattern_role:' . $scope] = true;
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
