<?php

/**
 * Staging, application, preview, and rollback for registered domain operations.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Database\ActionRepository;
use AWPT\Support\ArrayKey;
use AWPT\Support\ResourceValueSanitizer;

if (!defined('ABSPATH')) {
    exit();
}

final class DomainProposalManager {
    private DomainPackRegistry $registry;

    public function __construct(?DomainPackRegistry $registry = null) {
        $this->registry = $registry ?? DomainPackRegistry::instance();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function stage(array $operation, array $input): array|\WP_Error {
        $permission = $operation['permission_callback'] ?? null;

        if (!is_callable($permission) || true !== $permission($input, 'stage')) {
            return new \WP_Error(
                'awpt_domain_operation_forbidden',
                __('You do not have permission to stage this domain operation.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        $stage = $operation['stage_callback'] ?? null;
        $result = is_callable($stage) ? $stage($input, $operation) : null;

        if (is_wp_error($result)) {
            return $result;
        }

        if (!is_array($result) || !is_array($result['payload'] ?? null)) {
            return new \WP_Error(
                'awpt_domain_stage_invalid',
                __('The domain operation returned an invalid staged payload.', 'agent-wordpress-terminal'),
                ['status' => 500],
            );
        }

        $operation = ArrayKey::string_map($operation);
        $result = ArrayKey::string_map($result);
        $custom_payload = $this->sanitize_custom($operation, ArrayKey::as_map($result['payload'] ?? null));
        $snapshot_callback = $operation['snapshot_callback'] ?? null;
        $snapshot = is_callable($snapshot_callback) ? $snapshot_callback($custom_payload, $input) : [];

        if (is_wp_error($snapshot)) {
            return $snapshot;
        }

        $snapshot = ArrayKey::as_map($snapshot);
        $fingerprint = $operation['fingerprint_callback'] ?? null;

        if (is_callable($fingerprint) && '' === (string) ($snapshot['fingerprint'] ?? '')) {
            $snapshot['fingerprint'] = sanitize_text_field((string) $fingerprint($custom_payload));
        }

        $validation = $this->run_validator($operation, $custom_payload, 'stage');

        if (is_wp_error($validation)) {
            return $validation;
        }

        $payload = [
            'operation' => (string) $operation['operation_id'],
            'domain_pack_id' => (string) $operation['pack_id'],
            'domain_ability_name' => (string) $operation['ability_name'],
            'domain_payload' => $custom_payload,
            'domain_snapshot' => $this->sanitize_value($snapshot),
            'domain_irreversible' => true === ($operation['irreversible'] ?? false),
            'domain_previewable' => is_callable($operation['preview_callback'] ?? null),
            'validation_findings' => $validation,
            'affected' => sanitize_textarea_field((string) ($result['affected'] ?? '')),
        ];
        $session_id = (int) ($input['session_id'] ?? 0);
        $action_id = new ActionRepository()->create(
            $session_id,
            sanitize_text_field((string) ($result['title'] ?? $operation['label'] ?? 'Domain action')),
            sanitize_textarea_field((string) ($result['description'] ?? $result['title'] ?? '')),
            $payload,
        );

        if (null === $action_id) {
            return new \WP_Error(
                'awpt_action_create_failed',
                __('Could not create the domain proposal.', 'agent-wordpress-terminal'),
                ['status' => 500],
            );
        }

        return new ActionRepository()->format_action($action_id) ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function apply(array $payload): array|\WP_Error {
        $operation = $this->registry->proposal_operation((string) ($payload['operation'] ?? ''));

        if (null === $operation) {
            return new \WP_Error(
                'awpt_domain_operation_unavailable',
                __('The extension that registered this domain operation is not active.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }

        $permission = $operation['permission_callback'] ?? null;
        $custom_payload = ArrayKey::as_map($payload['domain_payload'] ?? null);

        if (!is_callable($permission) || true !== $permission($custom_payload, 'apply')) {
            return new \WP_Error(
                'awpt_domain_operation_forbidden',
                __('You do not have permission to apply this domain operation.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        $stale = $this->snapshot_is_stale($operation, $payload);

        if (null !== $stale) {
            return $stale;
        }

        $validation = $this->run_validator($operation, $custom_payload, 'apply');

        if (is_wp_error($validation)) {
            return $validation;
        }

        $callback = $operation['apply_callback'] ?? null;
        $result = is_callable($callback)
            ? $callback($custom_payload, ArrayKey::as_map($payload['domain_snapshot'] ?? null))
            : null;

        if (is_wp_error($result)) {
            return $result;
        }

        if (!is_array($result)) {
            return new \WP_Error(
                'awpt_domain_apply_invalid',
                __('The domain operation returned an invalid apply result.', 'agent-wordpress-terminal'),
                ['status' => 500],
            );
        }

        $fingerprint = $operation['fingerprint_callback'] ?? null;
        $applied_fingerprint = is_callable($fingerprint)
            ? (string) $fingerprint($custom_payload)
            : (string) ($result['fingerprint'] ?? '');

        return [
            'domain_result' => $this->sanitize_value($result),
            'domain_rollback_token' => $this->sanitize_value(ArrayKey::as_map($result['rollback_token'] ?? null)),
            'domain_applied_fingerprint' => sanitize_text_field($applied_fingerprint),
            'validation_findings' => $validation,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function preview(array $payload): array|\WP_Error {
        $operation = $this->registry->proposal_operation((string) ($payload['operation'] ?? ''));
        $callback = $operation['preview_callback'] ?? null;

        if (null === $operation || !is_callable($callback)) {
            return new \WP_Error(
                'awpt_preview_unsupported',
                __('This domain operation does not provide a preview.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $result = $callback(ArrayKey::as_map($payload['domain_payload'] ?? null), $payload);

        if (is_wp_error($result)) {
            return $result;
        }

        return is_array($result)
            ? ArrayKey::string_map($result)
            : new \WP_Error('awpt_domain_preview_invalid', __(
                'The domain preview is invalid.',
                'agent-wordpress-terminal',
            ));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function rollback(array $payload): array|\WP_Error {
        $operation = $this->registry->proposal_operation((string) ($payload['operation'] ?? ''));

        if (null === $operation || true === ($payload['domain_irreversible'] ?? false)) {
            return new \WP_Error(
                'awpt_domain_rollback_unsupported',
                __('This operation cannot be rolled back automatically.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }

        $permission = $operation['permission_callback'] ?? null;
        $callback = $operation['rollback_callback'] ?? null;
        $custom_payload = ArrayKey::as_map($payload['domain_payload'] ?? null);

        if (!is_callable($permission) || true !== $permission($custom_payload, 'rollback') || !is_callable($callback)) {
            return new \WP_Error(
                'awpt_domain_rollback_forbidden',
                __('The rollback is unavailable or not permitted.', 'agent-wordpress-terminal'),
                ['status' => 403],
            );
        }

        $fingerprint = $operation['fingerprint_callback'] ?? null;

        if (is_callable($fingerprint) && '' !== (string) ($payload['domain_applied_fingerprint'] ?? '')) {
            $current = (string) $fingerprint($custom_payload);

            if (!hash_equals((string) $payload['domain_applied_fingerprint'], $current)) {
                return new \WP_Error(
                    'awpt_domain_rollback_conflict',
                    __(
                        'The affected resource changed after apply; rollback was stopped to avoid overwriting newer work.',
                        'agent-wordpress-terminal',
                    ),
                    ['status' => 409],
                );
            }
        }

        $result = $callback(
            $custom_payload,
            ArrayKey::as_map($payload['domain_snapshot'] ?? null),
            ArrayKey::as_map($payload['domain_rollback_token'] ?? null),
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return is_array($result)
            ? ArrayKey::string_map($result)
            : new \WP_Error('awpt_domain_rollback_invalid', __(
                'The rollback result is invalid.',
                'agent-wordpress-terminal',
            ));
    }

    /**
     * Release temporary resources created while staging or previewing a rejected operation.
     *
     * @param array<string, mixed> $payload
     */
    public function cleanup(array $payload): ?\WP_Error {
        $operation = $this->registry->proposal_operation((string) ($payload['operation'] ?? ''));

        if (null === $operation || !is_callable($operation['cleanup_callback'] ?? null)) {
            return null;
        }

        $custom_payload = ArrayKey::as_map($payload['domain_payload'] ?? null);
        $permission = $operation['permission_callback'] ?? null;

        if (!is_callable($permission) || true !== $permission($custom_payload, 'cleanup')) {
            return new \WP_Error(
                'awpt_domain_cleanup_forbidden',
                __(
                    'The rejected domain operation could not release its temporary resources.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 403],
            );
        }

        $result = $operation['cleanup_callback']($custom_payload, $payload);

        return is_wp_error($result) ? $result : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize_stored_payload(array $payload): array {
        $operation = $this->registry->proposal_operation((string) ($payload['operation'] ?? ''));

        if (null === $operation) {
            return ['operation' => ''];
        }

        $clean = [
            'operation' => (string) $operation['operation_id'],
            'domain_pack_id' => sanitize_key((string) ($payload['domain_pack_id'] ?? $operation['pack_id'])),
            'domain_ability_name' => sanitize_text_field((string) ($payload['domain_ability_name'] ?? '')),
            'domain_payload' => $this->sanitize_custom(
                $operation,
                ArrayKey::as_map($payload['domain_payload'] ?? null),
            ),
            'domain_snapshot' => $this->sanitize_value(ArrayKey::as_map($payload['domain_snapshot'] ?? null)),
            'domain_irreversible' => true === ($payload['domain_irreversible'] ?? false),
            'domain_previewable' => true === ($payload['domain_previewable'] ?? false),
            'domain_result' => $this->sanitize_value(ArrayKey::as_map($payload['domain_result'] ?? null)),
            'domain_rollback_token' => $this->sanitize_value(ArrayKey::as_map(
                $payload['domain_rollback_token'] ?? null,
            )),
            'domain_rollback_result' => $this->sanitize_value(ArrayKey::as_map(
                $payload['domain_rollback_result'] ?? null,
            )),
            'domain_applied_fingerprint' => sanitize_text_field(
                (string) ($payload['domain_applied_fingerprint'] ?? ''),
            ),
            'affected' => sanitize_textarea_field((string) ($payload['affected'] ?? '')),
        ];

        if (is_array($payload['validation_findings'] ?? null)) {
            $clean['validation_findings'] = $payload['validation_findings'];
        }

        return $clean;
    }

    public function can_preview(string $operation_id): bool {
        $operation = $this->registry->proposal_operation($operation_id);

        return null !== $operation && is_callable($operation['preview_callback'] ?? null);
    }

    /**
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitize_custom(array $operation, array $payload): array {
        $callback = $operation['sanitize_callback'] ?? null;
        $sanitized = is_callable($callback) ? $callback($payload) : null;

        return is_array($sanitized) ? ArrayKey::string_map($sanitized) : $this->sanitize_value($payload);
    }

    /**
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>|\WP_Error
     */
    private function run_validator(array $operation, array $payload, string $phase): array|\WP_Error {
        $callback = $operation['validate_callback'] ?? null;

        if (!is_callable($callback)) {
            return [];
        }

        $findings = $callback($payload, $phase);

        if (is_wp_error($findings)) {
            return $findings;
        }

        if (!is_array($findings)) {
            return [];
        }

        $normalized = ArrayKey::list_of_maps($findings);
        $errors = array_filter(
            $normalized,
            static fn(array $finding): bool => 'error' === (string) ($finding['severity'] ?? ''),
        );

        if ([] !== $errors) {
            return new \WP_Error(
                'awpt_domain_operation_validation',
                __('The domain operation failed validation.', 'agent-wordpress-terminal'),
                ['status' => 409, 'validation_findings' => $normalized],
            );
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $payload
     */
    private function snapshot_is_stale(array $operation, array $payload): ?\WP_Error {
        $fingerprint = $operation['fingerprint_callback'] ?? null;
        $snapshot = ArrayKey::as_map($payload['domain_snapshot'] ?? null);
        $expected = (string) ($snapshot['fingerprint'] ?? '');

        if (!is_callable($fingerprint) || '' === $expected) {
            return null;
        }

        $current = (string) $fingerprint(ArrayKey::as_map($payload['domain_payload'] ?? null));

        return (
            hash_equals($expected, $current)
                ? null
                : new \WP_Error(
                    'awpt_domain_action_stale',
                    __(
                        'The affected resource changed after this proposal was staged. Restage before applying.',
                        'agent-wordpress-terminal',
                    ),
                    ['status' => 409],
                )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitize_value(array $value): array {
        return new ResourceValueSanitizer()->sanitize_object($value);
    }
}
