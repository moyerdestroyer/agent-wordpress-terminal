<?php

/**
 * awpt/propose-resource-change ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;
use AWPT\Support\ResourceChangeManager;

if (!defined('ABSPATH')) {
    exit();
}

/** Stages approval-gated changes to common WordPress resources. */
final class ProposeResourceChange implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-resource-change',
            'label' => __('Propose WordPress Resource Change', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages a validated change to a non-content WordPress resource. Native types: term, post_terms, menu, menu_item, menu_location, user, comment, widget_area, registered_setting, and post_meta. Read/list the target first. Integrations may extend types and operations.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'resource_type' => ['type' => 'string'],
                    'operation' => [
                        'type' => 'string',
                        'description' => __(
                            'Operation appropriate to the resource, such as create, update, delete, set, assign, status, or set_widgets.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'resource_id' => [
                        'type' => 'string',
                        'description' => __(
                            'Exact resource ID; use an empty string for create operations.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'description' => __(
                            'Operation-specific desired fields from verified resource evidence.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'proposal_manifest' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                    'decision_trace' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => [
                    'session_id',
                    'resource_type',
                    'operation',
                    'resource_id',
                    'data',
                    'title',
                    'description',
                ],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_propose'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => false,
                'destructive' => false,
                'requires_approval' => true,
            ],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_propose(array $input): bool {
        $session_id = (int) ($input['session_id'] ?? 0);
        $data = is_array($input['data'] ?? null) ? $input['data'] : [];
        /** @var array<string, mixed> $data */

        return $session_id > 0
        && !is_wp_error(new ResourceChangeManager()->prepare(
            (string) ($input['resource_type'] ?? ''),
            (string) ($input['operation'] ?? ''),
            (string) ($input['resource_id'] ?? ''),
            $data,
        ));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);

        if (!new SessionRepository()->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $data = is_array($input['data'] ?? null) ? $input['data'] : [];
        /** @var array<string, mixed> $data */
        $prepared = new ResourceChangeManager()->prepare(
            (string) ($input['resource_type'] ?? ''),
            (string) ($input['operation'] ?? ''),
            (string) ($input['resource_id'] ?? ''),
            $data,
        );

        if (is_wp_error($prepared)) {
            return $prepared;
        }

        $payload = [
            'operation' => ActionOperations::RESOURCE_CHANGE,
            ...$prepared,
        ];

        if (is_array($input['proposal_manifest'] ?? null)) {
            $payload['proposal_manifest'] = $input['proposal_manifest'];
        }

        if (is_array($input['decision_trace'] ?? null)) {
            $payload['decision_trace'] = $input['decision_trace'];
        }

        $action_id = new ActionRepository()->create(
            $session_id,
            sanitize_text_field((string) ($input['title'] ?? '')),
            sanitize_textarea_field((string) ($input['description'] ?? '')),
            $payload,
        );

        if (null === $action_id) {
            return new \WP_Error(
                'awpt_action_create_failed',
                __('Could not create the resource proposal.', 'agent-wordpress-terminal'),
                ['status' => 500],
            );
        }

        $action = new ActionRepository()->format_action($action_id);

        return is_array($action) ? $action : [];
    }
}
