<?php

/**
 * Session detail hydration helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\Json;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Converts raw database rows into REST-friendly session shapes.
 */
final class SessionHydrator {
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function tool_calls(array $rows, bool $include_outputs = true): array {
        $hydrated = [];

        foreach ($rows as $tool_call) {
            $input = Json::decode_array((string) ($tool_call['input_json'] ?? ''));
            $output = Json::decode_array((string) ($tool_call['output_json'] ?? ''));
            $item = [
                'id' => $tool_call['id'] ?? 0,
                'tool' => (string) ($tool_call['tool_name'] ?? ''),
                'input' => $input,
                'status' => (string) ($tool_call['status'] ?? ''),
                'created_at' => (string) ($tool_call['created_at'] ?? ''),
            ];

            if ($include_outputs) {
                $item['output'] = [] !== $output ? $output : null;
            } else {
                $item['output'] = null;
                $item['output_summary'] = $this->tool_output_summary((string) ($tool_call['tool_name'] ?? ''), $output);
            }

            $hydrated[] = $item;
        }

        return $hydrated;
    }

    /**
     * @param array<array-key, mixed> $output
     */
    private function tool_output_summary(string $tool, array $output): string {
        if ([] === $output) {
            return '';
        }

        if (array_key_exists('error', $output)) {
            return (string) $output['error'];
        }

        if (array_key_exists('count', $output)) {
            return sprintf('%s: %d result(s)', $tool, (int) $output['count']);
        }

        if (array_key_exists('total', $output)) {
            return sprintf('%s: %d total', $tool, (int) $output['total']);
        }

        if (array_key_exists('title', $output)) {
            return sprintf('%s: %s', $tool, (string) $output['title']);
        }

        return $tool;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function actions(array $rows): array {
        $hydrated = [];

        foreach ($rows as $action) {
            $hydrated[] = [
                'id' => (int) ($action['id'] ?? 0),
                'session_id' => (int) ($action['session_id'] ?? 0),
                'title' => (string) ($action['title'] ?? ''),
                'description' => (string) ($action['description'] ?? ''),
                'payload' => Json::decode_array((string) ($action['payload_json'] ?? '')),
                'status' => (string) ($action['status'] ?? ''),
                'created_at' => (string) ($action['created_at'] ?? ''),
                'updated_at' => (string) ($action['updated_at'] ?? ''),
            ];
        }

        return $hydrated;
    }
}
