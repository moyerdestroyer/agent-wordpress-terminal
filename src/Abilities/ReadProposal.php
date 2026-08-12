<?php

/**
 * awpt/read-proposal ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Domain\PatternEditableSlots;
use AWPT\Support\BlockTree;

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

        $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];
        $content = (string) ($payload['post_content'] ?? '');
        $manifest = is_array($payload['composition_manifest'] ?? null) ? $payload['composition_manifest'] : [];
        $patterns = is_array($manifest['patterns'] ?? null) ? $manifest['patterns'] : [];
        $pattern_names = [];

        foreach ($patterns as $pattern) {
            if (!(is_array($pattern) && is_string($pattern['name'] ?? null))) {
                continue;
            }

            $name = trim($pattern['name']);

            if ('' !== $name) {
                $pattern_names[] = $name;
            }
        }

        if ([] === $pattern_names && '' !== (string) ($payload['pattern_name'] ?? '')) {
            $pattern_names[] = (string) $payload['pattern_name'];
        }

        $tree = BlockTree::from_content($content);
        $action['revision_context'] = [
            'action_id' => $action_id,
            'mode' => 'path_updates',
            'post_title' => (string) ($payload['post_title'] ?? ''),
            'post_type' => (string) ($payload['post_type'] ?? ''),
            'pattern_names' => array_values(array_unique($pattern_names)),
            'content_chars' => strlen($content),
            'editable_slots' => new PatternEditableSlots()->from_content($content),
            'image_blocks' => $tree->flat_list('core/image', 40),
            'instruction' => __(
                'For ordinary revisions, use awpt/propose-patterned-post with this action_id and only the path-addressed text or media changes. Do not pass this action ID to post-reading abilities. Use awpt/propose-new-post with full post_content only for an explicitly from-scratch redesign.',
                'agent-wordpress-terminal',
            ),
        ];

        return $action;
    }
}
