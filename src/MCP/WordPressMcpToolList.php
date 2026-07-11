<?php

/**
 * Tool-list normalization and merge helpers for MCP discovery.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\MCP;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Keeps MCP tool arrays de-duplicated and string-keyed.
 */
final class WordPressMcpToolList {
    /**
     * Keep only well-formed existing tool rows.
     *
     * @param array<array-key, mixed> $existing Existing tool definitions.
     * @return array<int, array<string, mixed>>
     */
    public function normalize(array $existing): array {
        $merged = [];
        $seen = [];

        foreach ($existing as $tool) {
            if (!is_array($tool)) {
                continue;
            }

            $row = $this->string_keyed_row($tool);
            $name = $row['name'] ?? null;

            if (!is_string($name) || '' === $name || array_key_exists($name, $seen)) {
                continue;
            }

            $merged[] = $row;
            $seen[$name] = true;
        }

        return $merged;
    }

    /**
     * Append catalog tools onto an existing list without duplicate names.
     *
     * @param array<array-key, mixed>          $existing Existing tool definitions.
     * @param array<int, array<string, mixed>> $catalog Tools discovered from abilities.
     * @return array<int, array<string, mixed>>
     */
    public function merge(array $existing, array $catalog): array {
        $merged = $this->normalize($existing);
        $seen = $this->names_index($merged);

        foreach ($catalog as $tool) {
            $name = $tool['name'] ?? null;

            if (!is_string($name) || '' === $name || array_key_exists($name, $seen)) {
                continue;
            }

            $merged[] = $tool;
            $seen[$name] = true;
        }

        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $tools Normalized tools.
     * @return array<string, true>
     */
    private function names_index(array $tools): array {
        $seen = [];

        foreach ($tools as $tool) {
            $name = $tool['name'] ?? null;

            if (is_string($name) && '' !== $name) {
                $seen[$name] = true;
            }
        }

        return $seen;
    }

    /**
     * @param array<array-key, mixed> $tool Raw tool row.
     * @return array<string, mixed>
     */
    private function string_keyed_row(array $tool): array {
        $row = [];

        foreach ($tool as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $row[$key] = $value;
        }

        return $row;
    }
}
