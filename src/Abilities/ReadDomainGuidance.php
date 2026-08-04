<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AgentFeedback;
use AWPT\Domain\DomainGuidanceResolver;

if (!defined('ABSPATH')) {
    exit();
}

/** Progressively reads one active Domain Pack guidance module. */
final class ReadDomainGuidance implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-domain-guidance',
            'label' => __('Read Domain Guidance', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads one exact guidance module identified by awpt/get-work-context, including site Knowledge overrides.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'pack_id' => ['type' => 'string'],
                    'guidance_id' => ['type' => 'string'],
                ],
                'required' => ['pack_id', 'guidance_id'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => static fn(array $input): bool => current_user_can('edit_posts'),
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $result = new DomainGuidanceResolver()->read(
            (string) ($input['pack_id'] ?? ''),
            (string) ($input['guidance_id'] ?? ''),
        );

        if (is_wp_error($result)) {
            return $result;
        }

        $result['agent_feedback'] = AgentFeedback::make('ready', __(
            'Use this guidance with verified site evidence; continue the AWPT workflow.',
            'agent-wordpress-terminal',
        ));

        return $result;
    }
}
