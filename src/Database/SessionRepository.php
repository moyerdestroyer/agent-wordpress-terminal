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
final class SessionRepository
{
    private SessionHydrator $hydrator;

    public function __construct(?SessionHydrator $hydrator = null)
    {
        $this->hydrator = $hydrator ?? new SessionHydrator();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list_summaries(): array
    {
        $wpdb = WpDb::get();

        $table = $wpdb->prefix . 'awpt_sessions';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, title, model, provider, focus_post_id, created_at, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT 50",
                $this->current_user_id(),
            ),
            output: \ARRAY_A,
        );

        return $this->with_focus_summaries(is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_detail(int $session_id, int $messages_limit = 50, bool $include_tool_outputs = false): ?array
    {
        $messages_limit = max(1, min(200, $messages_limit));
        $wpdb = WpDb::get();

        $sessions = $wpdb->prefix . 'awpt_sessions';
        $messages = $wpdb->prefix . 'awpt_messages';
        $tool_calls = $wpdb->prefix . 'awpt_tool_calls';
        $actions = $wpdb->prefix . 'awpt_actions';

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$sessions} WHERE id = %d AND user_id = %d",
                $session_id,
                $this->current_user_id(),
            ),
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

        return $this->with_focus_summary($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $title): array
    {
        $wpdb = WpDb::get();

        $now = current_time('mysql');
        $table = $wpdb->prefix . 'awpt_sessions';

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id' => $this->current_user_id(),
                'title' => $title,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            format: ['%d', '%s', '%s', '%s'],
        );

        if (false === $inserted) {
            return [];
        }

        return [
            'id' => (int) $wpdb->insert_id,
            'user_id' => $this->current_user_id(),
            'title' => $title,
            'focus' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function update_title(int $session_id, string $title): ?array
    {
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
    public function update_fields(int $session_id, array $fields, array $formats): void
    {
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

    public function delete(int $session_id): void
    {
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

    public function clear_transcript(int $session_id): void
    {
        if (!$this->exists($session_id)) {
            return;
        }

        $this->discard_preview_resources_for_session($session_id);

        $wpdb = WpDb::get();

        $wpdb->delete($wpdb->prefix . 'awpt_messages', ['session_id' => $session_id], where_format: ['%d']);
        $wpdb->delete($wpdb->prefix . 'awpt_tool_calls', ['session_id' => $session_id], where_format: ['%d']);
        $wpdb->delete($wpdb->prefix . 'awpt_actions', ['session_id' => $session_id], where_format: ['%d']);
    }

    public function exists(int $session_id): bool
    {
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
    public function get_summary(int $session_id): array
    {
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

    private function current_user_id(): int
    {
        return get_current_user_id();
    }

    /**
     * @param list<array<string, mixed>> $sessions
     * @return list<array<string, mixed>>
     */
    private function with_focus_summaries(array $sessions): array
    {
        return array_map($this->with_focus_summary(...), $sessions);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function with_focus_summary(array $session): array
    {
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

    private function discard_preview_resources_for_session(int $session_id): void
    {
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
