<?php

/**
 * Refreshes the knowledge index after Dufresne import or rollback.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/** Coordinates a single deferred rebuild once an import or rollback has settled. */
final class DufresneKnowledgeRefresh {
    public const CRON_HOOK = 'awpt_rebuild_knowledge_after_dufresne_import';

    private const DELAY_SECONDS = 30;

    public static function register(): void {
        add_action('dufresne_wp_plugin_run_completed', [self::class, 'schedule'], 10, 2);
        add_action('dufresne_wp_plugin_after_rollback', [self::class, 'on_rollback'], 10, 2);
        add_action(self::CRON_HOOK, [self::class, 'rebuild']);
    }

    /**
     * @param array<string, mixed> $summary
     */
    public static function schedule(int $run_id, array $summary = []): void {
        unset($run_id);

        if (!self::should_schedule($summary)) {
            return;
        }

        self::schedule_deferred_rebuild();
    }

    /**
     * @param array{deleted_posts?: int, deleted_media?: int, deleted_terms?: int}|mixed $result
     */
    public static function on_rollback(int $run_id, mixed $result = []): void {
        unset($run_id, $result);
        KnowledgeIndexer::mark_stale();
        self::schedule_deferred_rebuild();
    }

    /** @param array<string, mixed> $summary */
    public static function should_schedule(array $summary): bool {
        return in_array((string) ($summary['status'] ?? ''), ['completed', 'completed_with_errors'], true);
    }

    public static function rebuild(): void {
        new KnowledgeIndexer()->rebuild();
    }

    private static function schedule_deferred_rebuild(): void {
        // Multiple imports/rollbacks can finish close together. One deferred rebuild
        // indexes their combined final state and avoids starting competing index runs.
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_single_event(time() + self::DELAY_SECONDS, self::CRON_HOOK);
    }
}
