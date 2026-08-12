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

    /**
     * Stable marker for act turns (execute an approved plan). Must remain at the
     * start of act_text(); do not translate.
     */
    public const ACT_MARKER = '[awpt:improve_act]';

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
            . 'Map existing copy into the new structure and replace required authoring placeholders with credible copy. '
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
            . '3. For each suggested change, name the least-destructive soft operation: '
            . 'batch/attrs (copy-only), prepare-pattern-change mode=replace, prepare-pattern-change mode=insert / pattern-insert, '
            . "or no change. Prefer section-level work over whole-page freehand.\n"
            . "4. Call out dynamic sections (preserve_by_default) and links/numbers that must carry forward.\n"
            . '5. End with a compact markdown plan the next turn can execute. '
            . 'Group work into coherent phases when needed; do not force incompatible mutations into one batch. '
            . 'If nothing needs changing, say so clearly.',
            'agent-wordpress-terminal',
        );

        return self::EVALUATE_MARKER . "\n" . $body;
    }

    /**
     * Step 2: execute a plan from the evaluate turn (bridge/CLI append the plan).
     */
    public static function act_text(): string {
        $body = __(
            "Execute the plan below for this focused page.\n\n"
            . "The plan is authoritative. Do not re-evaluate what is wrong with the page or restart open-ended discovery.\n\n"
            . "1. Trust the plan’s operations, paths, and preserve/carry-forward list.\n"
            . '2. At most one targeted re-read if fingerprints are missing (read-block-tree or get-block on named paths only). '
            . "Do not call find-abilities, re-list every block, or re-read theme docs unless the plan requires a specific pattern name you have not loaded.\n"
            . '3. Stage the least-destructive ops the plan named: batch/attrs and prepare-pattern-change → propose-pattern-replace/insert first. '
            . "Prefer one coherent staging proposal that advances the first incomplete phase(s)—not a full freehand rewrite of every phase.\n"
            . 'For block batches, each path may have only one non-insertion mutation. When one block needs both attributes and rich text changed, use one update_block change with attrs and content; never send separate update_attrs and replace_text changes for that path. '
            . "If later plan work is incompatible with the current proposal, stage the coherent safe phase instead of forcing it into an invalid batch.\n"
            . "4. Map existing copy into slots; use carry_forward for links and numbers, and replace required authoring placeholders before staging.\n"
            . "5. Full-document freehand propose-content-update only if the plan says no pattern fits or preparation returns custom_fallback.\n"
            . '6. Do not invent preparation_id values — call prepare first when using propose-pattern-replace/insert.',
            'agent-wordpress-terminal',
        );

        return self::ACT_MARKER . "\n" . $body;
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

    public static function is_act_message(string $message): bool {
        return str_starts_with(trim($message), self::ACT_MARKER);
    }

    /**
     * Expand a terminal slash command into an agent user message, or null if not a plan/improve alias.
     *
     * Supported:
     * - /plan [notes], /evaluate [notes] → evaluate-only (same isolation as review-queue step 1)
     * - /improve [notes] → legacy one-shot redesign brief (terminal UI may run evaluate→act instead)
     *
     * @return array{command: string, message: string}|null
     */
    public static function expand_slash_command(string $message): ?array {
        $trimmed = trim($message);

        if (!str_starts_with($trimmed, '/')) {
            return null;
        }

        $split = preg_split('/\s+/', $trimmed, 2);
        $parts = is_array($split) ? $split : [$trimmed];
        $command = strtolower(array_shift($parts) ?? '');
        $rest = trim(array_shift($parts) ?? '');

        return match ($command) {
            '/plan', '/evaluate' => [
                'command' => 'plan',
                'message' => self::evaluate_with_notes($rest),
            ],
            '/improve' => [
                'command' => 'improve',
                'message' => self::evaluate_with_notes($rest),
            ],
            default => null,
        };
    }

    /**
     * Evaluate brief plus optional operator notes (still evaluate-isolated).
     */
    public static function evaluate_with_notes(string $notes = ''): string {
        $base = self::evaluate_text();
        $notes = trim($notes);

        if ('' === $notes) {
            return $base;
        }

        return $base
        . "\n\n"
        . sprintf(
            /* translators: %s: free-form notes from the operator */
            __('Additional operator notes: %s', 'agent-wordpress-terminal'),
            $notes,
        );
    }

    /**
     * One-shot improve brief plus optional notes.
     */
    public static function text_with_notes(string $notes = ''): string {
        $base = self::text();
        $notes = trim($notes);

        if ('' === $notes) {
            return $base;
        }

        return $base
        . "\n\n"
        . sprintf(
            /* translators: %s: free-form notes from the operator */
            __('Additional operator notes: %s', 'agent-wordpress-terminal'),
            $notes,
        );
    }
}
