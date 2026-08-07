<?php

/**
 * Canonical Improve briefs for focused review-queue pages.
 *
 * Evaluate → act are separate concerns; Dufresne never owns these strings.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Single source of truth for "Improve this page" natural-language briefs.
 *
 * Used by the review bridge (via awptSettings), CLI queue helper, and any host
 * that should stay aligned without duplicating the string in TypeScript.
 */
final class ImprovePagePrompt {
    /**
     * Stable marker for evaluate-only turns (TurnProfile / runtime isolation).
     * Must remain at the start of evaluate_text(); do not translate.
     */
    public const EVALUATE_MARKER = '[awpt:improve_evaluate]';

    public const PROMPT_VERSION_LEGACY = 'improve-page-v2';

    public const PROMPT_VERSION_TWO_STEP = 'improve-page-eval-act-v1';

    /**
     * Legacy one-shot redesign brief (S9 baseline / --one-shot).
     */
    public static function text(): string {
        return __(
            "Redesign this focused page using active-theme patterns.\n\n"
            . 'Read the current page (and block paths/fingerprints when targeting a section). '
            . 'Prefer prepare-pattern-change → propose-pattern-replace for structural section swaps, '
            . 'or propose-pattern-insert for additions. Use surgical block edits for copy-only fixes. '
            . 'Map existing copy into the new structure where it fits. Placeholders are fine for empty slots. '
            . 'Do not invent facts or Media Library URLs. Prefer theme patterns when they fit; '
            . 'a full-document freehand rewrite is fine when no pattern fits.',
            'agent-wordpress-terminal',
        );
    }

    /**
     * Step 1: discover intent and emit a short plan only (no staging).
     */
    public static function evaluate_text(): string {
        $body = __(
            "Evaluate this focused page and produce a short execution plan only.\n\n"
            . "You must NOT stage proposals, call propose-* tools, or rewrite the page in this turn.\n\n"
            . "1. Read the page (prefer awpt/read-block-tree for top_level_sections: path, role, heading, preserve_by_default; use awpt/analyze-page if helpful).\n"
            . "2. Summarize what to keep vs improve (be concrete; do not invent facts or Media Library URLs).\n"
            . "3. For each suggested change, name the least-destructive soft operation: "
            . "batch/attrs (copy-only), prepare-pattern-change mode=replace, prepare-pattern-change mode=insert / pattern-insert, "
            . "or no change. Prefer section-level work over whole-page freehand.\n"
            . "4. Call out dynamic sections (preserve_by_default) and links/numbers that must carry forward.\n"
            . "5. End with a compact markdown plan the next turn can execute. "
            . 'If nothing needs changing, say so clearly.',
            'agent-wordpress-terminal',
        );

        return self::EVALUATE_MARKER . "\n" . $body;
    }

    /**
     * Step 2: execute a plan from the evaluate turn (bridge/CLI append the plan).
     */
    public static function act_text(): string {
        return __(
            "Execute the plan below for this focused page.\n\n"
            . 'Follow the plan’s least-destructive operations. Prefer prepare-pattern-change → '
            . 'propose-pattern-replace for section swaps, or prepare-pattern-change mode=insert / propose-pattern-insert for additions. '
            . 'Use surgical block edits for copy-only items. Map existing copy into pattern slots; use carry_forward for links and numbers. '
            . 'Placeholders are fine for empty slots. Do not invent facts or Media Library URLs. '
            . 'Full-document freehand is only appropriate when the plan says no pattern fits. '
            . 'Do not invent preparation_id values — call prepare first when using propose-pattern-replace/insert.',
            'agent-wordpress-terminal',
        );
    }

    /**
     * Full act user message: act brief + plan body.
     */
    public static function act_message(string $plan): string {
        $plan = trim($plan);

        if ('' === $plan) {
            return self::text();
        }

        return self::act_text() . "\n\n## Plan\n" . $plan;
    }

    public static function is_evaluate_message(string $message): bool {
        return str_starts_with(trim($message), self::EVALUATE_MARKER);
    }
}
