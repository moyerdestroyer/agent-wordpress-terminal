<?php

/**
 * Action database access.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Agent\AgentFeedback;
use AWPT\Support\ActionOperations;
use AWPT\Support\CompositionActionContext;
use AWPT\Support\Json;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads and writes awpt_actions.
 */
final class ActionRepository {
    /** @var list<int> */
    private array $last_superseded_ids = [];

    private int $last_mutated_action_id = 0;

    /**
     * @param array<string, mixed> $payload
     * @return int|null Inserted action ID.
     */
    public function create(
        int $session_id,
        string $title,
        string $description,
        array $payload,
        array $options = [],
    ): ?int {
        // One open proposal per session: a new stage replaces prior review cards.
        $this->last_superseded_ids = $this->supersede_open_for_session($session_id);
        $wpdb = WpDb::get();

        $now = current_time('mysql');
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'awpt_actions',
            [
                'session_id' => $session_id,
                'title' => $title,
                'description' => $description,
                'payload_json' => wp_json_encode($this->sanitize_payload($payload)),
                'status' => sanitize_key((string) ($options['status'] ?? 'proposed')),
                'turn_id' => '' !== (string) ($options['turn_id'] ?? '')
                    ? sanitize_key((string) $options['turn_id'])
                    : null,
                'proposal_key' => '' !== (string) ($options['proposal_key'] ?? '')
                    ? sanitize_key((string) $options['proposal_key'])
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            format: ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        );

        if (false === $inserted) {
            return null;
        }

        $this->last_mutated_action_id = (int) $wpdb->insert_id;

        return $this->last_mutated_action_id;
    }

    public function update_status(int $action_id, string $status): void {
        $wpdb = WpDb::get();

        $wpdb->update(
            $wpdb->prefix . 'awpt_actions',
            [
                'status' => $status,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $action_id],
            format: ['%s', '%s'],
            where_format: ['%d'],
        );
    }

