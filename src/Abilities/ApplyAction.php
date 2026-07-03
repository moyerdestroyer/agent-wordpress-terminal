<?php

/**
 * awpt/apply-action ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;

defined('ABSPATH') || exit();

/**
 * Applies an approved staged action.
 */
final class ApplyAction
{
    private ActionRepository $actions;

    public function __construct(?ActionRepository $actions = null)
    {
        $this->actions = $actions ?? new ActionRepository();
    }

    /**
     * Register the ability.
     */
    public function register(): void
    {
        AbilityRegistrar::register([
            'name' => 'awpt/apply-action',
            'label' => __('Apply Action', 'agent-wordpress-terminal'),
            'description' => __('Applies an explicitly approved AWPT staged action.', 'agent-wordpress-terminal'),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action_id' => [
                        'type' => 'integer',
                        'description' => __('AWPT action ID.', 'agent-wordpress-terminal'),
                    ],
                ],
                'required' => ['action_id'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_apply'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => false,
                'destructive' => true,
                'requires_approval' => true,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function can_apply(array $input): bool
    {
        $action = $this->actions->get_accessible_row((int) ($input['action_id'] ?? 0));

        if (null === $action || 'approved' !== $action['status']) {
            return false;
        }

        $payload = $this->actions->decode_payload($action);
        $post_id = (int) ($payload['post_id'] ?? 0);

        return (
            $post_id > 0
            && current_user_can('edit_post', $post_id)
            && current_user_can(capability: 'manage_options')
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error
    {
        $action_id = (int) ($input['action_id'] ?? 0);
        $action = $this->actions->get_accessible_row($action_id);

        if (null === $action) {
            return new \WP_Error(code: 'awpt_action_not_found', message: __(
                'Action not found.',
                'agent-wordpress-terminal',
            ));
        }

        if ('approved' !== $action['status']) {
            return new \WP_Error(code: 'awpt_action_not_approved', message: __(
                'Action must be approved before it can be applied.',
                'agent-wordpress-terminal',
            ));
        }

        $payload = $this->actions->decode_payload($action);

        if ('content_update' !== ($payload['operation'] ?? '')) {
            return new \WP_Error(code: 'awpt_unsupported_action', message: __(
                'Unsupported action operation.',
                'agent-wordpress-terminal',
            ));
        }

        $post_id = (int) ($payload['post_id'] ?? 0);

        if ($post_id <= 0 || !current_user_can(capability: 'edit_post', args: $post_id)) {
            return new \WP_Error(code: 'awpt_cannot_edit_post', message: __(
                'You do not have permission to edit this post.',
                'agent-wordpress-terminal',
            ));
        }

        $update = ['ID' => $post_id];

        if (array_key_exists('post_title', $payload)) {
            $update['post_title'] = sanitize_text_field((string) $payload['post_title']);
        }

        if (array_key_exists('post_content', $payload)) {
            $update['post_content'] = wp_kses_post((string) $payload['post_content']);
        }

        if (1 === count($update)) {
            return new \WP_Error(code: 'awpt_empty_action', message: __(
                'Action has no post changes to apply.',
                'agent-wordpress-terminal',
            ));
        }

        $updated = wp_update_post($update, wp_error: true);

        if (is_wp_error($updated)) {
            return $updated;
        }

        $this->actions->mark_applied($action_id);

        return [
            'action_id' => $action_id,
            'post_id' => $post_id,
            'status' => 'applied',
        ];
    }
}
