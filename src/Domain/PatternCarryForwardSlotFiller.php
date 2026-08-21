<?php

/**
 * Maps prepare carry_forward into editable pattern slots when the model misses paths.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Server-side slot fill so pattern_replace can stage without inventing layout markup.
 * Prefer model-supplied updates; only synthesize from carry_forward when needed.
 * Reject invented semantic paths (intro_paragraph) instead of silently ignoring them.
 */
final class PatternCarryForwardSlotFiller {
    /**
     * @param array<string, mixed> $receipt
     * @param list<mixed> $pattern_text_updates
     * @return list<array{block_path: string, content: string}>|\WP_Error
     */
    public function resolve_updates(array $receipt, array $pattern_text_updates): array|\WP_Error {
        $content = (string) ($receipt['pattern_content'] ?? '');
        $slots = '' !== trim($content) ? new PatternEditableSlots()->from_content($content) : [];
        $by_path = [];
        $by_slot_id = [];

        foreach ($slots as $slot) {
            $path = trim((string) ($slot['block_path'] ?? ''));

            if ('' !== $path) {
                $by_path[$path] = $slot;
            }

            $slot_id = sanitize_key((string) ($slot['slot_id'] ?? ''));

            if ('' !== $slot_id) {
                $by_slot_id[$slot_id] = $slot;
            }
        }

        $invalid = $this->invalid_model_updates($pattern_text_updates, $by_path, $by_slot_id);

        if ([] !== $invalid) {
            $paths = array_values(array_unique(array_map(
                static fn(array $row): string => (string) ($row['block_path'] ?? $row['slot_id'] ?? ''),
                $invalid,
            )));

            return new \WP_Error(
                'awpt_pattern_text_path_invalid',
                __(
                    'pattern_text_updates must use dotted numeric block_path values from editable_slots (or a known slot_id). Invented names like intro_paragraph are rejected.',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 400,
                    'invalid_updates' => $invalid,
                    'invalid_paths' => $paths,
                    'editable_slots' => array_values(array_map(static fn(array $slot): array => array_filter(
                        [
                            'block_path' => (string) ($slot['block_path'] ?? ''),
                            'slot_id' => (string) ($slot['slot_id'] ?? ''),
                            'block_name' => (string) ($slot['block_name'] ?? ''),
                            'label' => (string) ($slot['label'] ?? ''),
                        ],
                        static fn(string $value): bool => '' !== $value,
                    ), $slots)),
                ],
            );
        }

        $resolved = [];

        foreach ($pattern_text_updates as $update) {
            if (!is_array($update)) {
                continue;
            }

            $path = trim((string) ($update['block_path'] ?? ''));
            $slot_id = sanitize_key((string) ($update['slot_id'] ?? ''));
            $text = trim((string) ($update['content'] ?? ''));

            if ('' === $text) {
                continue;
            }

            if ('' !== $path && isset($by_path[$path])) {
                $resolved[] = ['block_path' => $path, 'content' => $text];
                continue;
            }

            if ('' !== $slot_id && isset($by_slot_id[$slot_id])) {
                $slot_path = trim((string) ($by_slot_id[$slot_id]['block_path'] ?? ''));

                if ('' !== $slot_path) {
                    $resolved[] = ['block_path' => $slot_path, 'content' => $text];
                }
            }
        }

        $carry = ArrayKey::as_map($receipt['carry_forward'] ?? null);
        $heading = trim((string) ($carry['heading'] ?? ''));
        $excerpt = trim((string) ($carry['excerpt'] ?? ''));

        if ([] !== $resolved) {
            return $this->clear_remaining_instructional_slots($slots, $resolved, $heading, $excerpt);
        }

        return $this->from_carry_forward($receipt, $slots);
    }

    /**
     * @param list<mixed> $pattern_text_updates
     * @param array<string, array<string, mixed>> $by_path
     * @param array<string, array<string, mixed>> $by_slot_id
     * @return list<array<string, mixed>>
     */
    private function invalid_model_updates(array $pattern_text_updates, array $by_path, array $by_slot_id): array {
        $invalid = [];

        foreach ($pattern_text_updates as $index => $update) {
            if (!is_array($update)) {
                continue;
            }

            $text = trim((string) ($update['content'] ?? ''));

            if ('' === $text) {
                continue;
            }

            $path = trim((string) ($update['block_path'] ?? ''));
            $slot_id = sanitize_key((string) ($update['slot_id'] ?? ''));

            if ('' !== $slot_id && isset($by_slot_id[$slot_id])) {
                continue;
            }

            if ('' !== $path && isset($by_path[$path])) {
                continue;
            }

            $invalid[] = [
                'update_index' => (int) $index,
                'block_path' => $path,
                'slot_id' => $slot_id,
                'reason' =>
                    '' !== $path && 1 !== preg_match('/^\d+(?:\.\d+)*$/', $path)
                        ? 'non_numeric_path'
                        : 'path_not_in_editable_slots',
            ];
        }

        return $invalid;
    }

