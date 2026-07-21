<?php

/**
 * User preferences for which discovered tools the agent may auto-call.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Stores a deny-list of tool names (abilities or MCP tools).
 *
 * Default is everything enabled; admins opt out of individual tools in the Tools UI.
 */
final class ToolPreferences {
    public const OPTION = 'awpt_disabled_tools';

    public const TRUSTED_MUTATING_OPTION = 'awpt_trusted_mutating_tools';

    /**
     * Tools that must never be auto-executed by the model (human/REST only).
     *
     * @var list<string>
     */
    public const NEVER_AUTO_EXECUTE = [
        'awpt/apply-action',
    ];

    /**
     * @return list<string>
     */
    public function disabled_names(): array {
        $names = [];

        foreach (ArrayKey::list_of_strings(get_option(self::OPTION, [])) as $name) {
            $clean = sanitize_text_field($name);

            if ('' !== $clean && !$this->is_never_auto($clean)) {
                $names[] = $clean;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Whether the agent may offer/call this tool (unless never-auto).
     */
    public function is_enabled(string $tool_name): bool {
        if ('' === $tool_name) {
            return false;
        }

        return !in_array($tool_name, $this->disabled_names(), true);
    }

    /**
     * Whether this tool is permanently blocked from model auto-execution.
     */
    public function is_never_auto(string $tool_name): bool {
        return in_array($tool_name, self::NEVER_AUTO_EXECUTE, true);
    }

    /**
     * Replace the disabled-tool list.
     *
     * @param array<array-key, mixed> $names Tool names to disable.
     * @return list<string> Sanitized stored list.
     */
    public function set_disabled(array $names): array {
        $clean = [];

        foreach (ArrayKey::list_of_strings($names) as $name) {
            $value = sanitize_text_field($name);

            if ('' === $value || $this->is_never_auto($value)) {
                continue;
            }

            $clean[] = $value;
        }

        $clean = array_values(array_unique($clean));
        update_option(self::OPTION, $clean, false);

        return $clean;
    }

    /** @return list<string> */
    public function trusted_mutating_names(): array {
        $names = [];

        foreach (ArrayKey::list_of_strings(get_option(self::TRUSTED_MUTATING_OPTION, [])) as $name) {
            $clean = sanitize_text_field($name);

            if ('' !== $clean) {
                $names[] = $clean;
            }
        }

        return array_values(array_unique($names));
    }

    public function is_trusted_mutating(string $tool_name): bool {
        return in_array($tool_name, $this->trusted_mutating_names(), true);
    }

    public function set_mutating_trust(string $tool_name, bool $trusted): void {
        $names = array_values(array_filter(
            $this->trusted_mutating_names(),
            static fn(string $name): bool => $name !== $tool_name,
        ));

        if ($trusted && '' !== $tool_name && !$this->is_never_auto($tool_name)) {
            $names[] = sanitize_text_field($tool_name);
        }

        update_option(self::TRUSTED_MUTATING_OPTION, array_values(array_unique($names)), false);
    }

    /**
     * @return list<string> Updated disabled list.
     */
    public function enable_tool(string $tool_name): array {
        $tool_name = sanitize_text_field($tool_name);
        $disabled = array_values(array_filter(
            $this->disabled_names(),
            static fn(string $name): bool => $name !== $tool_name,
        ));

        return $this->set_disabled($disabled);
    }

    /**
     * @return list<string> Updated disabled list.
     */
    public function disable_tool(string $tool_name): array {
        $tool_name = sanitize_text_field($tool_name);
        $disabled = $this->disabled_names();

        if ('' === $tool_name || $this->is_never_auto($tool_name) || in_array($tool_name, $disabled, true)) {
            return $this->set_disabled($disabled);
        }

        $disabled[] = $tool_name;

        return $this->set_disabled($disabled);
    }
}
