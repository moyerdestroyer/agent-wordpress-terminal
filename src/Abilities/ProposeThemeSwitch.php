<?php

/**
 * awpt/propose-theme-switch ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Creates a staged installed-theme switch action.
 */
final class ProposeThemeSwitch implements AbilityInterface {
    private ActionRepository $actions;
    private SessionRepository $sessions;

    public function __construct(?ActionRepository $actions = null, ?SessionRepository $sessions = null) {
        $this->actions = $actions ?? new ActionRepository();
        $this->sessions = $sessions ?? new SessionRepository();
    }

    /**
     * Register the ability.
     */
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-theme-switch',
            'label' => __('Propose Theme Switch', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages activation of an installed WordPress theme for explicit admin approval.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'stylesheet' => [
                        'type' => 'string',
                        'description' => __(
                            'Installed theme stylesheet, such as twentytwentyfive.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['session_id', 'stylesheet', 'title', 'description'],
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

    /**
     * @param array<string, mixed> $input
     */
    public function can_propose(array $input): bool {
        return current_user_can('switch_themes');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);
        $stylesheet = sanitize_key((string) ($input['stylesheet'] ?? ''));

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'));
        }

        $theme = wp_get_theme($stylesheet);

        if (!$theme->exists()) {
            return new \WP_Error('awpt_theme_not_found', __('Installed theme not found.', 'agent-wordpress-terminal'));
        }

        $action_id = $this->actions->create(
            session_id: $session_id,
            title: sanitize_text_field((string) $input['title']),
            description: sanitize_textarea_field((string) $input['description']),
            payload: [
                'operation' => 'theme_switch',
                'stylesheet' => $stylesheet,
                'theme_name' => $theme->get('Name'),
                'current_stylesheet' => get_stylesheet(),
                'current_theme' => wp_get_theme()->get('Name'),
                'affected' => sprintf(
                    /* translators: %s: theme name */
                    __('Active theme changes to %s', 'agent-wordpress-terminal'),
                    $theme->get('Name'),
                ),
            ],
        );

        if (null === $action_id) {
            return new \WP_Error('awpt_action_create_failed', __(
                'Could not create proposed action.',
                'agent-wordpress-terminal',
            ));
        }

        $action = $this->actions->format_action($action_id);

        return is_array($action) ? $action : [];
    }
}
