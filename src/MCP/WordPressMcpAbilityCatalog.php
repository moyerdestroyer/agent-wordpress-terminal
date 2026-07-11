<?php

/**
 * Discovers MCP-facing WordPress abilities for the AWPT MCP adapter.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\MCP;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lists MCP-public / adapter abilities as tool definitions.
 */
final class WordPressMcpAbilityCatalog {
    private WordPressMcpAbilityToolFactory $factory;

    private WordPressMcpAbilityRunner $runner;

    private WordPressMcpToolList $tool_list;

    public function __construct(
        ?WordPressMcpAbilityToolFactory $factory = null,
        ?WordPressMcpAbilityRunner $runner = null,
        ?WordPressMcpToolList $tool_list = null,
    ) {
        $this->factory = $factory ?? new WordPressMcpAbilityToolFactory();
        $this->runner = $runner ?? new WordPressMcpAbilityRunner();
        $this->tool_list = $tool_list ?? new WordPressMcpToolList();
    }

    /**
     * List MCP-facing abilities as tool definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list_tools(): array {
        if (!function_exists('wp_get_abilities')) {
            return [];
        }

        $tools = [];

        foreach (wp_get_abilities() as $ability) {
            if (!$this->should_expose_ability($ability)) {
                continue;
            }

            $item = $this->factory->from_ability($ability);

            if (null !== $item) {
                $tools[] = $item;
            }
        }

        return $tools;
    }

    /**
     * Execute a tool by running the matching WordPress ability.
     *
     * @param string                  $tool_name Tool / ability name.
     * @param array<array-key, mixed> $input Tool input.
     * @param array<string, mixed>    $tool Normalized tool metadata.
     * @return array<array-key, mixed>|\WP_Error|null Null when this catalog does not own the tool.
     */
    public function execute(string $tool_name, array $input, array $tool): array|\WP_Error|null {
        return $this->runner->execute($tool_name, $input, $tool);
    }

    /**
     * Keep only well-formed existing tool rows (no catalog discovery).
     *
     * @param array<array-key, mixed> $existing Existing tool definitions.
     * @return array<int, array<string, mixed>>
     */
    public function normalize_tools(array $existing): array {
        return $this->tool_list->normalize($existing);
    }

    /**
     * Merge catalog tools into an existing list without duplicate names.
     *
     * @param array<array-key, mixed> $existing Existing tool definitions.
     * @return array<int, array<string, mixed>>
     */
    public function merge_tools(array $existing): array {
        return $this->tool_list->merge($existing, $this->list_tools());
    }

    /**
     * @param object $ability Ability instance from wp_get_abilities().
     */
    private function should_expose_ability(object $ability): bool {
        $name = method_exists($ability, 'get_name') ? (string) $ability->get_name() : '';

        if ('' === $name) {
            return false;
        }

        if ($this->runner->is_adapter_namespace($name)) {
            return true;
        }

        return WordPressMcpMeta::ability_is_public($ability);
    }
}
