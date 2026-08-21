<?php

/**
 * Bound preparation receipts for compact pattern compose abilities.
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
 * Mints and loads short-lived preparation receipts so propose steps cannot
 * freely invent pattern lists or target paths after preparation.
 */
final class PatternPreparationReceipt {
    public const TTL_SECONDS = 3600;

    public const MODE_REPLACE = 'replace';
    public const MODE_INSERT = 'insert';
    public const MODE_DRAFT = 'draft';

    /**
     * @param array{
     *   post_id?: int,
     *   session_id?: int,
     *   mode: string,
     *   intent?: string,
     *   target_path?: string,
     *   expected_fingerprint?: string,
     *   source_content_hash?: string,
     *   source_modified_gmt?: string,
     *   pattern_names: list<string>,
     *   expanded_content_hash?: string,
     *   pattern_content?: string,
     *   position?: string,
     *   post_type?: string,
     *   carry_forward?: array<string, mixed>,
     *   replace_entire_document?: bool
     * } $data
     * @return array{preparation_id: string, expires_at: int, receipt: array<string, mixed>}
     */
    public function mint(array $data): array {
        $mode = sanitize_key($data['mode']);
        $pattern_names = array_values(array_filter(array_map(static fn(string $name): string => sanitize_text_field(
            $name,
        ), $data['pattern_names'])));
        $position = sanitize_key($data['position'] ?? '');

        if (!in_array($position, ['before', 'after', 'append'], true)) {
            $position = '';
        }

        $preparation_id = function_exists('wp_generate_uuid4')
            ? (string) wp_generate_uuid4()
            : hash('sha256', microtime(true) . '|' . random_int(0, PHP_INT_MAX) . '|' . implode(',', $pattern_names));

        $expires_at = time() + self::TTL_SECONDS;
        // Sign only compact fields; large pattern markup is stored but integrity-
        // checked via expanded_content_hash so signatures stay stable and small.
        $receipt = [
            'preparation_id' => $preparation_id,
            'post_id' => max(0, (int) ($data['post_id'] ?? 0)),
            'session_id' => max(0, (int) ($data['session_id'] ?? 0)),
            'mode' => $mode,
            'intent' => sanitize_text_field($data['intent'] ?? ''),
            'target_path' => sanitize_text_field($data['target_path'] ?? ''),
            'expected_fingerprint' => sanitize_text_field($data['expected_fingerprint'] ?? ''),
            'source_content_hash' => sanitize_text_field($data['source_content_hash'] ?? ''),
            'source_modified_gmt' => sanitize_text_field($data['source_modified_gmt'] ?? ''),
            'pattern_names' => $pattern_names,
            'expanded_content_hash' => sanitize_text_field($data['expanded_content_hash'] ?? ''),
            'position' => $position,
            'post_type' => sanitize_key($data['post_type'] ?? ''),
            'carry_forward' => is_array($data['carry_forward'] ?? null) ? $data['carry_forward'] : [],
            'replace_entire_document' => true === ($data['replace_entire_document'] ?? false),
            'created_at' => time(),
            'expires_at' => $expires_at,
            'signature' => '',
            'pattern_content' => $data['pattern_content'] ?? '',
        ];
        $receipt['signature'] = $this->sign($receipt);

        set_transient($this->transient_key($preparation_id), $receipt, self::TTL_SECONDS);

        return [
            'preparation_id' => $preparation_id,
            'expires_at' => $expires_at,
            'receipt' => $receipt,
        ];
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function load(string $preparation_id): array|\WP_Error {
        $preparation_id = sanitize_text_field($preparation_id);

        if ('' === $preparation_id) {
            return new \WP_Error(
                'awpt_preparation_id_required',
                __('A preparation_id from awpt/prepare-pattern-change is required.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $stored = ArrayKey::as_map(get_transient($this->transient_key($preparation_id)));

        if ([] === $stored) {
            return new \WP_Error(
                'awpt_preparation_not_found',
                __(
                    'Preparation receipt not found or expired. Call awpt/prepare-pattern-change again.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 404, 'preparation_id' => $preparation_id],
            );
        }

        $expires_at = (int) ($stored['expires_at'] ?? 0);

        if ($expires_at > 0 && time() > $expires_at) {
            delete_transient($this->transient_key($preparation_id));

            return new \WP_Error(
                'awpt_preparation_expired',
                __('Preparation receipt expired. Call awpt/prepare-pattern-change again.', 'agent-wordpress-terminal'),
                ['status' => 409, 'preparation_id' => $preparation_id],
            );
        }

        $expected = (string) ($stored['signature'] ?? '');
        $check = $stored;
        $check['signature'] = '';

        if ('' === $expected || !hash_equals($expected, $this->sign($check))) {
            return new \WP_Error(
                'awpt_preparation_tampered',
                __('Preparation receipt failed integrity verification.', 'agent-wordpress-terminal'),
                ['status' => 409, 'preparation_id' => $preparation_id],
            );
        }

        return $stored;
    }

    /**
     * @param array<string, mixed> $expected_constraints
     * @return array<string, mixed>|\WP_Error
     */
    public function require_for_propose(string $preparation_id, array $expected_constraints = []): array|\WP_Error {
        $receipt = $this->load($preparation_id);

        if (is_wp_error($receipt)) {
            return $receipt;
        }

        $post_id = (int) ($expected_constraints['post_id'] ?? 0);

        if ($post_id > 0 && $post_id !== (int) ($receipt['post_id'] ?? 0)) {
            return new \WP_Error(
                'awpt_preparation_post_mismatch',
                __('Preparation receipt belongs to a different post.', 'agent-wordpress-terminal'),
                [
                    'status' => 409,
                    'preparation_post_id' => (int) ($receipt['post_id'] ?? 0),
                    'requested_post_id' => $post_id,
                ],
            );
        }

        $mode = sanitize_key((string) ($expected_constraints['mode'] ?? ''));

        if ('' !== $mode && $mode !== sanitize_key((string) ($receipt['mode'] ?? ''))) {
            return new \WP_Error(
                'awpt_preparation_mode_mismatch',
                __('Preparation receipt mode does not match the propose ability.', 'agent-wordpress-terminal'),
                [
                    'status' => 409,
                    'preparation_mode' => (string) ($receipt['mode'] ?? ''),
                    'requested_mode' => $mode,
                ],
            );
        }

        $session_id = (int) ($expected_constraints['session_id'] ?? 0);
        $bound_session = (int) ($receipt['session_id'] ?? 0);

        if ($bound_session > 0 && $session_id > 0 && $bound_session !== $session_id) {
            return new \WP_Error(
                'awpt_preparation_session_mismatch',
                __('Preparation receipt belongs to a different session.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }

        return $receipt;
    }

    /** @param array<string, mixed> $receipt */
    private function sign(array $receipt): string {
        unset($receipt['signature'], $receipt['pattern_content']);
        ksort($receipt);
        $canonical = wp_json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', is_string($canonical) ? $canonical : '', $this->secret());
    }

    private function secret(): string {
        if (function_exists('wp_salt')) {
            return wp_salt('auth');
        }

        return 'awpt-pattern-preparation';
    }

    private function transient_key(string $preparation_id): string {
        return 'awpt_prep_' . md5($preparation_id);
    }
}
