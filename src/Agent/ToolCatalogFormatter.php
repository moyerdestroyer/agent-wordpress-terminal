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
final class ToolCatalogFormatter {
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
        'awpt/search-content' => 'Finds posts, pages, templates, template parts, and reusable blocks by title, slug, ID, or URL.',
        'awpt/list-content' => 'Lists and browses WordPress content with filters for post type, status, author, and search text, plus totals and item metadata.',
        'awpt/search-knowledge' => 'Searches Core Knowledge, legacy guidelines, site content, and allowed read-only document sources.',
        'awpt/read-knowledge' => 'Reads a specific Core Knowledge or legacy guideline record by WordPress post ID.',
        'awpt/propose-content-update' => 'Stages a proposed post update (title, content, status, or meta) for explicit admin approval.',
        'awpt/propose-block-attrs-update' => 'Stages a targeted Gutenberg block attribute update by block path for explicit admin approval.',
        'awpt/propose-new-post' => 'Stages creation of a brand new post or page for explicit admin approval. Use for new posts, not existing ones. Optional featured_image_id sets the WordPress featured image on apply.',
        'awpt/propose-site-settings-update' => 'Stages safe WordPress site settings changes for explicit admin approval.',
        'awpt/propose-theme-switch' => 'Stages activation of an installed WordPress theme for explicit admin approval.',
        'awpt/propose-plugin-deactivate' => 'Last-resort staged deactivation when diagnosis attributes a fatal PHP error to a third-party plugin.',
        'awpt/read-error-log' => 'Returns recent PHP error log lines from debug.log or the PHP error log.',
        'awpt/read-plugins' => 'Returns installed plugins with activation state for troubleshooting.',
        'awpt/read-site-health' => 'Runs WordPress Site Health checks and returns environment plus test results.',
        'awpt/probe-url' => 'Fetches a same-site URL and extracts rendered PHP or critical error snippets.',
        'awpt/diagnose-error' => 'Analyzes an error with log evidence, suspects, Site Health context, and remediation hints.',
        'awpt/apply-action' => 'Applies an explicitly approved AWPT staged action.',
        'awpt/sideload-media' => 'Downloads a remote image or video URL and adds it to the Media Library, returning its attachment ID and hosted URL. Automatically resolves share/preview page links (e.g. Tenor, Giphy) to their underlying direct media file.',
    ];

    /**
     * Approval-required AWPT abilities.
     *
     * @var list<string>
     */
    private const APPROVAL_REQUIRED_ABILITIES = [
        'awpt/propose-content-update',
        'awpt/propose-block-attrs-update',
        'awpt/propose-new-post',
        'awpt/propose-site-settings-update',
        'awpt/propose-theme-switch',
        'awpt/propose-plugin-deactivate',
        'awpt/apply-action',
    ];

    /**
     * Build a system-prompt catalog of callable tools and secondary user shortcuts.
     */
    public function get_system_prompt_catalog(): string {
        $registry = new ToolRegistry();
        $lines = [
            'Ability names use the awpt/ prefix and are callable via function calling.',
            'Natural language is the primary user workflow. Slash shortcuts are typed by users and should only be mentioned when users ask for shortcuts or commands.',
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

        $lines[] = 'Secondary user shortcuts (do not lead with these unless asked):';
        $lines[] = '- /focus {title|slug|url|id} — set session focus';
        $lines[] = '- /preview {title|slug|url|id} — open a preview';
        $lines[] = '- /knowledge search {query} — search indexed Knowledge';
        $lines[] = '- /tools — list registered abilities and MCP tools';
        $lines[] = '- /mcp status — show MCP connection status';
        $lines[] = '- /clear — clear the transcript';

        if (!function_exists('wp_get_abilities')) {
            $lines[] = 'Note: WordPress Abilities API enumeration is unavailable, but AWPT ability names above are still registered for this plugin.';
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve an ability description from the registry or static fallback.
     */
    private function describe_ability(string $ability_name): string {
        if (function_exists('wp_get_ability')) {
            $ability = wp_get_ability($ability_name);

            if (null !== $ability) {
                return $ability->get_description();
            }
        }

        return self::ABILITY_DESCRIPTIONS[$ability_name] ?? 'AWPT ability.';
    }
}
