<?php

/**
 * Session detail hydration helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\Json;

defined('ABSPATH') || exit();

/**
 * Converts raw database rows into REST-friendly session shapes.
 */
final class SessionHydrator
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function tool_calls(array $rows): array
    {
        $hydrated = [];

        foreach ($rows as $tool_call) {
            $input = Json::decode_array((string) ($tool_call['input_json'] ?? ''));
            $output = Json::decode_array((string) ($tool_call['output_json'] ?? ''));
            $hydrated[] = [
                'id' => $tool_call['id'] ?? 0,
                'tool' => (string) ($tool_call['tool_name'] ?? ''),
                'input' => $input,
                'output' => [] !== $output ? $output : null,
                'status' => (string) ($tool_call['status'] ?? ''),
                'created_at' => (string) ($tool_call['created_at'] ?? ''),
            ];
        }

        return $hydrated;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function context_items(array $rows): array
    {
        $hydrated = [];

        foreach ($rows as $context_item) {
            $hydrated[] = [
                'id' => $context_item['id'] ?? 0,
                'item_type' => (string) ($context_item['item_type'] ?? ''),
                'item_id' => $context_item['item_id'] ?? null,
                'label' => (string) ($context_item['label'] ?? ''),
                'payload' => Json::decode_array((string) ($context_item['payload_json'] ?? '')),
                'created_at' => (string) ($context_item['created_at'] ?? ''),
            ];
        }

        return $hydrated;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function actions(array $rows): array
    {
        $hydrated = [];

        foreach ($rows as $action) {
            $hydrated[] = [
                'id' => $action['id'] ?? 0,
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
