<?php

/**
 * awpt/apply-action ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Abilities\ActionAppliers\ContentUpdateActionApplier;
use AWPT\Abilities\ActionAppliers\SiteSettingsActionApplier;
use AWPT\Abilities\ActionAppliers\ThemeSwitchActionApplier;
use AWPT\Database\ActionRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Applies an approved staged action.
 */
final class ApplyAction
{
    private ActionRepository $actions;
    private ContentUpdateActionApplier $content_updates;
    private SiteSettingsActionApplier $site_settings;
    private ThemeSwitchActionApplier $theme_switches;

    public function __construct(
        ?ActionRepository $actions = null,
        ?ContentUpdateActionApplier $content_updates = null,
        ?SiteSettingsActionApplier $site_settings = null,
        ?ThemeSwitchActionApplier $theme_switches = null,
    ) {
        $this->actions = $actions ?? new ActionRepository();
        $this->content_updates = $content_updates ?? new ContentUpdateActionApplier();
        $this->site_settings = $site_settings ?? new SiteSettingsActionApplier();
        $this->theme_switches = $theme_switches ?? new ThemeSwitchActionApplier();
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

        return match ((string) ($payload['operation'] ?? '')) {
            'content_update' => (int) ($payload['post_id'] ?? 0) > 0
                && current_user_can('edit_post', (int) ($payload['post_id'] ?? 0))
                && current_user_can(capability: 'manage_options'),
            'site_settings_update' => current_user_can('manage_options'),
            'theme_switch' => current_user_can('switch_themes') && current_user_can('manage_options'),
            default => false,
        };
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

        $result = match ((string) ($payload['operation'] ?? '')) {
            'content_update' => $this->content_updates->apply($payload),
            'site_settings_update' => $this->site_settings->apply($payload),
            'theme_switch' => $this->theme_switches->apply($payload),
            default => new \WP_Error(code: 'awpt_unsupported_action', message: __(
                'Unsupported action operation.',
                'agent-wordpress-terminal',
            )),
        };

        if (is_wp_error($result)) {
            return $result;
        }

        $this->actions->mark_applied($action_id);

        return array_merge([
            'action_id' => $action_id,
            'status' => 'applied',
        ], $result);
    }
}
