<?php

/**
 * Builds REST payloads for discovered tools.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

use AWPT\Agent\ToolRegistry;
use AWPT\MCP\Adapter;
use AWPT\Support\Environment;
use AWPT\Support\ToolPreferences;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Groups abilities/MCP tools for the Tools UI and REST consumers.
 */
final class ToolsPayloadBuilder {
    /**
     * Full discovery payload with schemas.
     *
     * @return array<string, mixed>
     */
    public function full(): array {
        $prefs = new ToolPreferences();
        $core = [];
        $plugin = [];
        $other = [];
        $mcp = [];
        $ability_names = [];

        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $ability) {
                $name = $ability->get_name();
                $item = $this->decorate($this->ability_item_with_schemas($ability), $name, $prefs, 'ability');
                $ability_names[$name] = true;

                if (str_starts_with($name, 'core/')) {
                    $core[] = $item;
                    continue;
                }

                if (str_starts_with($name, 'awpt/')) {
                    $plugin[] = $item;
                    continue;
                }

                $other[] = $item;
            }
        }

        foreach (new Adapter()->list_tools() as $tool) {
            $name = (string) ($tool['name'] ?? '');

            if ('' === $name || array_key_exists($name, $ability_names)) {
                continue;
            }

            $mcp[] = $this->decorate($this->mcp_item($tool), $name, $prefs, 'mcp');
        }

        return $this->wrap($prefs, $core, $plugin, $other, $mcp);
    }

    /**
     * Boot-time payload with AWPT abilities only (no full schemas).
     *
     * @return array<string, mixed>
     */
    public function awpt_only(): array {
        $prefs = new ToolPreferences();
        $plugin = [];

        if (function_exists('wp_get_abilities')) {
            foreach (wp_get_abilities() as $ability) {
                $name = $ability->get_name();

                if (!str_starts_with($name, 'awpt/')) {
                    continue;
                }

                $plugin[] = $this->decorate($this->ability_item_without_schemas($ability), $name, $prefs, 'ability');
            }
        }

        return $this->wrap($prefs, [], $plugin, [], []);
    }

    /**
     * @param list<array<string, mixed>> $core
     * @param list<array<string, mixed>> $plugin
     * @param list<array<string, mixed>> $other
     * @param list<array<string, mixed>> $mcp
     * @return array<string, mixed>
     */
    private function wrap(ToolPreferences $prefs, array $core, array $plugin, array $other, array $mcp): array {
        return [
            'core' => $core,
            'plugin' => $plugin,
            'other' => $other,
            'mcp' => $mcp,
            'disabled' => $prefs->disabled_names(),
            'never_auto' => ToolPreferences::NEVER_AUTO_EXECUTE,
            'agent_enabled_count' => count(new ToolRegistry($prefs)->get_auto_executable_ability_names()),
            'environment' => Environment::status(),
        ];
    }

    /**
     * @param array<string, mixed> $item Tool row.
     * @return array<string, mixed>
     */
    private function decorate(array $item, string $name, ToolPreferences $prefs, string $source): array {
        $never_auto = $prefs->is_never_auto($name);
        $item['source'] = $source;
        $item['never_auto'] = $never_auto;
        $item['enabled'] = !$never_auto && $prefs->is_enabled($name);

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function ability_item_with_schemas(object $ability): array {
        $item = $this->ability_item_without_schemas($ability);
        $item['input_schema'] = method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : null;
        $item['output_schema'] = method_exists($ability, 'get_output_schema') ? $ability->get_output_schema() : null;

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function ability_item_without_schemas(object $ability): array {
        $item = [
            'name' => method_exists($ability, 'get_name') ? (string) $ability->get_name() : '',
            'label' => method_exists($ability, 'get_label') ? (string) $ability->get_label() : '',
            'description' => method_exists($ability, 'get_description') ? (string) $ability->get_description() : '',
            'category' => method_exists($ability, 'get_category') ? (string) $ability->get_category() : '',
            'input_schema' => null,
            'output_schema' => null,
            'permission' => null,
            'readonly' => null,
            'destructive' => null,
            'requires_approval' => null,
        ];

        if (method_exists($ability, 'get_meta')) {
            $meta = $ability->get_meta();

            if (is_array($meta) && is_array($meta['annotations'] ?? null)) {
                $annotations = $meta['annotations'];
                $item['readonly'] = $annotations['readonly'] ?? null;
                $item['destructive'] = $annotations['destructive'] ?? null;
                $item['requires_approval'] = $annotations['requires_approval'] ?? null;
            }
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $tool Normalized MCP tool.
     * @return array<string, mixed>
     */
    private function mcp_item(array $tool): array {
        return [
            'name' => (string) ($tool['name'] ?? ''),
            'label' => (string) ($tool['label'] ?? $tool['name'] ?? ''),
            'description' => (string) ($tool['description'] ?? ''),
            'category' => (string) ($tool['category'] ?? 'mcp'),
            'input_schema' => $tool['input_schema'] ?? null,
            'output_schema' => $tool['output_schema'] ?? null,
            'permission' => $tool['permission'] ?? null,
            'readonly' => $tool['readonly'] ?? null,
            'destructive' => $tool['destructive'] ?? null,
            'requires_approval' => $tool['requires_approval'] ?? null,
        ];
    }
}
