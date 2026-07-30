<?php

/**
 * awpt/read-proposal ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Returns the complete staged action payload needed for a revision turn.
 */
final class ReadProposal implements AbilityInterface {
    private ActionRepository $actions;

    public function __construct(?ActionRepository $actions = null) {
        $this->actions = $actions ?? new ActionRepository();
    }

    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-proposal',
            'label' => __('Read Proposal', 'agent-wordpress-terminal'),
            'description' => __(
                'Returns an open AWPT proposal and its staged payload so it can be revised without reconstructing prior work.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action_id' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => __('Open AWPT proposal ID.', 'agent-wordpress-terminal'),
                    ],
                ],
                'required' => ['action_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
            ],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_read(array $input): bool {
        $row = $this->actions->get_accessible_row((int) ($input['action_id'] ?? 0));

        return is_array($row) && in_array((string) ($row['status'] ?? ''), ['proposed', 'approved'], true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $action_id = (int) ($input['action_id'] ?? 0);
        $action = $this->actions->format_action($action_id);

        if (null === $action || !in_array((string) ($action['status'] ?? ''), ['proposed', 'approved'], true)) {
            return new \WP_Error(
                'awpt_proposal_not_found',
                __('Open proposal not found.', 'agent-wordpress-terminal'),
                ['status' => 404],
            );
        }

        return $action;
    }
}
