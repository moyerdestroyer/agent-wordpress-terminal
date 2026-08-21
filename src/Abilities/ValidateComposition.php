<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Domain\CompositionGate;

if (!defined('ABSPATH')) {
    exit();
}

/** Runs active theme validation without changing content. */
final class ValidateComposition implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/validate-composition',
            'label' => __('Validate Composition', 'agent-wordpress-terminal'),
            'description' => __(
                'Checks block markup against active theme structural and editorial rules.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'content' => ['type' => 'string'],
                    'post_type' => ['type' => 'string'],
                    'pattern_name' => ['type' => 'string'],
                    'work_type' => ['type' => 'string'],
                    'apply_safe_fixes' => ['type' => 'boolean', 'default' => true],
                ],
                'required' => ['content'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => static fn(array $input): bool => current_user_can('edit_posts'),
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array {
        $result = new CompositionGate()->evaluate(
            (string) $input['content'],
            [
                'post_type' => sanitize_key((string) ($input['post_type'] ?? '')),
                'pattern_name' => sanitize_text_field((string) ($input['pattern_name'] ?? '')),
                'work_type' => sanitize_key((string) ($input['work_type'] ?? 'compose')),
                'phase' => 'validate',
            ],
            !array_key_exists('apply_safe_fixes', $input)
            || \AWPT\Support\ArrayKey::rest_bool($input['apply_safe_fixes']),
        );
        $findings = $result['findings'];

        return [
            'valid' => [] === array_filter(
                $findings,
                static fn(array $finding): bool => 'error' === (string) ($finding['severity'] ?? ''),
            ),
            'findings' => $findings,
            'content' => $result['content'],
            'fixes' => $result['fixes'],
            'ruleset_hash' => $result['ruleset_hash'],
            'agent_feedback' => $result['agent_feedback'],
        ];
    }
}
