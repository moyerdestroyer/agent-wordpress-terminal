<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AgentFeedback;
use AWPT\Agent\AgentWorkContextService;
use AWPT\Agent\TurnProfile;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/** Returns the AWPT-owned workflow contract for a concrete site task. */
final class GetWorkContext implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/get-work-context',
            'label' => __('Get Work Context', 'agent-wordpress-terminal'),
            'description' => __(
                'Returns AWPT workflow phases, evidence gates, active design authority, relevant guidance, rules, and pattern candidates for a concrete task.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'intent' => ['type' => 'string'],
                    'post_type' => ['type' => 'string'],
                ],
                'required' => ['intent'],
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
        $intent = sanitize_textarea_field((string) ($input['intent'] ?? ''));
        $profile = TurnProfile::from_message($intent);
        $context = new AgentWorkContextService()->compile(
            $intent,
            $profile,
            sanitize_key((string) ($input['post_type'] ?? 'page')),
        );
        $context['agent_feedback'] = AgentFeedback::make(
            'needs_evidence',
            __('Follow the evidence gates, then stage one proposal for human review.', 'agent-wordpress-terminal'),
            [
                'next_actions' => array_values(array_map(static function (array $gate): array {
                    $abilities = ArrayKey::list_of_strings($gate['abilities'] ?? null);

                    return [
                        'ability' => $abilities[0] ?? 'awpt/find-abilities',
                        'reason' => (string) ($gate['evidence'] ?? ''),
                        'input' => [],
                    ];
                }, ArrayKey::list_of_maps(ArrayKey::as_map($context['workflow'] ?? null)['evidence_gates'] ?? null))),
            ],
        );

        return $context;
    }
}
