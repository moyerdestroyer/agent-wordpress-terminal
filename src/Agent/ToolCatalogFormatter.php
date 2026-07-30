<?php

/**
 * Tool catalog formatting for provider instructions.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Formats a short system-prompt note about tools.
 *
 * Full tool names, descriptions, and JSON schemas ship in the structured
 * `tools[]` payload on each completion request — do not re-list them here.
 */
final class ToolCatalogFormatter {
    /**
     * Short system-prompt note about tools and staging semantics.
     */
    public function get_system_prompt_catalog(): string {
        return implode("\n", [
            'Callable tools for this turn are provided as function tools (name, description, parameters). Prefer those declarations over inventing tool names.',
            'Natural language is the primary user workflow. Slash shortcuts (/focus, /preview, /knowledge, /tools, /clear) are typed by users and should only be mentioned when users ask for shortcuts or commands.',
            'AWPT write abilities (awpt/propose-*) stage proposed actions for admin approval; never claim a destructive change was applied without approval. awpt/apply-action is human-only.',
            'Discover before answering site-specific questions; do not invent theme paths, pattern slugs, or post IDs.',
        ]);
    }
}
