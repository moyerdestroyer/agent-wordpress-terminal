<?php

/**
 * Slash command router.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\MCP\Adapter;
use AWPT\Support\ContentTargetResolver;
use AWPT\Support\ToolPreferences;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Handles terminal slash commands (secondary to natural language).
 */
final class SlashCommandRouter {
    /**
     * Route a slash command.
     *
     * @param string $message User message.
     * @return array<string, mixed>
     */
    public function dispatch(string $message): array {
        $split = preg_split('/\s+/', trim($message));
        $parts = is_array($split) ? $split : [];
        $command = strtolower($parts[0] ?? '');

        return match ($command) {
            '/help' => $this->help(),
            '/clear' => [
                'content' => __('Transcript cleared for this session.', 'agent-wordpress-terminal'),
                'tool_calls' => [],
                'actions' => [],
                'command' => 'clear',
            ],
            '/tools' => $this->tools(),
            '/knowledge' => $this->knowledge($parts),
            '/focus' => $this->focus($parts),
            '/preview' => $this->preview($parts),
            default => [
                'content' => sprintf(
                    /* translators: %s: slash command */
                    __('Unknown command: %s. Try /help.', 'agent-wordpress-terminal'),
                    $command,
                ),
                'tool_calls' => [],
                'actions' => [],
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function help(): array {
        return [
            'content' => implode("\n", [
                __('You can usually ask in plain language:', 'agent-wordpress-terminal'),
                __('Focus the About page', 'agent-wordpress-terminal'),
                __('Preview the homepage', 'agent-wordpress-terminal'),
                __('Find brand voice guidance', 'agent-wordpress-terminal'),
                '',
                __('Useful shortcuts:', 'agent-wordpress-terminal'),
                __('/focus about - set focus by title, slug, URL, or ID', 'agent-wordpress-terminal'),
                __('/preview about - open a preview by title, slug, URL, or ID', 'agent-wordpress-terminal'),
                __('/knowledge search brand voice - search indexed Knowledge', 'agent-wordpress-terminal'),
                __('/tools - list registered WordPress Abilities', 'agent-wordpress-terminal'),
                __('/clear - clear this session transcript', 'agent-wordpress-terminal'),
            ]),
            'tool_calls' => [],
            'actions' => [],
            'command' => 'help',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tools(): array {
        return [
            'content' => $this->format_tool_groups($this->tool_groups()),
            'tool_calls' => [],
            'actions' => [],
            'command' => 'tools',
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function tool_groups(): array {
        $prefs = new ToolPreferences();
        $core_label = __('Core Abilities', 'agent-wordpress-terminal');
        $awpt_label = __('AWPT Abilities', 'agent-wordpress-terminal');
        $other_label = __('Other abilities & tools', 'agent-wordpress-terminal');
        $groups = [
            $core_label => [],
            $awpt_label => [],
            $other_label => [],
        ];
        $seen = [];

        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $ability) {
                $name = $ability->get_name();
                $seen[$name] = true;
                $suffix = $this->tool_status_suffix($name, $prefs);

                if (str_starts_with($name, 'core/')) {
                    $groups[$core_label][] = $name . $suffix;
                    continue;
                }

                if (str_starts_with($name, 'awpt/')) {
                    $groups[$awpt_label][] = $name . $suffix;
                    continue;
                }

                $groups[$other_label][] = $name . $suffix;
            }
        }

        // Rare non-ability leftovers (not a first-class MCP product surface).
        foreach (new Adapter()->list_tools() as $tool) {
            $name = (string) ($tool['name'] ?? '');

            if ('' === $name || array_key_exists($name, $seen)) {
                continue;
            }

            $groups[$other_label][] = $name . $this->tool_status_suffix($name, $prefs);
        }

        return $groups;
    }

    private function tool_status_suffix(string $name, ToolPreferences $prefs): string {
        if ($prefs->is_never_auto($name)) {
            return ' ' . __('(human-only)', 'agent-wordpress-terminal');
        }

        if (!$prefs->is_enabled($name)) {
            return ' ' . __('(disabled)', 'agent-wordpress-terminal');
        }

        return '';
    }

    /**
     * @param array<string, array<int, string>> $groups
     */
    private function format_tool_groups(array $groups): string {
        $lines = [];

        foreach ($groups as $label => $names) {
            $lines[] = $label . ':';
            $lines[] = [] === $names
                ? '- ' . __('None discovered', 'agent-wordpress-terminal')
                : implode("\n", array_map(static fn(string $name): string => '- ' . $name, $names));
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, string> $parts
     * @return array<string, mixed>
     */
    private function knowledge(array $parts): array {
        $action = strtolower($parts[1] ?? 'search');

        return match ($action) {
            'read' => $this->knowledge_read($parts),
            'search' => $this->knowledge_search($parts),
            default => [
                'content' => __(
                    'Usage: /knowledge search brand voice or /knowledge read 123',
                    'agent-wordpress-terminal',
                ),
                'tool_calls' => [],
                'actions' => [],
                'command' => 'knowledge',
            ],
        };
    }

    /**
     * @param array<int, string> $parts
     * @return array<string, mixed>
     */
    private function knowledge_search(array $parts): array {
        $query = trim(implode(' ', array_slice($parts, 2)));

        if ('' === $query) {
            return [
                'content' => __(
                    'Usage: /knowledge search brand voice or /knowledge read 123',
                    'agent-wordpress-terminal',
                ),
                'tool_calls' => [],
                'actions' => [],
                'command' => 'knowledge',
            ];
        }

        $result = new ToolExecutor()->execute('awpt/search-knowledge', ['query' => $query, 'limit' => 8]);

        if (is_wp_error($result)) {
            return $this->error_response('knowledge', $result->get_error_message());
        }

        $items = is_array($result['items'] ?? null) ? $result['items'] : [];

        if ([] === $items) {
            $content = __(
                'No indexed Knowledge matched that query. Rebuild the Knowledge index or try different terms.',
                'agent-wordpress-terminal',
            );
        } else {
            $lines = array_map(static fn(array $item): string => sprintf(
                '%s #%s: %s — %s',
                (string) ($item['source_kind'] ?? 'source'),
                (string) ($item['source_post_id'] ?? $item['source_id'] ?? ''),
                (string) ($item['label'] ?? ''),
                (string) ($item['excerpt'] ?? ''),
            ), $items);
            $content = implode("\n", $lines);
        }

        return [
            'content' => $content,
            'tool_calls' => [[
                'tool' => 'awpt/search-knowledge',
                'input' => ['query' => $query, 'limit' => 8],
                'output' => $result,
                'status' => 'success',
            ]],
            'actions' => [],
            'command' => 'knowledge',
        ];
    }

    /**
     * @param array<int, string> $parts
     * @return array<string, mixed>
     */
    private function knowledge_read(array $parts): array {
        $post_id = (int) ($parts[2] ?? 0);

        if ($post_id <= 0) {
            return [
                'content' => __(
                    'Usage: /knowledge search brand voice or /knowledge read 123',
                    'agent-wordpress-terminal',
                ),
                'tool_calls' => [],
                'actions' => [],
                'command' => 'knowledge',
            ];
        }

        $result = new ToolExecutor()->execute('awpt/read-knowledge', ['id' => $post_id]);

        if (is_wp_error($result)) {
            return $this->error_response('knowledge', $result->get_error_message());
        }

        return [
            'content' => sprintf(
                /* translators: %s: Knowledge label */
                __('Loaded Knowledge item: %s', 'agent-wordpress-terminal'),
                (string) ($result['label'] ?? '#' . $post_id),
            ),
            'tool_calls' => [[
                'tool' => 'awpt/read-knowledge',
                'input' => ['id' => $post_id],
                'output' => $result,
                'status' => 'success',
            ]],
            'actions' => [],
            'command' => 'knowledge',
        ];
    }

    /**
     * @param array<int, string> $parts
     * @return array<string, mixed>
     */
    private function focus(array $parts): array {
        $query = trim(implode(' ', array_slice($parts, 1)));
        $resolved = new ContentTargetResolver()->resolve($query);

        if ('missing' === $resolved['status']) {
            return $this->error_response('focus', __(
                'Usage: /focus about, /focus https://example.com/about, or /focus {id}',
                'agent-wordpress-terminal',
            ));
        }

        if ('ambiguous' === $resolved['status']) {
            return $this->ambiguous_content_response('focus', $resolved['results'] ?? []);
        }

        $post_id = (int) ($resolved['post_id'] ?? 0);
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
            'focus' => $resolved['result'] ?? null,
        ];
    }

    /**
     * @param array<int, string> $parts
     * @return array<string, mixed>
     */
    private function preview(array $parts): array {
        $query = trim(implode(' ', array_slice($parts, 1)));
        $resolved = new ContentTargetResolver()->resolve($query);

        if ('missing' === $resolved['status']) {
            return $this->error_response('preview', __(
                'Usage: /preview about, /preview https://example.com/about, or /preview {id}',
                'agent-wordpress-terminal',
            ));
        }

        if ('ambiguous' === $resolved['status']) {
            return $this->ambiguous_content_response('preview', $resolved['results'] ?? []);
        }

        $post_id = (int) ($resolved['post_id'] ?? 0);
        $preview = new ToolExecutor()->execute('awpt/preview-post', ['id' => $post_id]);

        if (is_wp_error($preview)) {
            return $this->error_response('preview', $preview->get_error_message());
        }

        return [
            'content' => sprintf(
                /* translators: 1: post title, 2: preview URL */
                __('Preview ready for %1$s: %2$s', 'agent-wordpress-terminal'),
                (string) ($preview['title'] ?? ''),
                (string) ($preview['preview_url'] ?? ''),
            ),
            'tool_calls' => [[
                'tool' => 'awpt/preview-post',
                'input' => ['id' => $post_id],
                'output' => $preview,
            ]],
            'actions' => [],
            'preview' => $preview,
            'command' => 'preview',
            'focus_post_id' => $post_id,
            'focus' => $resolved['result'] ?? null,
        ];
    }

    /**
     * @param list<array<array-key, mixed>> $results
     * @return array<string, mixed>
     */
    private function ambiguous_content_response(string $command, array $results): array {
        $lines = [__(
            'I found multiple matching content items. Use a specific ID, URL, or slug:',
            'agent-wordpress-terminal',
        )];

        foreach (array_slice($results, 0, 5) as $result) {
            $lines[] = sprintf(
                /* translators: 1: post ID, 2: title, 3: post type, 4: status */
                __('- #%1$d %2$s (%3$s, %4$s)', 'agent-wordpress-terminal'),
                (int) ($result['id'] ?? 0),
                (string) ($result['title'] ?? ''),
                (string) ($result['type'] ?? ''),
                (string) ($result['status'] ?? ''),
            );
        }

        return [
            'content' => implode("\n", $lines),
            'tool_calls' => [],
            'actions' => [],
            'command' => $command,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error_response(string $command, string $message): array {
        return [
            'content' => $message,
            'tool_calls' => [],
            'actions' => [],
            'command' => $command,
        ];
    }
}
