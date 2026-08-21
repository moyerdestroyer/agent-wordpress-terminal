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

    public const PROMPT_VERSION_TWO_STEP = 'improve-page-eval-act-v5';

    /**
     * Legacy one-shot improve brief (S9 baseline / --one-shot).
     */
    public static function text(): string {
        return __(
            "Improve this focused page.\n\n"
            . 'Read the page. Keep what already works. Stage only changes that would actually help.',
            'agent-wordpress-terminal',
        );
    }

    /**
     * Step 1: open-ended improve request. Tool names and unit schema live
     * in the evaluate system module, not here.
     */
    public static function evaluate_text(): string {
        $body = __(
            "Improve this focused page.\n\n"
            . 'Read the page, then write a short plan the next turn can execute. '
            . 'Do not stage changes in this turn. Keep what already works. If nothing should change, say so.',
            'agent-wordpress-terminal',
        );

        return self::EVALUATE_MARKER . "\n" . $body;
    }

    /**
     * One-click Review request. Keep this short: the button cannot know
     * which copy or structure the operator wants to keep.
     */
    public static function review_brief(): string {
        return __('Improve this page.', 'agent-wordpress-terminal');
    }

    /**
     * Review-queue evaluate message: plan request + one-click brief + notes.
     */
    public static function review_evaluate_message(int $post_id, string $title = '', string $notes = ''): string {
        $page_title = trim($title);

        if ('' === $page_title) {
            $page_title = __('Untitled', 'agent-wordpress-terminal');
        }

        $message =
            self::evaluate_text()
            . "\n\n## Review queue context\nFocused post: #"
            . $post_id
            . "\nFocused title: "
            . $page_title
            . "\n\n"
            . self::review_brief();
        $notes = trim($notes);

        if ('' !== $notes) {
            $message .= "\n\n## Reviewer request\n" . $notes;
        }

        return $message;
    }

    /**
     * Step 2: execute a plan from the evaluate turn (bridge/CLI append the plan).
     */
    public static function act_text(): string {
        $body = __(
            "Execute the plan below for this focused page.\n\n"
            . "The plan is authoritative. Do not re-evaluate what is wrong with the page or restart open-ended discovery.\n\n"
            . "1. Trust the plan’s operations, paths, and preserve/carry-forward list. Do not re-discover the design system.\n"
            . '2. At most one targeted re-read if fingerprints are missing (read-block-tree or get-block on named paths only). '
            . "Do not call find-abilities, re-list every block, or re-read theme docs unless the plan requires a specific pattern name you have not loaded.\n"
            . '3. Stage the ops the plan named. For surgical work call awpt/propose-block-batch-update with kind set, remove, or insert (attrs and/or html on set; copy expected_fingerprint from the tree). For a section swap or add, call propose-pattern-replace or propose-pattern-insert with path and intent — the server prepares. '
            . "Prefer one coherent staging proposal that completes this unit without leaving placeholder or deferred content.\n"
            . "If the unit cannot be staged safely and completely, return the structured tool failure instead of staging a partial phase.\n"
            . "4. Map existing copy into slots; use carry_forward for links and numbers, preserve source block structure, and replace required authoring placeholders before staging.\n"
            . "5. Full-document freehand propose-content-update only if the plan says no pattern fits or preparation returns custom_fallback.\n"
            . '6. Do not invent preparation_id values.',
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

    /**
     * Act brief for one focused unit. The evaluate plan remains in durable
     * session history, so resending it here would duplicate the cached prefix.
     *
     * @param array<string, mixed> $unit
     */
    public static function act_message_for_unit(array $unit, string $plan = ''): string {
        $normalized = self::normalize_unit($unit);
        $encoded = wp_json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $plan = trim($plan);

        if (!is_string($encoded) || '[]' === $encoded || '{}' === $encoded) {
            $brief = trim((string) ($unit['brief'] ?? $unit['title'] ?? ''));

            if ('' !== $plan) {
                return self::act_message($plan);
            }

            return '' !== $brief ? self::act_message($brief) : self::text();
        }

        $parts = [
            self::act_text(),
            '',
            __(
                'Execute only this unit. Do not stage later units or reopen page-wide diagnosis.',
                'agent-wordpress-terminal',
            ),
        ];

        $parts[] = '';
        $parts[] = "## Unit\n```awpt-unit\n" . $encoded . "\n```";

        return implode("\n", $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function parse_units(string $text): array {
        $matches = [];

        if (preg_match('/```awpt-units\s*(.*?)\s*```/s', $text, $matches) === 1) {
            return self::normalize_units(json_decode(trim($matches[1]), true));
        }

        $blocks = [];
        $block_count = preg_match_all('/```json\s*(.*?)\s*```/s', $text, $blocks);

        if (false !== $block_count && $block_count > 0) {
            foreach (array_reverse($blocks[1]) as $json) {
                $units = self::normalize_units(json_decode(trim($json), true));

                if ([] !== $units) {
                    return $units;
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $workflow
     * @return array<string, mixed>|null
     */
    public static function current_unit(array $workflow): ?array {
        $units = self::normalize_units($workflow['units'] ?? null);
        $cursor = max(0, (int) ($workflow['cursor'] ?? 0));

        return $units[$cursor] ?? null;
    }

    public static function unit_op_from_act_message(string $message): string {
        $matches = [];

        if (preg_match('/```awpt-unit\s*(\{.*?\})\s*```/s', $message, $matches) !== 1) {
            return '';
        }

        $decoded = json_decode($matches[1], true);
        $unit = self::normalize_unit(is_array($decoded) ? $decoded : []);

        return (string) ($unit['op'] ?? '');
    }

    public static function is_fallback_evaluate_plan(string $text): bool {
        $text = trim($text);

        return (
            str_contains($text, '[awpt:plan_failed]')
            || str_contains($text, 'after evaluate tool budget was exhausted')
            || str_contains($text, '### Recommended next ops')
            && str_contains($text, 'No change if evidence shows the page is already fine')
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function units_from_plan(string $plan): array {
        $plan = trim($plan);

        if ('' === $plan) {
            return [];
        }

        if (self::is_fallback_evaluate_plan($plan)) {
            return [];
        }

        return self::parse_units($plan);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function normalize_units(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        if (isset($value['awpt-units']) && is_array($value['awpt-units'])) {
            $value = $value['awpt-units'];
        }

        $out = [];

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $unit = self::normalize_unit($row);

            if ('' === (string) ($unit['op'] ?? '')) {
                continue;
            }

            $out[] = $unit;
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalize_unit(array $row): array {
        $op = sanitize_key((string) ($row['op'] ?? ''));

        if (!in_array($op, ['batch', 'pattern_replace', 'pattern_insert', 'none'], true)) {
            $op = '';
        }

        $paths = [];

        foreach (is_array($row['paths'] ?? null) ? $row['paths'] : [] as $path) {
            $path = trim((string) $path);

            if ('' !== $path) {
                $paths[] = $path;
            }
        }

        foreach (['path', 'target_path', 'block_path'] as $path_key) {
            $path = trim((string) ($row[$path_key] ?? ''));

            if ('' !== $path) {
                $paths[] = $path;
            }
        }

        $id = sanitize_key((string) ($row['id'] ?? ''));

        if ('' === $id || 'unit' === $id) {
            if (isset($row['unit'])) {
                $id = sanitize_key('unit-' . (string) $row['unit']);
            } elseif ('' !== (string) ($row['label'] ?? '')) {
                $id = sanitize_key((string) $row['label']);
            } else {
                $id = 'unit';
            }
        }

        $title = sanitize_text_field((string) ($row['title'] ?? $row['label'] ?? ''));
        $fingerprint = sanitize_text_field((string) ($row['expected_fingerprint'] ?? $row['fingerprint'] ?? ''));
        $brief_parts = [];

        foreach ([$row['brief'] ?? '', $row['description'] ?? '', $row['changes'] ?? ''] as $part) {
            if (!(is_string($part) && '' !== trim($part))) {
                continue;
            }

            $brief_parts[] = trim($part);
        }

        if (isset($row['operations']) && is_array($row['operations']) && [] !== $row['operations']) {
            $encoded_ops = wp_json_encode($row['operations'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if (is_string($encoded_ops) && '[]' !== $encoded_ops) {
                $brief_parts[] = $encoded_ops;
            }
        }

        $pattern_name = sanitize_text_field((string) ($row['pattern_name'] ?? $row['pattern'] ?? ''));

        $unit = [
            'id' => $id,
            'title' => $title,
            'op' => $op,
            'paths' => array_values(array_unique($paths)),
            'expected_fingerprint' => $fingerprint,
            'pattern_name' => $pattern_name,
            'carry_forward' => self::string_list($row['carry_forward'] ?? null),
            'do_not' => self::string_list($row['do_not'] ?? null),
            'brief' => implode("\n", array_values(array_unique($brief_parts))),
        ];

        return self::apply_unit_defaults($unit);
    }

    /**
     * Normalize aliases; do not invent a path when the model omitted one.
     *
     * @param array<string, mixed> $unit
     * @return array<string, mixed>
     */
    public static function apply_unit_defaults(array $unit): array {
        $unit = [
            'id' => (string) ($unit['id'] ?? 'unit'),
            'title' => (string) ($unit['title'] ?? ''),
            'op' => (string) ($unit['op'] ?? ''),
            'paths' => is_array($unit['paths'] ?? null) ? array_values($unit['paths']) : [],
            'expected_fingerprint' => (string) ($unit['expected_fingerprint'] ?? ''),
            'pattern_name' => (string) ($unit['pattern_name'] ?? ''),
            'carry_forward' => self::string_list($unit['carry_forward'] ?? null),
            'do_not' => self::string_list($unit['do_not'] ?? null),
            'brief' => (string) ($unit['brief'] ?? ''),
        ];

        if (in_array($unit['op'], ['pattern_replace', 'pattern_insert'], true)) {
            $normalized_paths = [];

            foreach ($unit['paths'] as $path) {
                $normalized = self::normalize_unit_path((string) $path);

                if (null === $normalized) {
                    continue;
                }

                $normalized_paths[] = $normalized;
            }

            $unit['paths'] = array_values(array_unique($normalized_paths));
        }

        return $unit;
    }

    /**
     * @return string|null Normalized dotted path, or null when the token is not a path.
     */
    public static function normalize_unit_path(string $path): ?string {
        $path = trim($path, "[] \t\"'");

        if ('' === $path) {
            return null;
        }

        $alias = strtolower($path);

        if ('document' === $alias) {
            return 'document';
        }

        if (1 === preg_match('/^\d+(?:\.\d+)*$/', $path)) {
            return $path;
        }

        return null;
    }

    /**
     * Whether a normalized unit has enough fields for act to execute without thrash.
     *
     * @param array<string, mixed> $unit
     */
    public static function unit_is_complete(array $unit): bool {
        $raw_paths = is_array($unit['paths'] ?? null) ? $unit['paths'] : [];
        $unit = self::apply_unit_defaults(self::normalize_unit($unit));
        $op = (string) ($unit['op'] ?? '');

        if ('none' === $op) {
            return true;
        }

        if ('' === $op) {
            return false;
        }

        $brief = trim((string) ($unit['brief'] ?? ''));
        $title = trim((string) ($unit['title'] ?? ''));
        $has_description = '' !== $brief || '' !== $title;

        if ('batch' === $op) {
            return $has_description;
        }

        if (in_array($op, ['pattern_replace', 'pattern_insert'], true)) {
            $paths = is_array($unit['paths'] ?? null) ? $unit['paths'] : [];
            $pattern_name = trim((string) ($unit['pattern_name'] ?? ''));

            // Empty paths must stay incomplete — do not treat silence as path 0.
            if ([] === $raw_paths || [] === $paths) {
                return false;
            }

            foreach ($raw_paths as $path) {
                if (null === self::normalize_unit_path((string) $path)) {
                    return false;
                }
            }

            return $has_description && '' !== $pattern_name;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $units
     * @return list<array<string, mixed>>
     */
    public static function incomplete_units(array $units): array {
        $incomplete = [];

        foreach ($units as $unit) {
            if (self::unit_is_complete($unit)) {
                continue;
            }

            $incomplete[] = self::apply_unit_defaults(self::normalize_unit($unit));
        }

        return $incomplete;
    }

    /**
     * Human-readable nits for incomplete units (for an evaluate repair hop).
     *
     * @param list<array<string, mixed>> $units
     * @return list<string>
     */
    public static function units_completeness_nits(
        array $units,
        string $plan = '',
        int $top_level_section_count = 0,
    ): array {
        $nits = [];

        foreach (array_values($units) as $index => $unit) {
            if (self::unit_is_complete($unit)) {
                continue;
            }

            $source = self::normalize_unit($unit);
            $source_paths = is_array($source['paths'] ?? null) ? $source['paths'] : [];
            $unit = self::apply_unit_defaults($source);
            $op = (string) ($unit['op'] ?? '');
            $label = trim((string) ($unit['title'] ?? $unit['id'] ?? ''));
            $prefix = sprintf('Unit %d%s', $index + 1, '' !== $label ? " ({$label})" : '');

            if ('' === $op) {
                $nits[] = $prefix . ': set op to batch, pattern_replace, pattern_insert, or none.';
                continue;
            }

            if ('batch' === $op) {
                $nits[] = $prefix . ': batch needs a non-empty brief or title.';
                continue;
            }

            if (in_array($op, ['pattern_replace', 'pattern_insert'], true)) {
                $missing = [];

                if ([] === $source_paths) {
                    $missing[] = 'paths (use ["document"] for a full-page change — empty paths are rejected)';
                } else {
                    foreach ($source_paths as $path) {
                        if (null !== self::normalize_unit_path((string) $path)) {
                            continue;
                        }

                        $encoded_path = wp_json_encode((string) $path);
                        $missing[] = sprintf(
                            'valid paths (rejected %s; use a dotted numeric section path or document)',
                            false === $encoded_path ? '""' : $encoded_path,
                        );
                        break;
                    }
                }

                if ('' === trim((string) ($unit['brief'] ?? '')) && '' === trim((string) ($unit['title'] ?? ''))) {
                    $missing[] = 'brief or title';
                }

                if ('' === trim((string) ($unit['pattern_name'] ?? ''))) {
                    $missing[] = 'pattern_name';
                }

                if ([] !== $missing) {
                    $nits[] = $prefix . ': add ' . implode(', ', $missing) . '.';
                }
            }
        }

        return array_values(array_merge($nits, self::plan_structure_nits($plan, $units, $top_level_section_count)));
    }

    /**
     * Reject phantom multi-step essays that only fence a single pattern replace.
     *
     * @param list<array<string, mixed>> $units
     * @return list<string>
     */
    public static function plan_structure_nits(string $plan, array $units, int $top_level_section_count = 0): array {
        $nits = [];
        $units = self::normalize_units($units);
        $count = count($units);

        if (0 === $count) {
            return $nits;
        }

        $blob = mb_strtolower($plan . ' ' . self::units_text_blob($units), 'UTF-8');
        $defers = (bool) preg_match(
            '/subsequent units?\s+will|follow[- ]on units?|after (?:the )?pattern is placed|'
            . 'later units?\s+will|then populate|populate the intro|adjust headings from|'
            . 'remove q\/?a|clean up raw html/i',
            $blob,
        );

        if ($defers && $count < 2) {
            $nits[] =
                'The plan defers work to later units, but the awpt-units fence has only one unit. '
                . 'Add the real follow-on units, or make this unit complete without deferred work.';
        }

        $first = $units[0];
        $first_op = (string) ($first['op'] ?? '');
        $layout_only = self::unit_is_layout_only($first);

        if ($layout_only) {
            $nits[] =
                'Layout-only / content-incomplete units are not executable. '
                . 'Map the source content into the layout in this unit, or choose a different complete operation.';
        }

        if (
            1 === $count
            && 'pattern_replace' === $first_op
            && $top_level_section_count >= 8
            && !in_array('document', is_array($first['paths'] ?? null) ? $first['paths'] : [], true)
        ) {
            $nits[] = sprintf(
                'A full-page pattern replace on a page with %d top-level sections must use paths ["document"]. '
                . 'Path 0 names the first section, not the document.',
                $top_level_section_count,
            );
        }

        return $nits;
    }

    /**
     * @param array<string, mixed> $unit
     */
    public static function unit_is_layout_only(array $unit): bool {
        $unit = self::normalize_unit($unit);
        $blob = mb_strtolower(
            trim((string) ($unit['brief'] ?? '')) . ' ' . trim((string) ($unit['title'] ?? '')),
            'UTF-8',
        );

        return (bool) preg_match(
            '/\blayout[- ]only\b|\bchrome[- ]only\b|\bcontent incomplete\b|\bskeleton only\b/i',
            $blob,
        );
    }

    /**
     * Compact top-level tree evidence from evaluate tool calls for the workflow.
     *
     * @param array<int, array<string, mixed>> $tool_calls
     * @return array{top_level_section_count: int, sections: list<array{path: string, heading: string, role: string}>}
     */
    public static function tree_snapshot_from_tool_calls(array $tool_calls): array {
        $sections = [];

        foreach ($tool_calls as $call) {
            if ('success' !== (string) ($call['status'] ?? '')) {
                continue;
            }

            $tool = (string) ($call['tool'] ?? '');

            if (
                'awpt/read-block-tree' !== $tool
                && 'wpab__awpt__read-block-tree' !== $tool
                && !str_ends_with($tool, 'read-block-tree')
            ) {
                continue;
            }

            $output = is_array($call['output'] ?? null) ? $call['output'] : [];
            $top = is_array($output['top_level_sections'] ?? null) ? $output['top_level_sections'] : [];

            if ([] === $top) {
                continue;
            }

            $sections = [];

            foreach ($top as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $path = sanitize_text_field((string) ($row['path'] ?? ''));

                if ('' === $path) {
                    continue;
                }

                $sections[] = [
                    'path' => $path,
                    'heading' => sanitize_text_field((string) ($row['heading'] ?? '')),
                    'role' => sanitize_key((string) ($row['role'] ?? '')),
                ];
            }
        }

        return [
            'top_level_section_count' => count($sections),
            'sections' => array_slice($sections, 0, 24),
        ];
    }

    /**
     * @param list<array<string, mixed>> $units
     */
    private static function units_text_blob(array $units): string {
        $parts = [];

        foreach ($units as $unit) {
            $parts[] = (string) ($unit['brief'] ?? '');
            $parts[] = (string) ($unit['title'] ?? '');
        }

        return implode(' ', $parts);
    }

    /** True when staged pattern markup still shows theme instructional chrome. */
    public static function staged_content_looks_chrome_incomplete(string $content): bool {
        $plain = wp_strip_all_tags($content);

        return (bool) preg_match(
            '/Section heading \(h[23]\)|Subsection heading|Section \d+\b|page heading communicates|'
            . 'particulars of your body copy|Read the full documentation|side navigation on the component page/i',
            $plain,
        );
    }

    /**
     * Merge a repaired awpt-units fence into the prior evaluate essay when the
     * repair hop returns fence-only text.
     */
    public static function merge_repaired_units_into_plan(string $plan, string $repair): string {
        $plan = trim($plan);
        $repair = trim($repair);

        if ('' === $repair) {
            return $plan;
        }

        $repaired_units = self::parse_units($repair);

        if ([] === $repaired_units) {
            return $repair;
        }

        $encoded = wp_json_encode($repaired_units, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($encoded) || '' === $encoded) {
            return $plan;
        }

        $fence = "```awpt-units\n" . $encoded . "\n```";

        if (preg_match('/```awpt-units\s*.*?\s*```/s', $plan) === 1) {
            return trim((string) preg_replace_callback(
                '/```awpt-units\s*.*?\s*```/s',
                static fn(): string => $fence,
                $plan,
                1,
            ));
        }

        if (preg_match('/```json\s*.*?\s*```/s', $plan) === 1) {
            return trim((string) preg_replace_callback(
                '/```json\s*.*?\s*```/s',
                static fn(): string => $fence,
                $plan,
                1,
            ));
        }

        return trim($plan . "\n\n" . $fence);
    }

    /**
     * Replace a stale evaluate body with the current brief. Rebuild review-queue
     * context from the focused post so a cached client string cannot pin an old plan.
     */
    public static function refresh_evaluate_message(string $message): string {
        $message = trim($message);

        if (!self::is_evaluate_message($message)) {
            return $message;
        }

        $matches = [];

        if (
            preg_match(
                '/## Review queue context\s*\nFocused post: #(\d+)\s*\nFocused title: ([^\n]*)/s',
                $message,
                $matches,
            ) === 1
        ) {
            $notes = '';
            $note_matches = [];

            if (preg_match('/## Reviewer request\n(.*)$/s', $message, $note_matches) === 1) {
                $notes = trim($note_matches[1]);
            }

            return self::review_evaluate_message((int) $matches[1], trim($matches[2]), $notes);
        }

        $suffix = '';

        if (preg_match('/\nAdditional operator notes:.*$/s', $message, $matches) === 1) {
            $suffix .= "\n\n" . ltrim($matches[0]);
        }

        return self::evaluate_text() . $suffix;
    }

    /**
     * @return list<string>
     */
    private static function string_list(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $encoded = wp_json_encode($item);
                $item = is_string($encoded) ? $encoded : '';
            } elseif (is_scalar($item) || null === $item) {
                $item = (string) $item;
            } else {
                continue;
            }

            $item = trim($item);

            if ('' !== $item) {
                $out[] = $item;
            }
        }

        return $out;
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
     * - /improve [notes] → evaluate-only (same isolation as /plan; terminal UI may then act)
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
