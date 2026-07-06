<?php

/**
 * awpt/propose-plugin-deactivate ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;
use AWPT\Support\Diagnostics\PluginInventory;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Creates a staged plugin deactivation action.
 */
final class ProposePluginDeactivate {
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
            'name' => 'awpt/propose-plugin-deactivate',
            'label' => __('Propose Plugin Deactivate', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages deactivation of an installed plugin for explicit admin approval.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'plugin_file' => [
                        'type' => 'string',
                        'description' => __(
                            'Plugin file path, such as akismet/akismet.php.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['session_id', 'plugin_file', 'title', 'description'],
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
        return current_user_can('activate_plugins') && current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);
        $plugin_file = sanitize_text_field((string) ($input['plugin_file'] ?? ''));

        if (!$this->sessions->exists($session_id) || !current_user_can('manage_options')) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'));
        }

        if ($this->is_protected_plugin($plugin_file)) {
            return new \WP_Error('awpt_plugin_protected', __(
                'This plugin cannot be deactivated through AWPT.',
                'agent-wordpress-terminal',
            ));
        }

        $plugin = $this->find_plugin($plugin_file);

        if (null === $plugin) {
            return new \WP_Error('awpt_plugin_not_found', __(
                'Installed plugin not found.',
                'agent-wordpress-terminal',
            ));
        }

        $action_id = $this->actions->create(
            session_id: $session_id,
            title: sanitize_text_field((string) $input['title']),
            description: sanitize_textarea_field((string) $input['description']),
            payload: [
                'operation' => ActionOperations::PLUGIN_DEACTIVATE,
                'plugin_file' => $plugin['file'],
                'plugin_slug' => $plugin['slug'],
                'plugin_name' => $plugin['name'],
                'was_active' => $plugin['active'],
                'affected' => sprintf(
                    /* translators: %s: plugin name */
                    __('Plugin deactivated: %s', 'agent-wordpress-terminal'),
                    $plugin['name'],
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

    /**
     * @return array{slug: string, name: string, file: string, active: bool}|null
     */
    private function find_plugin(string $plugin_file): ?array {
        foreach (new PluginInventory()->list() as $plugin) {
            if ($plugin['file'] === $plugin_file) {
                return $plugin;
            }
        }

        return null;
    }

    private function is_protected_plugin(string $file): bool {
        return str_contains($file, 'agent-wordpress-terminal/');
    }
}
