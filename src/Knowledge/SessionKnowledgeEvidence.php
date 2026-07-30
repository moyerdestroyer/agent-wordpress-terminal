<?php

/**
 * Session-scoped Knowledge evidence lookup.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

use AWPT\Database\MessageRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Recovers stable Knowledge chunk IDs already shown in a session.
 */
final class SessionKnowledgeEvidence {
    /** @return list<string> */
    public function chunk_ids(int $session_id): array {
        if ($session_id <= 0) {
            return [];
        }

        $ids = [];

        foreach (new MessageRepository()->recent_tool_calls($session_id) as $call) {
            if (
                'success' !== $call['status']
                || !in_array($call['tool'], ['awpt/search-knowledge', 'awpt/knowledge-auto-retrieval'], true)
            ) {
                continue;
            }

            if (is_array($call['output'])) {
                $this->collect($call['output'], $ids);
            }
        }

        return array_values(array_keys($ids));
    }

    /** @return list<string> */
    public function query_fingerprints(int $session_id): array {
        if ($session_id <= 0) {
            return [];
        }

        $fingerprints = [];

        foreach (new MessageRepository()->recent_tool_calls($session_id) as $call) {
            if (
                'success' !== $call['status']
                || !in_array($call['tool'], ['awpt/search-knowledge', 'awpt/knowledge-auto-retrieval'], true)
            ) {
                continue;
            }

            $output = is_array($call['output']) ? $call['output'] : [];
            $fingerprint = (string) ($output['query_fingerprint'] ?? '');

            if ('' !== $fingerprint) {
                $fingerprints[$fingerprint] = true;
            }
        }

        return array_values(array_keys($fingerprints));
    }

    /** @return list<string> */
    public function queries(int $session_id): array {
        if ($session_id <= 0) {
            return [];
        }

        $queries = [];

        foreach (new MessageRepository()->recent_tool_calls($session_id) as $call) {
            if (
                'success' !== $call['status']
                || !in_array($call['tool'], ['awpt/search-knowledge', 'awpt/knowledge-auto-retrieval'], true)
            ) {
                continue;
            }

            $input = is_array($call['input']) ? $call['input'] : [];
            $query = trim((string) ($input['query'] ?? ''));

            if ('' !== $query) {
                $queries[mb_strtolower($query)] = $query;
            }
        }

        return array_values($queries);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, true>      $ids
     */
    private function collect(array $value, array &$ids): void {
        $chunk_id = (string) ($value['chunk_id'] ?? '');

        if ('' !== $chunk_id) {
            $ids[$chunk_id] = true;
        }

        foreach ($value as $child) {
            if (!is_array($child)) {
                continue;
            }

            $this->collect($child, $ids);
        }
    }
}
