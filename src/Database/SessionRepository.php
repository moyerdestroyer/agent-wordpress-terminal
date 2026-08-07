<?php

/**
 * Session database access.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

use AWPT\Support\Json;
use AWPT\Support\StagedPostPreview;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads and writes awpt_sessions and related session detail.
 */
final class SessionRepository {
    /** Soft cap on sessions retained per admin (oldest by updated_at are pruned). */
    public const MAX_PER_USER = 8;

    private SessionHydrator $hydrator;

    public function __construct(?SessionHydrator $hydrator = null) {
        $this->hydrator = $hydrator ?? new SessionHydrator();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list_summaries(): array {
        $user_id = $this->current_user_id();

        if ($user_id > 0) {
            $this->prune_excess();
        }

        $wpdb = WpDb::get();
        $table = $wpdb->prefix . 'awpt_sessions';

        if ($user_id > 0) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, user_id, title, model, provider, focus_post_id, created_at, updated_at
                    FROM {$table}
                    WHERE user_id = %d
                    ORDER BY updated_at DESC
                    LIMIT %d",
                    $user_id,
                    self::MAX_PER_USER,
                ),
                output: \ARRAY_A,
            );
        } else {
            // Unauthenticated session GET (dev agents): no user filter.
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT id, user_id, title, model, provider, focus_post_id, created_at, updated_at
                    FROM {$table}
                    ORDER BY updated_at DESC
                    LIMIT %d", self::MAX_PER_USER),
                output: \ARRAY_A,
            );
        }

        return $this->with_focus_summaries(is_array($rows) ? $rows : []);
    }

    /**
     * Most recently updated session id for the viewer, or 0 when none exist.
     *
     * Authenticated admins are scoped to their own sessions; unauthenticated
     * readers (dev agents) see the global latest session.
     */
    public function latest_id(): int {
        $wpdb = WpDb::get();
        $table = $wpdb->prefix . 'awpt_sessions';
        $user_id = $this->current_user_id();

        if ($user_id > 0) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1",
                $user_id,
            ));
        } else {
            $id = $wpdb->get_var("SELECT id FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT 1");
        }

        return max(0, (int) $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_detail(
        int $session_id,
        int $messages_limit = 50,
        bool $include_tool_outputs = false,
        bool $include_ai_logs = true,
    ): ?array {
        $messages_limit = max(1, min(200, $messages_limit));
        $wpdb = WpDb::get();
        $user_id = $this->current_user_id();

        $sessions = $wpdb->prefix . 'awpt_sessions';
        $messages = $wpdb->prefix . 'awpt_messages';
        $tool_calls = $wpdb->prefix . 'awpt_tool_calls';
        $actions = $wpdb->prefix . 'awpt_actions';

        $session = $wpdb->get_row(
            $user_id > 0
                ? $wpdb->prepare("SELECT * FROM {$sessions} WHERE id = %d AND user_id = %d", $session_id, $user_id)
                : $wpdb->prepare("SELECT * FROM {$sessions} WHERE id = %d", $session_id),
            output: \ARRAY_A,
        );

        if (!is_array($session)) {
            return null;
        }

        $message_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, role, content, created_at FROM {$messages} WHERE session_id = %d ORDER BY id DESC LIMIT %d",
                $session_id,
                $messages_limit,
            ),
            output: \ARRAY_A,
        );
        $message_rows = is_array($message_rows) ? array_reverse($message_rows) : [];
        $session['messages'] = $message_rows;
        $session['messages_truncated'] = count($message_rows) >= $messages_limit;

        $tool_call_sql = "SELECT id, tool_name, input_json, output_json, status, created_at FROM {$tool_calls} WHERE session_id = %d ORDER BY id DESC LIMIT %d";
        $tool_call_rows = $wpdb->get_results(
            $wpdb->prepare($tool_call_sql, $session_id, $messages_limit * 4),
            output: \ARRAY_A,
        );
        $tool_call_rows = is_array($tool_call_rows) ? array_reverse($tool_call_rows) : [];
        $session['tool_calls'] = $this->hydrator->tool_calls($tool_call_rows, $include_tool_outputs);
        $session['tool_calls_truncated'] = count($tool_call_rows) >= ($messages_limit * 4);

        $action_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, session_id, title, description, payload_json, status, created_at, updated_at FROM {$actions} WHERE session_id = %d ORDER BY id DESC",
                $session_id,
            ),
            output: \ARRAY_A,
        );
        $session['actions'] = $this->hydrator->actions(is_array($action_rows) ? $action_rows : []);
        $session['last_turn_outcome'] = Json::decode_array((string) ($session['last_outcome_json'] ?? ''));
        unset($session['last_outcome_json']);

        if ($include_ai_logs) {
            $ai_logs = new AiLogRepository()->list_for_session($session_id, min(100, $messages_limit * 4));
            $session['ai_logs'] = $ai_logs;
            $session['ai_logs_truncated'] = count($ai_logs) >= min(100, $messages_limit * 4);
        } else {
            $session['ai_logs'] = [];
            $session['ai_logs_truncated'] = false;
        }

        return $this->with_focus_summary($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $title, int $focus_post_id = 0): array {
        $wpdb = WpDb::get();

        $now = current_time('mysql');
        $table = $wpdb->prefix . 'awpt_sessions';

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id' => $this->current_user_id(),
                'title' => $title,
                'focus_post_id' => $focus_post_id > 0 ? $focus_post_id : null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            format: ['%d', '%s', '%d', '%s', '%s'],
        );

        if (false === $inserted) {
            return [];
        }

        $created_id = (int) $wpdb->insert_id;
        $this->prune_excess();

        $summary = $this->get_summary($created_id);

        if ([] !== $summary) {
            return $summary;
        }

        return [
            'id' => $created_id,
            'user_id' => $this->current_user_id(),
            'title' => $title,
            'focus_post_id' => $focus_post_id > 0 ? $focus_post_id : null,
            'focus' => $focus_post_id > 0
                ? $this->with_focus_summary(['focus_post_id' => $focus_post_id])['focus']
                : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array<string, mixed>|null */
    public function find_by_focus(int $post_id): ?array {
        if ($post_id <= 0) {
            return null;
        }

        $wpdb = WpDb::get();
        $table = $wpdb->prefix . 'awpt_sessions';
        $actions = $wpdb->prefix . 'awpt_actions';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, user_id, title, model, provider, focus_post_id, created_at, updated_at
            FROM {$table}
            WHERE user_id = %d AND focus_post_id = %d
            ORDER BY (SELECT COUNT(*) FROM {$actions} WHERE session_id = {$table}.id) DESC, updated_at DESC
            LIMIT 1",
                $this->current_user_id(),
                $post_id,
            ),
            output: \ARRAY_A,
        );

        return is_array($row) ? $this->with_focus_summary($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function update_title(int $session_id, string $title): ?array {
        $wpdb = WpDb::get();

        $now = current_time('mysql');
        $table = $wpdb->prefix . 'awpt_sessions';

        $wpdb->update(
            $table,
            [
                'title' => $title,
                'updated_at' => $now,
            ],
            [
                'id' => $session_id,
                'user_id' => $this->current_user_id(),
            ],
            format: ['%s', '%s'],
            where_format: ['%d', '%d'],
        );

        return $this->get_summary($session_id);
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string>         $formats
     */
    public function update_fields(int $session_id, array $fields, array $formats): void {
        if (!$this->exists($session_id)) {
            return;
        }

        $wpdb = WpDb::get();

        $wpdb->update(
            $wpdb->prefix . 'awpt_sessions',
            $fields,
            [
                'id' => $session_id,
                'user_id' => $this->current_user_id(),
            ],
            format: $formats,
            where_format: ['%d', '%d'],
        );
    }

    public function delete(int $session_id): void {
        if (!$this->exists($session_id)) {
            return;
        }

        $this->discard_preview_resources_for_session($session_id);

        $wpdb = WpDb::get();

        foreach (['messages', 'tool_calls', 'actions'] as $suffix) {
            $wpdb->delete($wpdb->prefix . 'awpt_' . $suffix, ['session_id' => $session_id], where_format: ['%d']);
        }

        $wpdb->delete(
            $wpdb->prefix . 'awpt_sessions',
            [
                'id' => $session_id,
                'user_id' => $this->current_user_id(),
            ],
            where_format: ['%d', '%d'],
        );
    }

    public function clear_transcript(int $session_id): void {
        if (!$this->exists($session_id)) {
            return;
        }

        $this->discard_preview_resources_for_session($session_id);

        $wpdb = WpDb::get();

        $wpdb->delete($wpdb->prefix . 'awpt_messages', ['session_id' => $session_id], where_format: ['%d']);
        $wpdb->delete($wpdb->prefix . 'awpt_tool_calls', ['session_id' => $session_id], where_format: ['%d']);
        $wpdb->delete($wpdb->prefix . 'awpt_actions', ['session_id' => $session_id], where_format: ['%d']);
    }

    public function exists(int $session_id): bool {
        $wpdb = WpDb::get();

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}awpt_sessions WHERE id = %d AND user_id = %d",
            $session_id,
            $this->current_user_id(),
        ));

        return (int) $found === $session_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_summary(int $session_id): array {
        $wpdb = WpDb::get();

        $table = $wpdb->prefix . 'awpt_sessions';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, user_id, title, model, provider, focus_post_id, created_at, updated_at FROM {$table} WHERE id = %d AND user_id = %d",
                $session_id,
                $this->current_user_id(),
            ),
            output: \ARRAY_A,
        );

        return is_array($row) ? $this->with_focus_summary($row) : [];
    }

    private function current_user_id(): int {
        return get_current_user_id();
    }

    /**
     * Drop oldest sessions beyond {@see MAX_PER_USER} for the current admin.
     */
    private function prune_excess(): void {
        $wpdb = WpDb::get();
        $table = $wpdb->prefix . 'awpt_sessions';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC",
            $this->current_user_id(),
        ));

        if (!is_array($ids) || count($ids) <= self::MAX_PER_USER) {
            return;
        }

        foreach (array_slice($ids, self::MAX_PER_USER) as $overflow_id) {
            $this->delete((int) $overflow_id);
        }
    }

    /**
     * @param list<array<string, mixed>> $sessions
     * @return list<array<string, mixed>>
     */
    private function with_focus_summaries(array $sessions): array {
        return array_map($this->with_focus_summary(...), $sessions);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function with_focus_summary(array $session): array {
        $post_id = (int) ($session['focus_post_id'] ?? 0);
        $session['focus'] = null;

        if ($post_id <= 0) {
            return $session;
        }

        $post = get_post($post_id);

        if (!$post instanceof \WP_Post || !current_user_can('read_post', $post_id)) {
            return $session;
        }

        $session['focus'] = [
            'id' => $post_id,
            'title' => get_the_title($post),
            'type' => $post->post_type,
            'status' => $post->post_status,
            'slug' => $post->post_name,
            'url' => get_permalink($post),
            'edit_url' => (string) get_edit_post_link($post_id, 'raw'),
        ];

        return $session;
    }

    private function discard_preview_resources_for_session(int $session_id): void {
        $wpdb = WpDb::get();
        $preview = new StagedPostPreview();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT payload_json FROM {$wpdb->prefix}awpt_actions WHERE session_id = %d AND status = %s",
                $session_id,
                'proposed',
            ),
            output: \ARRAY_A,
        );

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $preview->discard_preview_resources(Json::decode_array((string) ($row['payload_json'] ?? '')));
        }
    }
}
