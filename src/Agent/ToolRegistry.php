<?php

/**
 * Agent tool registry.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Abilities\AbilitySchemas;

defined('ABSPATH') || exit();

/**
 * Converts safe AWPT abilities into chat completion tools.
 */
final class ToolRegistry
{
    /**
     * Read-only abilities that may run during provider response generation.
     */
    private const AUTO_TOOL_MAP = [
        'awpt__read_content' => 'awpt/read-content',
        'awpt__read_settings' => 'awpt/read-settings',
        'awpt__read_users' => 'awpt/read-users',
        'awpt__read_block_tree' => 'awpt/read-block-tree',
        'awpt__analyze_page' => 'awpt/analyze-page',
        'awpt__preview_post' => 'awpt/preview-post',
        'awpt__search_knowledge' => 'awpt/search-knowledge',
        'awpt__read_knowledge' => 'awpt/read-knowledge',
        'awpt__propose_content_update' => 'awpt/propose-content-update',
    ];

    /**
     * Return ability names that may run automatically during provider generation.
     *
     * @return list<string>
     */
    public function get_auto_executable_ability_names(): array
    {
        return array_values(self::AUTO_TOOL_MAP);
    }

    /**
     * Return OpenAI-compatible function tools.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_chat_completion_tools(): array
    {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }

        $tools = [];

        foreach (wp_get_abilities() as $ability) {
            $name = $ability->get_name();
            $function_name = $this->function_name_for_tool($name);

            if (null === $function_name) {
                continue;
            }

            $schema = method_exists($ability, 'get_input_schema')
                ? $ability->get_input_schema()
                : AbilitySchemas::empty_object_input();

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $function_name,
                    'description' => $ability->get_description(),
                    'parameters' => AbilitySchemas::normalize_for_provider(
                        is_array($schema) ? $schema : AbilitySchemas::empty_object_input(),
                    ),
                ],
            ];
        }

        return $tools;
    }

    /**
     * Resolve an ability name to a provider function name.
     *
     * @param string $ability_name Ability name.
     */
    public function function_name_for_ability(string $ability_name): ?string
    {
        $function_name = array_search($ability_name, self::AUTO_TOOL_MAP, true);

        if (is_string($function_name)) {
            return $function_name;
        }

        if (array_key_exists($ability_name, self::AUTO_TOOL_MAP)) {
            return $ability_name;
        }

        return null;
    }

    /**
     * Resolve a provider function name to an ability name.
     *
     * @param string $function_name Provider function name.
     */
    public function tool_name_for_function(string $function_name): ?string
    {
        if (array_key_exists($function_name, self::AUTO_TOOL_MAP)) {
            return self::AUTO_TOOL_MAP[$function_name];
        }

        if (class_exists('WP_AI_Client_Ability_Function_Resolver') && str_starts_with($function_name, 'wpab__')) {
            $ability_name = \WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name($function_name);

            return $this->can_auto_execute($ability_name) ? $ability_name : null;
        }

        return null;
    }

    /**
     * Whether an ability is safe for automatic execution.
     *
     * @param string $tool_name Ability name.
     */
    public function can_auto_execute(string $tool_name): bool
    {
        return in_array($tool_name, self::AUTO_TOOL_MAP, true);
    }

    /**
     * Resolve an ability name to a provider function name.
     *
     * @param string $tool_name Ability name.
     */
    private function function_name_for_tool(string $tool_name): ?string
    {
        $function_name = array_search($tool_name, self::AUTO_TOOL_MAP, true);

        return is_string($function_name) ? $function_name : null;
    }
}