    /**
     * @param array<string, mixed> $receipt
     * @param list<array<string, mixed>> $slots
     * @return list<array{block_path: string, content: string}>
     */
    private function from_carry_forward(array $receipt, array $slots): array {
        $carry = ArrayKey::as_map($receipt['carry_forward'] ?? null);

        if (!new PatternCompactFillGuard()->target_is_substantive($carry)) {
            return [];
        }

        $heading = trim((string) ($carry['heading'] ?? ''));
        $excerpt = trim((string) ($carry['excerpt'] ?? ''));
        $by_id = [];

        foreach ($slots as $slot) {
            $id = sanitize_key((string) ($slot['slot_id'] ?? ''));

            if ('' !== $id) {
                $by_id[$id] = $slot;
            }
        }

        $updates = [];
        // When both heading and excerpt exist, do not put the excerpt into lead and
        // primary-body — that doubles roster/FAQ text across the documentation layout.
        $slot_text = [
            'primary-heading' => $heading !== '' ? $heading : '',
            'primary-body' => $excerpt !== '' ? $excerpt : '',
            'lead' => '',
        ];

        if ($excerpt !== '' && '' === $heading) {
            $slot_text['lead'] = $excerpt;
        } elseif ($heading !== '' && '' === $excerpt) {
            $slot_text['lead'] = $heading;
            $slot_text['primary-heading'] = $heading;
        } elseif ($heading !== '' && $excerpt !== '') {
            // Distinct fields only: title in heading slot, substance in body.
            $slot_text['lead'] = '';
        }

        foreach ($slot_text as $slot_id => $text) {
            $text = trim($text);

            if ('' === $text || !isset($by_id[$slot_id])) {
                continue;
            }

            $slot = $by_id[$slot_id];
            $path = trim((string) ($slot['block_path'] ?? ''));
            $max = (int) ($slot['max_characters'] ?? 0);

            if ('' === $path) {
                continue;
            }

            if ($max > 0 && mb_strlen($text, 'UTF-8') > $max) {
                $text = rtrim(mb_substr($text, 0, max(1, $max - 1), 'UTF-8')) . '…';
            }

            $updates[] = ['block_path' => $path, 'content' => $text];
        }

        if ([] !== $updates) {
            return $this->clear_remaining_instructional_slots($slots, $updates, $heading, $excerpt);
        }

        $texts = array_values(array_filter([$heading, $excerpt], static fn(string $t): bool => '' !== $t));

        if ([] === $texts) {
            return [];
        }

        $updates = [];
        $limit = min(count($slots), count($texts));

        for ($i = 0; $i < $limit; ++$i) {
            $slot = $slots[$i];
            $path = trim((string) ($slot['block_path'] ?? ''));

            if ('' === $path) {
                continue;
            }

            $updates[] = ['block_path' => $path, 'content' => $texts[$i]];
        }

        return $this->clear_remaining_instructional_slots($slots, $updates, $heading, $excerpt);
    }

    /**
     * @param list<array<string, mixed>> $slots
     * @param list<array{block_path: string, content: string}> $updates
     * @return list<array{block_path: string, content: string}>
     */
    private function clear_remaining_instructional_slots(
        array $slots,
        array $updates,
        string $heading,
        string $excerpt,
    ): array {
        $covered = [];

        foreach ($updates as $update) {
            $covered[trim($update['block_path'])] = true;
        }

        $heading_fills = 0;

        foreach ($slots as $slot) {
            $path = trim((string) ($slot['block_path'] ?? ''));

            if ('' === $path || isset($covered[$path])) {
                continue;
            }

            $current = trim((string) ($slot['current_text'] ?? ''));

            if (!$this->looks_like_instructional_filler($current)) {
                continue;
            }

            $block_name = (string) ($slot['block_name'] ?? '');
            $is_heading = str_contains($block_name, 'heading');

            // Do not stamp the same carry_forward excerpt into every leftover
            // instructional slot — that is how documentation layouts end up with
            // "Members …" / FAQ answers repeated across the whole page.
            if ($is_heading) {
                ++$heading_fills;
                $text =
                    1 === $heading_fills && '' !== trim($heading)
                        ? trim($heading)
                        : 'Section ' . (string) $heading_fills;
            } else {
                $text = '';
            }

            $max = (int) ($slot['max_characters'] ?? 0);

            if ($max > 0 && '' !== $text && mb_strlen($text, 'UTF-8') > $max) {
                $text = rtrim(mb_substr($text, 0, max(1, $max - 1), 'UTF-8')) . '…';
            }

            $updates[] = ['block_path' => $path, 'content' => $text];
            $covered[$path] = true;
        }

        return $updates;
    }

    private function looks_like_instructional_filler(string $text): bool {
        if ('' === $text) {
            return false;
        }

        return (bool) preg_match(
            '/Section heading \(h[23]\)|Subsection heading|page heading communicates|particulars of your body copy|inverted pyramid|These headings introduce|Keep each section and subsection focused|Use the side navigation menu|menu is best suited|Read the full documentation|side navigation on the component page|on the component page/i',
            $text,
        );
    }
}
