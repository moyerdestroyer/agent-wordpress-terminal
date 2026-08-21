<?php

/**
 * Resolves a pattern-change receipt, preparing when the model omitted one.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Abilities\PreparePatternChange;
use AWPT\Domain\PatternPreparationReceipt;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lets propose-pattern-replace/insert accept path + intent instead of a receipt.
 */
final class PatternProposeAutoPrepare {
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function resolve(array $input, string $mode): array|\WP_Error {
        $preparation_id = sanitize_text_field((string) ($input['preparation_id'] ?? ''));
        $receipts = new PatternPreparationReceipt();
        $constraints = [
            'post_id' => (int) ($input['post_id'] ?? 0),
            'session_id' => (int) ($input['session_id'] ?? 0),
            'mode' => $mode,
        ];

        if ('' !== $preparation_id && !$this->looks_unusable($preparation_id)) {
            $receipt = $receipts->require_for_propose($preparation_id, $constraints);

            if (!is_wp_error($receipt)) {
                return $receipt;
            }

            // Missing/stale/invented IDs fall through to path+intent prepare.
        }

        if ('' !== $preparation_id && $this->looks_placeholder($preparation_id)) {
            $preparation_id = '';
        }

        $path = $this->infer_path($input);
        $intent = $this->infer_intent($input);
        $replace_entire_document = $this->wants_entire_document($input);

        if ('' === $path || '' === $intent) {
            return new \WP_Error(
                '' !== $preparation_id && '' === $path && '' === $intent
                    ? 'awpt_preparation_id_invalid'
                    : 'awpt_preparation_id_required',
                __(
                    'Pass path (for example "0") and intent so the server can prepare the section. preparation_id is optional.',
                    'agent-wordpress-terminal',
                ),
                [
                    'status' => 400,
                    'preparation_id' => $preparation_id,
                    'path' => $path,
                    'recommended_next_tools' => ['awpt/propose-pattern-replace', 'awpt/propose-pattern-insert'],
                ],
            );
        }

        $prepare_input = [
            'session_id' => (int) ($input['session_id'] ?? 0),
            'post_id' => (int) ($input['post_id'] ?? 0),
            'intent' => $intent,
            'mode' => $mode,
            'target_path' => $path,
            'expected_fingerprint' => sanitize_text_field(
                (string) ($input['expected_fingerprint'] ?? $input['fingerprint'] ?? ''),
            ),
            'position' => sanitize_key((string) ($input['position'] ?? '')),
            'replace_entire_document' => $replace_entire_document,
        ];
        $pattern_name = sanitize_text_field((string) ($input['pattern_name'] ?? ''));

        if ($replace_entire_document) {
            // Document hash is computed at prepare time; ignore section fingerprints.
            $prepare_input['expected_fingerprint'] = '';
        }

        if ('' !== $pattern_name) {
            $prepare_input['pattern_name'] = $pattern_name;
        }

        $prepared = new PreparePatternChange()->execute($prepare_input);

        if (is_wp_error($prepared)) {
            return $prepared;
        }

        if ('custom_fallback' === (string) ($prepared['mode'] ?? '')) {
            return new \WP_Error(
                'awpt_pattern_change_custom_fallback',
                (string) (
                    $prepared['reason'] ?? __(
                        'No compatible section pattern was available.',
                        'agent-wordpress-terminal',
                    )
                ),
                [
                    'status' => 409,
                    'recommended_next_tools' => ['awpt/propose-block-batch-update'],
                    'prepare' => $prepared,
                ],
            );
        }

        $minted_id = sanitize_text_field((string) ($prepared['preparation_id'] ?? ''));

        return $receipts->require_for_propose($minted_id, $constraints);
    }

    public function looks_unusable(string $preparation_id): bool {
        return $this->looks_placeholder($preparation_id);
    }

    public function looks_placeholder(string $preparation_id): bool {
        return (bool) preg_match('/[<]|FROM_PREPARE|PLACEHOLDER|heading_block_path/i', $preparation_id);
    }

    public function looks_like_hash(string $preparation_id): bool {
        return 1 === preg_match('/^[a-f0-9]{64}$/', $preparation_id);
    }

    /** @param array<string, mixed> $input */
    private function infer_path(array $input): string {
        foreach (['path', 'target_path', 'block_path'] as $key) {
            $normalized = $this->normalize_path_candidate((string) ($input[$key] ?? ''));

            if ('' !== $normalized) {
                return $this->section_path($normalized);
            }
        }

        // Do not infer the page section from pattern_text_updates: those paths
        // address leaves inside the materialized pattern, not the live page tree.
        return '';
    }

    /** @param array<string, mixed> $input */
    private function infer_intent(array $input): string {
        foreach (['intent', 'title', 'description'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));

            if ('' !== $value) {
                return $value;
            }
        }

        return '';
    }

    private function normalize_path_candidate(string $path): string {
        $path = trim($path);

        if ('' === $path) {
            return '';
        }

        $path = trim($path, "[] \t\"'");

        $matches = [];

        if (1 === preg_match('/^path\s+(\d+(?:\.\d+)*)$/i', $path, $matches)) {
            $path = $matches[1];
        }

        $alias = strtolower($path);

        if ('document' === $alias) {
            return 'document';
        }

        if (1 === preg_match('/^\d+(?:\.\d+)*$/', $path)) {
            return $path;
        }

        return '';
    }

    private function section_path(string $path): string {
        if ('document' === $path) {
            return $path;
        }

        $parts = explode('.', $path);

        return $parts[0];
    }

    /** @param array<string, mixed> $input */
    private function wants_entire_document(array $input): bool {
        if (true === ($input['replace_entire_document'] ?? false)) {
            return true;
        }

        foreach (['path', 'target_path', 'block_path'] as $key) {
            $alias = strtolower(trim((string) ($input[$key] ?? ''), "[] \t\"'"));

            if ('document' === $alias) {
                return true;
            }
        }

        return false;
    }
}
