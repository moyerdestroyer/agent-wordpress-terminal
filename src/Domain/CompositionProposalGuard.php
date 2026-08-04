<?php

/**
 * Applies the shared composition contract before an action reaches staging.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Agent\AgentFeedback;
use AWPT\Support\PostContentMediaIntegrity;

if (!defined('ABSPATH')) {
    exit();
}

final class CompositionProposalGuard {
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function prepare(array $payload, string $work_type, int $session_id = 0): array|\WP_Error {
        $content = (string) ($payload['post_content'] ?? '');
        $preservation_error = new ExistingContentPreservationValidator()->validate_for_session(
            $session_id,
            (string) ($payload['original_post_content'] ?? ''),
            $content,
        );

        if ($preservation_error instanceof \WP_Error) {
            return $preservation_error;
        }

        if (
            true === ($payload['presentation_requires_h1'] ?? false)
            && 1 !== preg_match_all('/<h1\b[^>]*>.*?<\/h1>/is', $content)
        ) {
            return new \WP_Error(
                'awpt_required_page_h1_missing',
                __(
                    'Rendered evidence shows that this page needs exactly one content H1, but the proposal does not provide one.',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 409,
                    'recommended_next_tools' => [[
                        'tool' => 'awpt/propose-block-batch-update',
                        'reason' => 'Insert one core/heading level 1 block in the same atomic batch as the other presentation changes.',
                    ]],
                ],
            );
        }

        if (true === ($payload['presentation_requires_h1'] ?? false)) {
            $duplicate_heading = $this->duplicate_page_heading($content);

            if ('' !== $duplicate_heading) {
                return new \WP_Error(
                    'awpt_duplicate_page_heading',
                    __('The page H1 is duplicated by a subordinate heading.', 'agent-wordpress-terminal'),
                    [
                        'status' => 409,
                        'heading' => $duplicate_heading,
                        'recommended_next_tools' => [[
                            'tool' => 'awpt/propose-block-batch-update',
                            'reason' => 'Promote the existing heading to H1, or give the subordinate section a distinct label.',
                        ]],
                    ],
                );
            }

            $outline_error = $this->heading_outline_error($content);

            if ($outline_error instanceof \WP_Error) {
                return $outline_error;
            }
        }
        $validation = new DomainValidationService();
        $result = $validation->evaluate(
            $content,
            [
                'work_type' => sanitize_key($work_type),
                'operation' => sanitize_key((string) ($payload['operation'] ?? '')),
                'post_type' => sanitize_key((string) ($payload['post_type'] ?? '')),
                'phase' => 'propose',
            ],
            true,
        );
        $baseline = array_values(array_filter($this->baseline_findings($validation, $payload, $work_type), 'is_array'));
        /** @var list<array<string, mixed>> $baseline */
        $blocking_findings = self::new_findings($result['findings'], $baseline);
        $error = $validation->blocking_error($blocking_findings);

        if ($error instanceof \WP_Error) {
            $data = $error->get_error_data();
            $data = is_array($data) ? $data : [];
            $data['safe_fixes'] = $result['fixes'];
            $data['corrected_content'] = $result['content'];
            $data['ruleset_hash'] = $result['ruleset_hash'];
            $data['agent_feedback'] = AgentFeedback::validation($result['findings'], $result['fixes']);
            $error->add_data($data);

            return $error;
        }

        $media = new PostContentMediaIntegrity()->prepare($result['content']);

        if (is_wp_error($media)) {
            return $media;
        }

        $payload['post_content'] = $media['content'];
        $payload['validation_findings'] = $result['findings'];
        $payload['safe_fixes'] = $result['fixes'];
        $payload['ruleset_hash'] = $result['ruleset_hash'];
        $payload['agent_feedback'] = AgentFeedback::validation($result['findings'], $result['fixes'], true);

        if ([] !== $media['repairs']) {
            $existing_repairs = is_array($payload['repairs_applied'] ?? null) ? $payload['repairs_applied'] : [];
            $payload['repairs_applied'] = [...$existing_repairs, ...$media['repairs']];
        }

        return $payload;
    }

    public function heading_outline_error(string $content): ?\WP_Error {
        $matches = [];
        preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER);
        $previous_level = 0;

        foreach ($matches as $match) {
            $level = (int) ($match[1] ?? 0);

            if ($level <= 0) {
                continue;
            }

            if ($previous_level > 0 && $level > ($previous_level + 1)) {
                $text = trim(wp_strip_all_tags((string) ($match[2] ?? '')));

                return new \WP_Error(
                    'awpt_heading_level_skipped',
                    __('The proposed page skips a heading level in its document outline.', 'agent-wordpress-terminal'),
                    [
                        'status' => 409,
                        'previous_level' => $previous_level,
                        'offending_level' => $level,
                        'heading' => $text,
                        'recommended_next_tools' => [[
                            'tool' => 'awpt/propose-block-batch-update',
                            'reason' => sprintf(
                                'Change “%s” to level %d, or insert a genuine level-%d parent section before it.',
                                $text,
                                $previous_level + 1,
                                $previous_level + 1,
                            ),
                        ]],
                    ],
                );
            }

            $previous_level = $level;
        }

        return null;
    }

    private function duplicate_page_heading(string $content): string {
        $matches = [];
        preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER);
        $h1 = '';
        $subordinate = [];

        foreach ($matches as $match) {
            $text = trim((string) preg_replace(
                '/\s+/u',
                ' ',
                html_entity_decode(wp_strip_all_tags((string) ($match[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ));
            $normalized = mb_strtolower($text, 'UTF-8');

            if ('1' === (string) ($match[1] ?? '')) {
                $h1 = $normalized;
            } elseif ('' !== $normalized) {
                $subordinate[$normalized] = $text;
            }
        }

        return '' !== $h1 && isset($subordinate[$h1]) ? $subordinate[$h1] : '';
    }

    /**
     * Existing imported markup may already violate a current editorial rule.
     * A surgical review action that leaves that markup untouched must not be
     * blocked; only violations introduced by the proposed content are fatal.
     *
     * @param list<array<string, mixed>> $findings
     * @param list<array<string, mixed>> $baseline
     * @return list<array<string, mixed>>
     */
    public static function new_findings(array $findings, array $baseline): array {
        $known = [];

        foreach ($baseline as $finding) {
            $key = self::finding_key($finding);
            $known[$key] = ($known[$key] ?? 0) + 1;
        }

        $introduced = [];

        foreach ($findings as $finding) {
            $key = self::finding_key($finding);

            if (($known[$key] ?? 0) > 0) {
                --$known[$key];
                continue;
            }

            $introduced[] = $finding;
        }

        return $introduced;
    }

    /** @param array<string, mixed> $finding */
    private static function finding_key(array $finding): string {
        // Block paths are positions, not identities. Inserting a heading near
        // the start of an imported page can shift every later path while
        // leaving the pre-existing rule debt unchanged. Compare a multiset of
        // semantic findings so moved violations are grandfathered but an
        // additional violation of the same rule is still detected.
        return implode(':', [
            (string) ($finding['severity'] ?? ''),
            (string) ($finding['code'] ?? ''),
            (string) ($finding['rule_id'] ?? ''),
            (string) ($finding['source'] ?? ''),
            (string) ($finding['expected'] ?? ''),
            (string) ($finding['actual'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function baseline_findings(DomainValidationService $validation, array $payload, string $work_type): array {
        $original = (string) ($payload['original_post_content'] ?? '');

        if ('' === $original) {
            return [];
        }

        $baseline = $validation->evaluate(
            $original,
            [
                'work_type' => sanitize_key($work_type),
                'operation' => sanitize_key((string) ($payload['operation'] ?? '')),
                'post_type' => sanitize_key((string) ($payload['post_type'] ?? '')),
                'phase' => 'baseline',
            ],
            true,
        );

        return $baseline['findings'];
    }
}
