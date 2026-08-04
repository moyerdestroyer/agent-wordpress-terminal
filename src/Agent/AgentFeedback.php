<?php

/**
 * Stable, additive feedback contract for agents and action cards.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

final class AgentFeedback {
    /**
     * @param array{
     *     findings?: list<array<string, mixed>>,
     *     fixes?: list<array<string, mixed>>,
     *     next_actions?: list<array<string, mixed>>,
     *     retry_allowed?: bool,
     *     attempts_remaining?: int,
     *     preserve_evidence?: bool
     * } $options
     * @return array<string, mixed>
     */
    public static function make(string $outcome, string $summary, array $options = []): array {
        return [
            'outcome' => in_array($outcome, ['ready', 'needs_evidence', 'needs_correction', 'staged', 'blocked'], true)
                ? $outcome
                : 'ready',
            'summary' => sanitize_textarea_field($summary),
            'findings' => array_values($options['findings'] ?? []),
            'fixes' => array_values($options['fixes'] ?? []),
            'next_actions' => array_values($options['next_actions'] ?? []),
            'retry' => [
                'allowed' => $options['retry_allowed'] ?? false,
                'attempts_remaining' => max(0, $options['attempts_remaining'] ?? 0),
                'preserve_evidence' => $options['preserve_evidence'] ?? true,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @param list<array<string, mixed>> $fixes
     * @return array<string, mixed>
     */
    public static function validation(array $findings, array $fixes = [], bool $staged = false): array {
        $errors = count(array_filter(
            $findings,
            static fn(array $finding): bool => 'error' === (string) ($finding['severity'] ?? ''),
        ));

        if ($errors > 0) {
            return self::make(
                'needs_correction',
                sprintf(__('%d blocking validation issue(s) must be corrected.', 'agent-wordpress-terminal'), $errors),
                [
                    'findings' => $findings,
                    'fixes' => $fixes,
                    'next_actions' => [[
                        'ability' => 'awpt/validate-composition',
                        'reason' => __(
                            'Validate the corrected complete composition before staging again.',
                            'agent-wordpress-terminal',
                        ),
                        'input' => ['content' => '<corrected complete block markup>'],
                    ]],
                    'retry_allowed' => true,
                    'attempts_remaining' => 1,
                ],
            );
        }

        return self::make(
            $staged ? 'staged' : 'ready',
            $staged
                ? __('The proposal passed validation and is staged for human review.', 'agent-wordpress-terminal')
                : __('The composition passed the active validation rules.', 'agent-wordpress-terminal'),
            ['findings' => $findings, 'fixes' => $fixes],
        );
    }
}