    public function mark_applied(int $action_id): void {
        $this->update_status($action_id, 'applied');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update_payload(int $action_id, array $payload): void {
        $wpdb = WpDb::get();

        $wpdb->update(
            $wpdb->prefix . 'awpt_actions',
            [
                'payload_json' => wp_json_encode($this->sanitize_payload($payload)),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $action_id],
            format: ['%s', '%s'],
            where_format: ['%d'],
        );
    }

    /**
     * Replace the editable fields of an existing proposal and return it to review.
     *
     * @param array<string, mixed> $payload
     */
    public function revise(int $action_id, string $title, string $description, array $payload): bool {
        $row = $this->get_accessible_row($action_id);
        $session_id = (int) ($row['session_id'] ?? 0);

        // Keep this card; supersede any other open proposals in the session.
        $this->last_superseded_ids = $session_id > 0 ? $this->supersede_open_for_session($session_id, $action_id) : [];
        $wpdb = WpDb::get();

        $updated = $wpdb->update(
            $wpdb->prefix . 'awpt_actions',
            [
                'title' => $title,
                'description' => $description,
                'payload_json' => wp_json_encode($this->sanitize_payload($payload)),
                'status' => 'proposed',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $action_id],
            format: ['%s', '%s', '%s', '%s', '%s'],
            where_format: ['%d'],
        );

        if (false === $updated) {
            return false;
        }

        $this->last_mutated_action_id = $action_id;

        return true;
    }

    /**
     * Close other open proposals in the session so only one remains active.
     *
     * @return list<int> Superseded action IDs.
     */
    public function supersede_open_for_session(int $session_id, int $except_action_id = 0): array {
        if ($session_id <= 0) {
            return [];
        }

        $preview = new StagedPostPreview();
        $removed = [];

        foreach ($this->list_open_for_session($session_id, 25) as $action) {
            $action_id = (int) ($action['id'] ?? 0);

            if ($action_id <= 0 || $action_id === $except_action_id) {
                continue;
            }

            $preview->discard_preview_resources($this->decode_payload($action));
            $this->update_status($action_id, 'superseded');
            $removed[] = $action_id;
        }

        return $removed;
    }

    /**
     * Return active proposals that belong to the current user and session.
     *
     * @return list<array<string, mixed>>
     */
    public function list_open_for_session(int $session_id, int $limit = 10): array {
        $wpdb = WpDb::get();
        $limit = max(1, min(25, $limit));
        $actions = $wpdb->prefix . 'awpt_actions';
        $sessions = $wpdb->prefix . 'awpt_sessions';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.* FROM {$actions} a INNER JOIN {$sessions} s ON s.id = a.session_id"
                . " WHERE a.session_id = %d AND s.user_id = %d AND a.status IN ('proposed', 'approved')"
                . ' ORDER BY a.updated_at DESC LIMIT %d',
                $session_id,
                get_current_user_id(),
                $limit,
            ),
            output: \ARRAY_A,
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    /** Return the newest open new-post proposal in a session. */
    public function latest_open_new_post_for_session(int $session_id): ?array {
        foreach ($this->list_open_for_session($session_id, 25) as $action) {
            $payload = $this->decode_payload($action);

            if (ActionOperations::NEW_POST === (string) ($payload['operation'] ?? '')) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Resolve which open new-post proposal a corrective propose-new-post call should revise.
     *
     * Preference order among open new-post actions in the session (newest first):
     * 1. Exact title match for the same post type
     * 2. When the agent omitted the title, the only open new-post of that post type
     * 3. When both title and type are omitted, the only open new-post of any type
     *
     * Returns 0 when nothing is safely revisable (caller should create a new proposal).
     */
    public function resolve_revisable_new_post_id(
        int $session_id,
        string $post_type = '',
        string $post_title = '',
    ): int {
        $candidates = [];

        foreach ($this->list_open_for_session($session_id, 25) as $action) {
            $payload = $this->decode_payload($action);

            if (ActionOperations::NEW_POST !== (string) ($payload['operation'] ?? '')) {
                continue;
            }

            $candidates[] = [
                'id' => (int) ($action['id'] ?? 0),
                'post_type' => sanitize_key((string) ($payload['post_type'] ?? 'post')),
                'title_key' => sanitize_title((string) ($payload['post_title'] ?? '')),
            ];
        }

        return self::pick_revisable_new_post_id($candidates, $post_type, $post_title);
    }

    /**
     * @param list<array{id: int, post_type: string, title_key: string}> $candidates Newest-first candidates.
     */
    public static function pick_revisable_new_post_id(
        array $candidates,
        string $post_type = '',
        string $post_title = '',
    ): int {
        if ([] === $candidates) {
            return 0;
        }

        $post_type = sanitize_key($post_type);
        $title_key = sanitize_title($post_title);
        $typed = '' === $post_type
            ? $candidates
            : array_values(array_filter($candidates, static fn(array $row): bool => $row['post_type'] === $post_type));

        if ([] === $typed) {
            $typed = $candidates;
        }

        if ('' !== $title_key) {
            $title_matches = array_values(array_filter(
                $typed,
                static fn(array $row): bool => $row['title_key'] === $title_key,
            ));

            if ([] !== $title_matches) {
                return max(0, $title_matches[0]['id']);
            }
        }

        if ('' === $title_key && 1 === count($typed)) {
            return max(0, $typed[0]['id']);
        }

        return 0;
    }

    /** Find the action produced by one logical proposal slot in a turn. */
    public function find_by_turn_key(int $session_id, string $turn_id, string $proposal_key): ?array {
        if ($session_id <= 0 || '' === $turn_id || '' === $proposal_key) {
            return null;
        }

        $wpdb = WpDb::get();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}awpt_actions WHERE session_id = %d AND turn_id = %s AND proposal_key = %s LIMIT 1",
                $session_id,
                sanitize_key($turn_id),
                sanitize_key($proposal_key),
            ),
            output: \ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    /** Delete one accessible proposal row. Preview resources must be discarded first. */
    public function delete(int $action_id): bool {
        if (null === $this->get_accessible_row($action_id)) {
            return false;
        }

        $wpdb = WpDb::get();
        $deleted = $wpdb->delete($wpdb->prefix . 'awpt_actions', ['id' => $action_id], where_format: ['%d']);

        return false !== $deleted;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_accessible_row(int $action_id): ?array {
        $wpdb = WpDb::get();

        $actions = $wpdb->prefix . 'awpt_actions';
        $sessions = $wpdb->prefix . 'awpt_sessions';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT a.* FROM {$actions} a INNER JOIN {$sessions} s ON s.id = a.session_id WHERE a.id = %d AND s.user_id = %d",
                $action_id,
                get_current_user_id(),
            ),
            output: \ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function format_action(int $action_id): ?array {
        $row = $this->get_accessible_row($action_id);

        if (null === $row) {
            return null;
        }

        $action = [
            'id' => (int) $row['id'],
            'session_id' => (int) $row['session_id'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'payload' => Json::decode_array((string) $row['payload_json']),
            'status' => (string) $row['status'],
            'turn_id' => (string) ($row['turn_id'] ?? ''),
            'proposal_key' => (string) ($row['proposal_key'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
        $payload = is_array($action['payload']) ? $action['payload'] : [];

        if (!is_array($payload['agent_feedback'] ?? null)) {
            $payload['agent_feedback'] = AgentFeedback::make(
                in_array($action['status'], ['proposed', 'approved'], true) ? 'staged' : 'ready',
                in_array($action['status'], ['proposed', 'approved'], true)
                    ? __('The proposal is staged for human review.', 'agent-wordpress-terminal')
                    : __('The action lifecycle state is reflected below.', 'agent-wordpress-terminal'),
            );
            $action['payload'] = $payload;
        }

        // Surface superseded peers so the transcript can drop replaced cards.
        if ($action_id === $this->last_mutated_action_id && [] !== $this->last_superseded_ids) {
            $action['removed_action_ids'] = $this->last_superseded_ids;
        }

        return $action;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function decode_payload(array $action): array {
        return Json::decode_array((string) ($action['payload_json'] ?? ''));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize_payload(array $payload): array {
        return new ActionPayloadSanitizer()->sanitize(new CompositionActionContext()->enrich($payload));
    }
}
