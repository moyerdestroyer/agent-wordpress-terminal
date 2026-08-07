<?php

/**
 * Resumable Knowledge indexing runs and jobs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

if (!defined('ABSPATH')) {
    exit();
}

final class KnowledgeRunRepository {
    /**
     * Maximum attempts before a job is force-failed as a crash loop.
     *
     * {@see fail_job()} retries up to 3 times for caught exceptions, but jobs
     * that trigger PHP fatal errors (memory exhaustion, timeout) bypass the
     * catch block entirely — {@see recover_processing_jobs()} resets them to
     * 'queued' on every batch, creating an infinite crash loop. This cap
     * force-fails such jobs after a small number of extra attempts.
     */
    public const MAX_CRASH_ATTEMPTS = 5;

    /**
     * @param list<array<string, mixed>> $sources
     */
    public function create(string $profile, array $sources, string $now): int {
        $wpdb = WpDb::get();
        $wpdb->insert(
            $wpdb->prefix . 'awpt_knowledge_runs',
            [
                'status' => 'running',
                'phase' => 'processing',
                'index_profile' => $profile,
                'discovered_sources' => count($sources),
                'started_at' => $now,
                'heartbeat_at' => $now,
                'created_at' => $now,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s'],
        );
        $run_id = (int) $wpdb->insert_id;

        foreach ($sources as $source) {
            $payload = $source;
            $payload['content'] = '';
            $wpdb->insert(
                $wpdb->prefix . 'awpt_knowledge_jobs',
                [
                    'run_id' => $run_id,
                    'source_kind' => (string) ($source['kind'] ?? 'unknown'),
                    'source_id' => (string) ($source['source_id'] ?? ''),
                    'discovery_fingerprint' => (string) ($source['discovery_fingerprint'] ?? ''),
                    'payload_json' => wp_json_encode($payload),
                    'status' => 'queued',
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'],
            );
        }

        return $run_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function active(): ?array {
        $wpdb = WpDb::get();
        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}awpt_knowledge_runs
             WHERE status IN ('queued', 'running')
             ORDER BY id DESC LIMIT 1",
            output: \ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $run_id): ?array {
        $wpdb = WpDb::get();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d LIMIT 1', $wpdb->prefix . 'awpt_knowledge_runs', $run_id),
            output: \ARRAY_A,
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queued_jobs(int $run_id, int $limit): array {
        $wpdb = WpDb::get();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i
             WHERE run_id = %d AND status = 'queued'
             ORDER BY id ASC LIMIT %d",
                $wpdb->prefix . 'awpt_knowledge_jobs',
                $run_id,
                max(1, min(25, $limit)),
            ),
            output: \ARRAY_A,
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Reset jobs stuck in 'processing' back to 'queued'.
     *
     * Jobs that have exceeded {@see MAX_CRASH_ATTEMPTS} are force-failed
     * instead — they are in a fatal-error crash loop that the normal
     * {@see fail_job()} retry cap cannot catch.
     *
     * @return int Number of jobs force-failed as crash loops.
     */
    public function recover_processing_jobs(int $run_id, string $now): int {
        $wpdb = WpDb::get();

        $force_failed = (int) $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = 'failed',
                error_text = %s,
                updated_at = %s
             WHERE run_id = %d AND status = 'processing' AND attempts >= %d",
            $wpdb->prefix . 'awpt_knowledge_jobs',
            __('Source processing repeatedly crashed; skipped after too many attempts.', 'agent-wordpress-terminal'),
            $now,
            $run_id,
            self::MAX_CRASH_ATTEMPTS,
        ));

        if ($force_failed > 0) {
            $wpdb->query($wpdb->prepare(
                'UPDATE %i SET failed_sources = failed_sources + %d, processed_sources = processed_sources + %d
                 WHERE id = %d',
                $wpdb->prefix . 'awpt_knowledge_runs',
                $force_failed,
                $force_failed,
                $run_id,
            ));
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = 'queued', updated_at = %s
             WHERE run_id = %d AND status = 'processing'",
            $wpdb->prefix . 'awpt_knowledge_jobs',
            $now,
            $run_id,
        ));

        return $force_failed;
    }

    public function begin_job(int $job_id, string $now): void {
        $wpdb = WpDb::get();
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = 'processing', attempts = attempts + 1, updated_at = %s WHERE id = %d",
            $wpdb->prefix . 'awpt_knowledge_jobs',
            $now,
            $job_id,
        ));
    }

    public function complete_job(int $job_id, string $now): void {
        WpDb::get()->update(
            WpDb::get()->prefix . 'awpt_knowledge_jobs',
            ['status' => 'completed', 'error_text' => '', 'updated_at' => $now],
            ['id' => $job_id],
            ['%s', '%s', '%s'],
            ['%d'],
        );
    }

    public function fail_job(int $job_id, string $error, string $now): bool {
        $wpdb = WpDb::get();
        $attempts = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT attempts FROM %i WHERE id = %d',
            $wpdb->prefix . 'awpt_knowledge_jobs',
            $job_id,
        ));
        $retry = $attempts < 3;
        $wpdb->update(
            $wpdb->prefix . 'awpt_knowledge_jobs',
            [
                'status' => $retry ? 'queued' : 'failed',
                'error_text' => mb_substr($error, 0, 1000, 'UTF-8'),
                'updated_at' => $now,
            ],
            ['id' => $job_id],
            ['%s', '%s', '%s'],
            ['%d'],
        );

        return $retry;
    }

    /**
     * @param array<string, int> $increments
     */
    public function advance(int $run_id, array $increments, string $now): void {
        $allowed = [
            'processed_sources',
            'updated_sources',
            'unchanged_sources',
            'failed_sources',
            'indexed_chunks',
            'embedded_chunks',
        ];
        $sets = ['heartbeat_at = %s'];
        $params = [$now];

        foreach ($increments as $column => $amount) {
            if (!in_array($column, $allowed, true) || 0 === $amount) {
                continue;
            }

            $sets[] = "{$column} = {$column} + %d";
            $params[] = $amount;
        }

        $params[] = $run_id;
        WpDb::get()->query(WpDb::get()->prepare(
            'UPDATE ' . WpDb::get()->prefix . 'awpt_knowledge_runs SET ' . implode(', ', $sets) . ' WHERE id = %d',
            $params,
        ));
    }

    public function has_pending_jobs(int $run_id): bool {
        $wpdb = WpDb::get();

        return (
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE run_id = %d AND status IN ('queued', 'processing')",
                $wpdb->prefix . 'awpt_knowledge_jobs',
                $run_id,
            )) > 0
        );
    }

    public function finish(int $run_id, bool $success, string $now, string $error = ''): void {
        WpDb::get()->update(
            WpDb::get()->prefix . 'awpt_knowledge_runs',
            [
                'status' => $success ? 'completed' : 'failed',
                'phase' => $success ? 'completed' : 'failed',
                'error_text' => $error,
                'heartbeat_at' => $now,
                'finished_at' => $now,
            ],
            ['id' => $run_id],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent_failures(int $run_id, int $limit = 5): array {
        $wpdb = WpDb::get();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT source_kind, source_id, error_text
             FROM %i WHERE run_id = %d AND status = 'failed'
             ORDER BY id DESC LIMIT %d",
                $wpdb->prefix . 'awpt_knowledge_jobs',
                $run_id,
                max(1, min(20, $limit)),
            ),
            output: \ARRAY_A,
        );

        return is_array($rows) ? $rows : [];
    }
}
