<?php

/**
 * Content slash commands.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles focused post/page slash commands.
 */
final class ContentCommandRouter
{
    /**
     * Handle /focus command.
     *
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    public function focus(array $parts): array
    {
        $post_id = (int) ($parts[1] ?? 0);

        if ($post_id <= 0) {
            return $this->usage('focus', __('Usage: /focus 291', 'agent-wordpress-terminal'));
        }

        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return $this->error_response('focus', __('Content item not found.', 'agent-wordpress-terminal'));
        }

        if (!current_user_can('read_post', $post_id)) {
            return $this->error_response('focus', __(
                'You do not have permission to focus on this content.',
                'agent-wordpress-terminal',
            ));
        }

        return [
            'content' => sprintf(
                /* translators: %s: post title */
                __('Focus set to %s.', 'agent-wordpress-terminal'),
                get_the_title($post),
            ),
            'tool_calls' => [],
            'actions' => [],
            'command' => 'focus',
            'focus_post_id' => $post_id,
        ];
    }

    /**
     * Handle /preview command.
     *
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    public function preview(array $parts): array
    {
        $post_id = (int) ($parts[1] ?? 0);

        if ($post_id <= 0) {
            return $this->usage('preview', __('Usage: /preview 291', 'agent-wordpress-terminal'));
        }

        $preview = $this->execute_tool('awpt/preview-post', ['id' => $post_id]);

        if (is_wp_error($preview)) {
            return $this->error_response('preview', $preview->get_error_message());
        }

        return [
            'content' => sprintf(
                /* translators: 1: post title, 2: preview URL */
                __('Preview ready for %1$s: %2$s', 'agent-wordpress-terminal'),
                $preview['title'],
                $preview['preview_url'],
            ),
            'tool_calls' => [
                [
                    'tool' => 'awpt/preview-post',
                    'input' => ['id' => $post_id],
                    'output' => $preview,
                ],
            ],
            'actions' => [],
            'preview' => $preview,
            'command' => 'preview',
            'focus_post_id' => $post_id,
        ];
    }

    /**
     * Return a usage response.
     *
     * @param string $command Command name.
     * @param string $message Usage message.
     * @return array<string, mixed>
     */
    private function usage(string $command, string $message): array
    {
        return $this->error_response($command, $message);
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
     * Execute a registered ability through the permission-enforcing tool executor.
     *
     * @param string               $tool_name Ability name.
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>|\WP_Error
     */
    private function execute_tool(string $tool_name, array $input): array|\WP_Error
    {
        return new ToolExecutor()->execute($tool_name, $input);
    }
}
