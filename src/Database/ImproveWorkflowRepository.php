<?php

/**
 * Durable state for the two-turn Improve workflow.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\ImprovePagePrompt;
use AWPT\Support\Json;

if (!defined('ABSPATH')) {
    exit();
}

/** Stores and validates one active Improve workflow per session. */
final class ImproveWorkflowRepository {
    public const TTL_SECONDS = 3600;

    /** @var array<int, array<string, mixed>> Request-local cache. */
    private static array $cache = [];

    /** @return array<string, mixed> */
    public function begin_evaluate(int $session_id, int $focus_post_id, string $turn_id): array {
        $now = time();
        $workflow = [
            'id' => (string) wp_generate_uuid4(),
            'type' => 'improve',
            'version' => ImprovePagePrompt::PROMPT_VERSION_TWO_STEP,
            'state' => 'evaluating',
            'focus_post_id' => max(0, $focus_post_id),
            'evaluate_turn_id' => sanitize_key($turn_id),
            'act_turn_id' => '',
            'plan' => '',
            'action_ids' => [],
            'error_code' => '',
            'error_message' => '',
            'created_at' => gmdate('c', $now),
            'updated_at' => gmdate('c', $now),
            'expires_at' => $now + self::TTL_SECONDS,
        ];
        $this->save($session_id, $workflow);

        return $workflow;
    }

    /** @return array<string, mixed>|null */
    public function get(int $session_id): ?array {
        if (array_key_exists($session_id, self::$cache)) {
            return self::$cache[$session_id];
        }
        $wpdb = WpDb::get();
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT improve_workflow_json FROM {$wpdb->prefix}awpt_sessions WHERE id = %d",
            $session_id,
        ));
        $workflow = Json::decode_array(is_string($value) ? $value : '');

        if ([] === $workflow) {
            return null;
        }
        self::$cache[$session_id] = $workflow;

        return $workflow;
    }

    /** @return array<string, mixed> */
    public function plan_ready(int $session_id, string $plan): array {
        $workflow = $this->get($session_id) ?? [];
        if ('evaluating' !== (string) ($workflow['state'] ?? '') || '' === trim($plan)) {
            return $this->fail(
                $session_id,
                'awpt_improve_plan_missing',
                __('The evaluation did not produce an executable plan.', 'agent-wordpress-terminal'),
            );
        }

        $workflow['state'] = 'plan_ready';
        $workflow['plan'] = trim($plan);
        $workflow['updated_at'] = gmdate('c');
        $this->save($session_id, $workflow);

        return $workflow;
    }

    /** @return array<string, mixed>|\WP_Error */
    public function begin_act(
        int $session_id,
        string $workflow_id,
        int $focus_post_id,
        string $turn_id,
    ): array|\WP_Error {
        $workflow = $this->get($session_id);
        if (null === $workflow || !hash_equals((string) ($workflow['id'] ?? ''), $workflow_id)) {
            return new \WP_Error(
                'awpt_improve_workflow_not_found',
                __('The Improve plan could not be found. Evaluate the page again.', 'agent-wordpress-terminal'),
                ['status' => 404],
            );
        }
        if ((int) ($workflow['expires_at'] ?? 0) < time()) {
            $this->fail(
                $session_id,
                'awpt_improve_plan_expired',
                __('The Improve plan expired.', 'agent-wordpress-terminal'),
            );
            return new \WP_Error(
                'awpt_improve_plan_expired',
                __('The Improve plan expired. Evaluate the page again.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }
        if ('plan_ready' !== (string) ($workflow['state'] ?? '')) {
            return new \WP_Error(
                'awpt_improve_workflow_not_executable',
                __('This Improve plan has already been executed or is not ready.', 'agent-wordpress-terminal'),
                ['status' => 409, 'state' => (string) ($workflow['state'] ?? '')],
            );
        }
        $bound_focus = (int) ($workflow['focus_post_id'] ?? 0);
        if ($bound_focus > 0 && $focus_post_id > 0 && $bound_focus !== $focus_post_id) {
            return new \WP_Error(
                'awpt_improve_focus_mismatch',
                __(
                    'The focused page changed after evaluation. Evaluate the new page first.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 409, 'expected_post_id' => $bound_focus, 'received_post_id' => $focus_post_id],
            );
        }

        $workflow['state'] = 'acting';
        $workflow['act_turn_id'] = sanitize_key($turn_id);
        $workflow['updated_at'] = gmdate('c');
        $this->save($session_id, $workflow);

        return $workflow;
    }

    /**
     * @param list<int> $action_ids
     * @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    public function finish_act(int $session_id, array $action_ids, array $outcome): array {
        $workflow = $this->get($session_id) ?? [];
        $status = sanitize_key((string) ($outcome['status'] ?? ''));
        $workflow['state'] = match (true) {
            [] !== $action_ids => 'staged',
            'failed' === $status => 'failed',
            default => 'no_change',
        };
        $workflow['action_ids'] = array_values(array_unique(array_filter(array_map('absint', $action_ids))));
        $workflow['error_code'] = 'failed' === $workflow['state']
            ? sanitize_key((string) ($outcome['error_code'] ?? 'awpt_improve_act_failed'))
            : '';
        $workflow['error_message'] = 'failed' === $workflow['state']
            ? sanitize_text_field((string) ($outcome['message'] ?? ''))
            : '';
        $workflow['updated_at'] = gmdate('c');
        $this->save($session_id, $workflow);

        return $workflow;
    }

    /** @return array<string, mixed> */
    public function fail(int $session_id, string $code, string $message): array {
        $workflow = $this->get($session_id) ?? [];
        $workflow['state'] = 'failed';
        $workflow['error_code'] = sanitize_key($code);
        $workflow['error_message'] = sanitize_text_field($message);
        $workflow['updated_at'] = gmdate('c');
        $this->save($session_id, $workflow);

        return $workflow;
    }

    public function sync_action(int $session_id, int $action_id, string $status): void {
        $workflow = $this->get($session_id);
        if (null === $workflow) {
            return;
        }
        $action_ids = is_array($workflow['action_ids'] ?? null) ? $workflow['action_ids'] : [];
        if (!in_array($action_id, $action_ids, true)) {
            return;
        }
        $next = match ($status) {
            'applied' => 'applied',
            'rejected' => 'rejected',
            'rolled_back' => 'rolled_back',
            default => '',
        };
        if ('' === $next) {
            return;
        }
        $workflow['state'] = $next;
        $workflow['updated_at'] = gmdate('c');
        $this->save($session_id, $workflow);
    }

    /** @param array<string, mixed> $workflow */
    private function save(int $session_id, array $workflow): void {
        self::$cache[$session_id] = $workflow;
        $wpdb = WpDb::get();
        $wpdb->update(
            $wpdb->prefix . 'awpt_sessions',
            ['improve_workflow_json' => wp_json_encode($workflow)],
            ['id' => $session_id],
            ['%s'],
            ['%d'],
        );
    }
}
