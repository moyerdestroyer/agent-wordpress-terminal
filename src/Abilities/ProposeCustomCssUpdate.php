<?php

/**
 * awpt/propose-custom-css-update ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Stages Customizer Additional CSS (wp custom_css) for admin approval.
 */
final class ProposeCustomCssUpdate implements AbilityInterface {
    private ActionRepository $actions;
    private SessionRepository $sessions;

    public function __construct(?ActionRepository $actions = null, ?SessionRepository $sessions = null) {
        $this->actions = $actions ?? new ActionRepository();
        $this->sessions = $sessions ?? new SessionRepository();
    }

    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-custom-css-update',
            'label' => __('Propose Custom CSS Update', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages an update to the active theme’s Additional CSS (Customizer custom_css) for admin approval. Prefer this for small frontend layout fixes (sticky offsets, docs TOC chrome) instead of editing theme files on disk.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'css' => [
                        'type' => 'string',
                        'description' => __(
                            'Full Additional CSS document to store (not a patch). Include prior rules you intend to keep.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'stylesheet' => [
                        'type' => 'string',
                        'description' => __(
                            'Theme stylesheet. Defaults to the active theme.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['session_id', 'title', 'description', 'css'],
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
        unset($input);

        return current_user_can('edit_css') || current_user_can('edit_theme_options');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $css = (string) ($input['css'] ?? '');

        if ('' === trim($css)) {
            return new \WP_Error('awpt_empty_css', __('CSS content is required.', 'agent-wordpress-terminal'), [
                'status' => 400,
            ]);
        }

        if (strlen($css) > 200_000) {
            return new \WP_Error(
                'awpt_css_too_large',
                __('CSS exceeds the 200KB staging limit.', 'agent-wordpress-terminal'),
                [
                    'status' => 400,
                ],
            );
        }

        $stylesheet = sanitize_text_field((string) ($input['stylesheet'] ?? get_stylesheet()));
        $theme = wp_get_theme($stylesheet);

        if (!$theme->exists()) {
            return new \WP_Error('awpt_theme_not_found', __('Theme not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $original = function_exists('wp_get_custom_css') ? wp_get_custom_css($stylesheet) : '';
        $title = sanitize_text_field((string) ($input['title'] ?? ''));
        $description = sanitize_textarea_field((string) ($input['description'] ?? ''));

        if ('' === $title) {
            $title = __('Update Additional CSS', 'agent-wordpress-terminal');
        }

        $payload = [
            'operation' => ActionOperations::CUSTOM_CSS_UPDATE,
            'stylesheet' => $stylesheet,
            'theme_name' => $theme->get('Name'),
            'css' => $css,
            'original_css' => $original,
        ];

        $action_id = $this->actions->create($session_id, $title, $description, $payload);

        if (null === $action_id) {
            return new \WP_Error(
                'awpt_action_create_failed',
                __('Could not create proposed action.', 'agent-wordpress-terminal'),
                [
                    'status' => 500,
                ],
            );
        }

        $formatted = $this->actions->format_action($action_id);

        return is_array($formatted) ? $formatted : ['id' => $action_id, 'status' => 'proposed'];
    }
}
