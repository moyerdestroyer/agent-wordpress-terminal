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

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_detail(int $session_id): ?array
    {
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
                "SELECT id, role, content, created_at FROM {$messages} WHERE session_id = %d ORDER BY id ASC",
                $session_id,
            ),
            output: \ARRAY_A,
        );
        $session['messages'] = is_array($message_rows) ? $message_rows : [];

        $tool_call_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, tool_name, input_json, output_json, status, created_at FROM {$tool_calls} WHERE session_id = %d ORDER BY id ASC",
                $session_id,
            ),
            output: \ARRAY_A,
        );
        $session['tool_calls'] = $this->hydrator->tool_calls(is_array($tool_call_rows) ? $tool_call_rows : []);

        $action_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, session_id, title, description, payload_json, status, created_at, updated_at FROM {$actions} WHERE session_id = %d ORDER BY id DESC",
                $session_id,
            ),
            output: \ARRAY_A,
        );
        $session['actions'] = $this->hydrator->actions(is_array($action_rows) ? $action_rows : []);

        return $session;
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

        return is_array($row) ? $row : [];
    }

    private function current_user_id(): int
    {
        return get_current_user_id();
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
