<?php

/**
 * Content slash commands.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

defined('ABSPATH') || exit();

/**
 * Handles post/page slash commands.
 */
final class ContentCommandRouter
{
    /**
     * Handle /read command.
     *
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    public function read_content(array $parts): array
    {
        $post_id = (int) ($parts[1] ?? 0);

        if ($post_id <= 0) {
            return $this->usage('read', __('Usage: /read 291', 'agent-wordpress-terminal'));
        }

        $content = $this->execute_tool('awpt/read-content', ['id' => $post_id]);

        if (is_wp_error($content)) {
            return $this->error_response('read', $content->get_error_message());
        }

        return [
            'content' => sprintf(
                /* translators: 1: post title, 2: post status */
                __('Loaded %1$s (%2$s).', 'agent-wordpress-terminal'),
                $content['title'],
                $content['status'],
            ),
            'tool_calls' => [
                [
                    'tool' => 'awpt/read-content',
                    'input' => ['id' => $post_id],
                    'output' => $content,
                ],
            ],
            'actions' => [],
            'command' => 'read',
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
     * Handle /analyze command.
     *
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    public function analyze(array $parts): array
    {
        $post_id = (int) ($parts[1] ?? 0);

        if ($post_id <= 0) {
            return $this->usage('analyze', __('Usage: /analyze 291', 'agent-wordpress-terminal'));
        }

        $analysis = $this->execute_tool('awpt/analyze-page', ['id' => $post_id]);

        if (is_wp_error($analysis)) {
            return $this->error_response('analyze', $analysis->get_error_message());
        }

        return [
            'content' => sprintf(
                /* translators: 1: page title, 2: risk level, 3: heading count */
                __('Analysis ready for %1$s. Risk: %2$s. Headings: %3$d.', 'agent-wordpress-terminal'),
                $analysis['title'],
                $analysis['risk_level'],
                count($analysis['headings'] ?? []),
            ),
            'tool_calls' => [
                [
                    'tool' => 'awpt/analyze-page',
                    'input' => ['id' => $post_id],
                    'output' => $analysis,
                ],
            ],
            'actions' => [],
            'command' => 'analyze',
            'focus_post_id' => $post_id,
        ];
    }

    /**
     * Handle /read-block-tree command.
     *
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    public function read_block_tree(array $parts): array
    {
        $post_id = (int) ($parts[1] ?? 0);

        if ($post_id <= 0) {
            return $this->usage('read-block-tree', __('Usage: /read-block-tree 291', 'agent-wordpress-terminal'));
        }

        $tree = $this->execute_tool('awpt/read-block-tree', ['id' => $post_id]);

        if (is_wp_error($tree)) {
            return $this->error_response('read-block-tree', $tree->get_error_message());
        }

        return [
            'content' => sprintf(
                /* translators: %d: block count */
                __('Found %d blocks.', 'agent-wordpress-terminal'),
                (int) $tree['count'],
            ),
            'tool_calls' => [
                [
                    'tool' => 'awpt/read-block-tree',
                    'input' => ['id' => $post_id],
                    'output' => $tree,
                ],
            ],
            'actions' => [],
            'command' => 'read-block-tree',
            'focus_post_id' => $post_id,
        ];
    }

    /**
     * Handle /propose update command.
     *
     * @param int                $session_id Session ID.
     * @param array<int, string> $parts Command parts.
     * @param string             $message Full command.
     * @return array<string, mixed>
     */
    public function propose(int $session_id, array $parts, string $message): array
    {
        $subcommand = strtolower((string) ($parts[1] ?? ''));
        $post_id = (int) ($parts[2] ?? 0);

        if ('update' !== $subcommand || $post_id <= 0) {
            return $this->usage('propose', __(
                'Usage: /propose update 291 Replacement content',
                'agent-wordpress-terminal',
            ));
        }

        $content = trim(preg_replace('/^\/propose\s+update\s+\d+\s*/i', '', $message) ?? '');

        if ('' === $content) {
            return $this->usage('propose', __(
                'Add replacement content after the post ID to stage an update.',
                'agent-wordpress-terminal',
            ));
        }

        $post = get_post($post_id);

        if (!$post) {
            return $this->error_response('propose', __('Content item not found.', 'agent-wordpress-terminal'));
        }

        return $this->stage_content_update($session_id, $post_id, $post, $content);
    }

    /**
     * Stage a content update action.
     *
     * @param int      $session_id Session ID.
     * @param int      $post_id Post ID.
     * @param \WP_Post $post Post object.
     * @param string   $content Replacement content.
     * @return array<string, mixed>
     */
    private function stage_content_update(int $session_id, int $post_id, \WP_Post $post, string $content): array
    {
        $input = [
            'session_id' => $session_id,
            'post_id' => $post_id,
            'title' => sprintf(
                /* translators: %s: post title */
                __('Update content for %s', 'agent-wordpress-terminal'),
                get_the_title($post),
            ),
            'description' => __(
                'Replace the post content with the staged text from the command.',
                'agent-wordpress-terminal',
            ),
            'post_content' => wpautop($content),
            'affected' => __('Entire post content', 'agent-wordpress-terminal'),
        ];
        $action = $this->execute_tool('awpt/propose-content-update', $input);

        if (is_wp_error($action)) {
            return $this->error_response('propose', $action->get_error_message());
        }

        return [
            'content' => __('Proposed action staged for review.', 'agent-wordpress-terminal'),
            'tool_calls' => [
                [
                    'tool' => 'awpt/propose-content-update',
                    'input' => [
                        'session_id' => $session_id,
                        'post_id' => $post_id,
                    ],
                    'output' => $action,
                ],
            ],
            'actions' => [$action],
            'command' => 'propose',
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
        return (new ToolExecutor())->execute($tool_name, $input);
    }
}
