<?php

/**
 * Site slash commands.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

defined('ABSPATH') || exit();

/**
 * Handles site-level read slash commands.
 */
final class SiteCommandRouter
{
    /**
     * Handle /read-settings command.
     *
     * @return array<string, mixed>
     */
    public function read_settings(): array
    {
        $settings = $this->execute_tool('awpt/read-settings', []);

        if (is_wp_error($settings)) {
            return $this->error_response('read-settings', $settings->get_error_message());
        }

        return [
            'content' => sprintf(
                /* translators: 1: site name, 2: active theme name */
                __('Loaded site settings for %1$s. Theme: %2$s.', 'agent-wordpress-terminal'),
                $settings['site']['name'] ?? '',
                $settings['theme']['name'] ?? '',
            ),
            'tool_calls' => [
                [
                    'tool' => 'awpt/read-settings',
                    'input' => [],
                    'output' => $settings,
                ],
            ],
            'actions' => [],
            'command' => 'read-settings',
        ];
    }

    /**
     * Handle /read-users command.
     *
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    public function read_users(array $parts): array
    {
        $input = $this->parse_user_command_input($parts);
        $users = $this->execute_tool('awpt/read-users', $input);

        if (is_wp_error($users)) {
            return $this->error_response('read-users', $users->get_error_message());
        }

        return [
            'content' => sprintf(
                /* translators: 1: returned user count, 2: total matched users */
                __('Loaded %1$d of %2$d users.', 'agent-wordpress-terminal'),
                (int) ($users['count'] ?? 0),
                (int) ($users['total'] ?? 0),
            ),
            'tool_calls' => [
                [
                    'tool' => 'awpt/read-users',
                    'input' => $input,
                    'output' => $users,
                ],
            ],
            'actions' => [],
            'command' => 'read-users',
        ];
    }

    /**
     * Execute a registered ability through the permission-enforcing tool executor.
     *
     * @param string               $tool_name Ability name.
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>|\WP_Error
     */
    private function execute_tool(string $tool_name, array $input): array|\WP_Error
    {
        return (new ToolExecutor())->execute($tool_name, $input);
    }

    /**
     * Return an error response.
     *
     * @param string $command Command name.
     * @param string $message Error message.
     * @return array<string, mixed>
     */
    private function error_response(string $command, string $message): array
    {
        return [
            'content' => $message,
            'tool_calls' => [],
            'actions' => [],
            'command' => $command,
        ];
    }

    /**
     * Parse optional /read-users arguments.
     *
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    private function parse_user_command_input(array $parts): array
    {
        $input = [];

        foreach (array_slice($parts, 1) as $part) {
            if (str_contains($part, '=')) {
                $this->parse_user_key_value($part, $input);

                continue;
            }

            if (is_numeric($part)) {
                $input['limit'] = (int) $part;
                continue;
            }

            if (!array_key_exists('role', $input)) {
                $input['role'] = sanitize_key($part);
            }
        }

        return $input;
    }

    /**
     * Parse one key=value option for /read-users.
     *
     * @param string               $part Argument part.
     * @param array<string, mixed> $input Parsed input.
     */
    private function parse_user_key_value(string $part, array &$input): void
    {
        [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
        $key = sanitize_key($key);

        if ('role' === $key) {
            $input['role'] = sanitize_key($value);
        }

        if ('search' === $key) {
            $input['search'] = sanitize_text_field($value);
        }

        if ('limit' === $key) {
            $input['limit'] = (int) $value;
        }
    }
}
