<?php

/**
 * awpt/propose-site-settings-update ability.
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
 * Creates a staged site settings update action.
 */
final class ProposeSiteSettingsUpdate
{
    private ActionRepository $actions;
    private SessionRepository $sessions;

    public function __construct(?ActionRepository $actions = null, ?SessionRepository $sessions = null)
    {
        $this->actions = $actions ?? new ActionRepository();
        $this->sessions = $sessions ?? new SessionRepository();
    }

    /**
     * Register the ability.
     */
    public function register(): void
    {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-site-settings-update',
            'label' => __('Propose Site Settings Update', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages safe WordPress site setting changes for explicit admin approval.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'settings' => [
                        'type' => 'object',
                        'description' => __(
                            'Allowed keys: blogname, blogdescription, blog_public, show_on_front, page_on_front, page_for_posts, posts_per_page, default_comment_status, default_ping_status, require_name_email, comment_registration, thread_comments, page_comments, comments_per_page, permalink_structure, category_base, tag_base.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['session_id', 'title', 'description', 'settings'],
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
    public function can_propose(array $input): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error
    {
        $session_id = (int) ($input['session_id'] ?? 0);

        if (!$this->sessions->exists($session_id) || !current_user_can('manage_options')) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'));
        }

        $raw_settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $settings = $this->sanitize_settings($raw_settings);

        if ([] === $settings) {
            return new \WP_Error('awpt_empty_settings_update', __(
                'No supported site settings were provided.',
                'agent-wordpress-terminal',
            ));
        }

        $original = [];

        foreach (array_keys($settings) as $option) {
            $original[$option] = get_option($option);
        }

        $action_id = $this->actions->create(
            session_id: $session_id,
            title: sanitize_text_field((string) $input['title']),
            description: sanitize_textarea_field((string) $input['description']),
            payload: [
                'operation' => 'site_settings_update',
                'settings_changes' => $settings,
                'original_settings' => $original,
                'affected' => implode(', ', array_keys($settings)),
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
     * @param array<array-key, mixed> $raw_settings
     * @return array<string, mixed>
     */
    private function sanitize_settings(array $raw_settings): array
    {
        $settings = [];

        foreach (array_keys($raw_settings) as $key) {
            if (!is_string($key)) {
                continue;
            }

            $sanitized = $this->sanitize_setting($key, $raw_settings[$key]);

            if (null !== $sanitized) {
                $settings[$key] = $sanitized;
            }
        }

        return $settings;
    }

    private function sanitize_setting(string $key, mixed $value): string|int|null
    {
        return match ($key) {
            'blogname', 'blogdescription', 'category_base', 'tag_base' => sanitize_text_field((string) $value),
            'blog_public',
            'require_name_email',
            'comment_registration',
            'thread_comments',
            'page_comments',
                => $this->truthy($value) ? 1 : 0,
            'show_on_front' => in_array((string) $value, ['posts', 'page'], true) ? (string) $value : null,
            'page_on_front', 'page_for_posts' => $this->sanitize_page_id($value),
            'posts_per_page' => max(1, min(100, $this->to_absint($value))),
            'comments_per_page' => max(1, min(500, $this->to_absint($value))),
            'default_comment_status', 'default_ping_status' => in_array((string) $value, ['open', 'closed'], true)
                ? (string) $value
                : null,
            'permalink_structure' => $this->sanitize_permalink_structure($value),
            default => null,
        };
    }

    private function sanitize_page_id(mixed $value): int
    {
        $page_id = $this->to_absint($value);

        return $page_id > 0 && get_post($page_id) instanceof \WP_Post ? $page_id : 0;
    }

    private function to_absint(mixed $value): int
    {
        return absint(is_scalar($value) ? $value : 0);
    }

    private function sanitize_permalink_structure(mixed $value): ?string
    {
        $structure = sanitize_text_field((string) $value);

        if ('' === $structure) {
            return '';
        }

        return preg_match('/^\/[A-Za-z0-9_\/%\-]+\/$/', $structure) ? $structure : null;
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }
}
