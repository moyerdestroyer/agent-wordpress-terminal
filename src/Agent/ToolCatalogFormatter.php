<?php

/**
 * Tool catalog formatting for provider instructions.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\MCP\Adapter;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Formats registered tools for system prompts.
 */
final class ToolCatalogFormatter
{
    /**
     * Fallback descriptions when wp_get_ability() is unavailable.
     *
     * @var array<string, string>
     */
    private const ABILITY_DESCRIPTIONS = [
        'awpt/read-content' => 'Returns readable post or page content and metadata.',
        'awpt/read-settings' => 'Returns non-secret WordPress site settings and environment details.',
        'awpt/read-themes' => 'Returns installed WordPress themes and the active stylesheet.',
        'awpt/read-users' => 'Returns WordPress user summaries without exposing emails or password data.',
        'awpt/read-block-tree' => 'Returns parsed Gutenberg block structure for a post.',
        'awpt/analyze-page' => 'Returns an agent-friendly page brief with structure and risk signals.',
        'awpt/preview-post' => 'Returns preview URL and iframe metadata for a post.',
        'awpt/search-knowledge' => 'Searches Core Knowledge, legacy guidelines, site content, and allowed read-only document sources.',
        'awpt/read-knowledge' => 'Reads a specific Core Knowledge or legacy guideline record by WordPress post ID.',
        'awpt/propose-content-update' => 'Stages a proposed post content update for explicit admin approval.',
        'awpt/propose-site-settings-update' => 'Stages safe WordPress site settings changes for explicit admin approval.',
        'awpt/propose-theme-switch' => 'Stages activation of an installed WordPress theme for explicit admin approval.',
        'awpt/apply-action' => 'Applies an explicitly approved AWPT staged action.',
    ];

    /**
     * Approval-required AWPT abilities.
     *
     * @var list<string>
     */
    private const APPROVAL_REQUIRED_ABILITIES = [
        'awpt/propose-content-update',
        'awpt/propose-site-settings-update',
        'awpt/propose-theme-switch',
        'awpt/apply-action',
    ];

    /**
     * Build a system-prompt catalog of callable tools and slash commands.
     */
    public function get_system_prompt_catalog(): string
    {
        $registry = new ToolRegistry();
        $lines = [
            'Ability names use the awpt/ prefix and are callable via function calling. Slash commands start with / and are typed by the user in the terminal.',
            'When asked what tools you have, list the ability names below first, then mention slash commands separately.',
            'Use awpt/search-knowledge for durable site knowledge, guidelines, memories, notes, indexed content, and allowed document sources.',
            'Write abilities stage proposed actions for admin approval; never claim a destructive change was applied without approval.',
            'Auto-callable ability names:',
        ];

        foreach ($registry->get_auto_executable_ability_names() as $ability_name) {
            $lines[] = sprintf('- %s: %s', $ability_name, $this->describe_ability($ability_name));
        }

        $lines[] = 'Approval-required ability names:';

        foreach (self::APPROVAL_REQUIRED_ABILITIES as $ability_name) {
            $lines[] = sprintf('- %s: %s', $ability_name, $this->describe_ability($ability_name));
        }

        $mcp_tools = new Adapter()->list_tools();

        if ([] !== $mcp_tools) {
            $lines[] = 'Connected MCP tools:';

            foreach ($mcp_tools as $tool) {
                $lines[] = sprintf(
                    '- %s: %s',
                    (string) ($tool['name'] ?? 'unknown'),
                    (string) ($tool['description'] ?? ''),
                );
            }
        }

        $lines[] = 'Slash commands (typed by the user, not ability names):';
        $lines[] = '- /help — list user-facing slash commands';
        $lines[] = '- /focus {id} — set the session focus to a post or page';
        $lines[] = '- /preview {id} — load a post preview in the preview workspace';
        $lines[] = '- /tools — list registered abilities and MCP tools';
        $lines[] = '- /mcp status — show MCP connection status';
        $lines[] = '- /mcp tools — list MCP tools';
        $lines[] = '- /knowledge search {query} — search indexed Knowledge and read-only site sources';
        $lines[] = '- /knowledge read {id} — read a Core Knowledge or legacy guideline item';
        $lines[] = '- /clear — clear the transcript';

        if (!function_exists('wp_get_abilities')) {
            $lines[] = 'Note: WordPress Abilities API enumeration is unavailable, but AWPT ability names above are still registered for this plugin.';
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve an ability description from the registry or static fallback.
     */
    private function describe_ability(string $ability_name): string
    {
        if (function_exists('wp_get_ability')) {
            $ability = wp_get_ability($ability_name);

            if (null !== $ability) {
                return $ability->get_description();
            }
        }

        return self::ABILITY_DESCRIPTIONS[$ability_name] ?? 'AWPT ability.';
    }
}
