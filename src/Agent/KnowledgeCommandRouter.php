<?php

/**
 * Knowledge slash commands.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

defined('ABSPATH') || exit();

/**
 * Handles /knowledge commands.
 */
final class KnowledgeCommandRouter
{
    /**
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    public function dispatch(array $parts): array
    {
        $action = strtolower((string) ($parts[1] ?? 'search'));

        return match ($action) {
            'read' => $this->read($parts),
            'search' => $this->search($parts),
            default => $this->usage(),
        };
    }

    /**
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    private function search(array $parts): array
    {
        $query = trim(implode(' ', array_slice($parts, 2)));

        if ('' === $query) {
            return $this->usage();
        }

        $result = (new ToolExecutor())->execute('awpt/search-knowledge', ['query' => $query, 'limit' => 8]);

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
            'tool_calls' => [
                [
                    'tool' => 'awpt/search-knowledge',
                    'input' => ['query' => $query, 'limit' => 8],
                    'output' => $result,
                    'status' => 'success',
                ],
            ],
            'actions' => [],
            'command' => 'knowledge',
        ];
    }

    /**
     * @param array<int, string> $parts Command parts.
     * @return array<string, mixed>
     */
    private function read(array $parts): array
    {
        $post_id = (int) ($parts[2] ?? 0);

        if ($post_id <= 0) {
            return $this->usage();
        }

        $result = (new ToolExecutor())->execute('awpt/read-knowledge', ['id' => $post_id]);

        if (is_wp_error($result)) {
            return $this->error_response('knowledge', $result->get_error_message());
        }

        return [
            'content' => sprintf(
                /* translators: %s: Knowledge label */
                __('Loaded Knowledge item: %s', 'agent-wordpress-terminal'),
                (string) ($result['label'] ?? '#' . $post_id),
            ),
            'tool_calls' => [
                [
                    'tool' => 'awpt/read-knowledge',
                    'input' => ['id' => $post_id],
                    'output' => $result,
                    'status' => 'success',
                ],
            ],
            'actions' => [],
            'command' => 'knowledge',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function usage(): array
    {
        return [
            'content' => __('Usage: /knowledge search brand voice or /knowledge read 123', 'agent-wordpress-terminal'),
            'tool_calls' => [],
            'actions' => [],
            'command' => 'knowledge',
        ];
    }

    /**
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
}
