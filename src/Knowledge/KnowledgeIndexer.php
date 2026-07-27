<?php

/**
 * Resumable local Knowledge indexing.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

use AWPT\Database\Installer;
use AWPT\Database\KnowledgeIndexRepository;
use AWPT\Database\KnowledgeRunRepository;

if (!defined('ABSPATH')) {
    exit();
}

final class KnowledgeIndexer {
    public const CRON_HOOK = 'awpt_knowledge_process_run';

    private const PROGRESS_OPTION = 'awpt_knowledge_rebuild_progress';
    private const COUNTS_OPTION = 'awpt_knowledge_index_counts';
    private const START_LOCK_OPTION = 'awpt_knowledge_start_lock';
    private const WORKER_LOCK_OPTION = 'awpt_knowledge_worker_lock';
    private const LOCK_TTL = 60;
    private const JOBS_PER_BATCH = 5;
    private const BATCH_TIME_LIMIT = 12.0;

    private KnowledgeIndexRepository $index;
    private KnowledgeRunRepository $runs;
    private KnowledgeSourceIndexer $source_indexer;
    private EmbeddingService $embeddings;

    public function __construct(
        ?KnowledgeIndexRepository $index = null,
        ?KnowledgeSourceIndexer $source_indexer = null,
        ?EmbeddingService $embeddings = null,
        ?KnowledgeRunRepository $runs = null,
    ) {
        $this->index = $index ?? new KnowledgeIndexRepository();
        $this->embeddings = $embeddings ?? new EmbeddingService();
        $this->source_indexer = $source_indexer ?? new KnowledgeSourceIndexer($this->index, null, $this->embeddings);
        $this->runs = $runs ?? new KnowledgeRunRepository();
    }

    public static function mark_stale(mixed ...$unused): void {
        update_option('awpt_knowledge_stale', '1', false);
    }

    public static function mark_post_stale(int $post_id, mixed $post = null): void {
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return;
        }

        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id)) {
            return;
        }

        $post = $post instanceof \WP_Post ? $post : get_post($post_id);

        if (
            $post instanceof \WP_Post
            && !in_array($post->post_type, new KnowledgeSiteContentTypes()->installed(), true)
        ) {
            return;
        }

        self::mark_stale();
    }

    public static function register_content_hooks(): void {
        add_action(self::CRON_HOOK, [self::class, 'run_scheduled_batch']);
        add_action('save_post', [self::class, 'mark_post_stale'], 10, 2);

        foreach ([
            'deleted_post',
            'trashed_post',
            'untrashed_post',
            'add_attachment',
            'edit_attachment',
            'delete_attachment',
            'switch_theme',
        ] as $hook) {
            add_action($hook, [self::class, 'mark_stale']);
        }
    }

    public static function run_scheduled_batch(int $run_id = 0): void {
        new self()->process_batch($run_id);
    }

    /**
     * Start a reconciliation and return immediately.
     *
     * @return array<string, mixed>
     */
    public function rebuild(): array {
        Installer::create_tables();
        $active = $this->runs->active();

        if (is_array($active)) {
            return [
                'in_progress' => true,
                'run_id' => (int) ($active['id'] ?? 0),
            ];
        }

        if (!self::acquire_lock(self::START_LOCK_OPTION)) {
            return ['in_progress' => true, 'run_id' => 0];
        }

        try {
            self::update_progress('discovering', [
                'phase' => 'discovering',
                'processed_sources' => 0,
                'total_sources' => 0,
                'indexed_sources' => 0,
                'indexed_chunks' => 0,
                'embedded_chunks' => 0,
                'unchanged_sources' => 0,
                'failed_sources' => 0,
            ]);
            $this->embeddings->clear_last_error();
            $repository = new KnowledgeRepository();
            $sources = array_merge(
                $repository->list_source_descriptors(),
                $repository->list_site_content_descriptors(),
                new FilesystemSourceReader()->list_descriptors(),
            );
            $now = current_time('mysql');
            $run_id = $this->runs->create(KnowledgeIndexProfile::value(), $sources, $now);
            update_option('awpt_knowledge_stale', '1', false);
            self::update_progress('indexing', [
                'run_id' => $run_id,
                'phase' => 'processing',
                'processed_sources' => 0,
                'total_sources' => count($sources),
                'indexed_sources' => 0,
                'indexed_chunks' => 0,
                'embedded_chunks' => 0,
                'unchanged_sources' => 0,
                'failed_sources' => 0,
            ]);
            self::schedule($run_id);

            return [
                'in_progress' => true,
                'run_id' => $run_id,
                'discovered_sources' => count($sources),
            ];
        } finally {
            self::release_lock(self::START_LOCK_OPTION);
        }
    }

    /**
     * Process a bounded set of source jobs.
     *
     * @return array<string, mixed>
     */
    public function process_batch(int $run_id = 0): array {
        if (!self::acquire_lock(self::WORKER_LOCK_OPTION)) {
            return ['in_progress' => true, 'run_id' => $run_id];
        }

        try {
            $run = $run_id > 0 ? $this->runs->find($run_id) : $this->runs->active();

            if (!is_array($run) || !in_array((string) ($run['status'] ?? ''), ['queued', 'running'], true)) {
                return ['in_progress' => false, 'run_id' => $run_id];
            }

            $run_id = (int) $run['id'];
            $now = current_time('mysql');
            $this->runs->recover_processing_jobs($run_id, $now);
            $started = microtime(true);
            $jobs = $this->runs->queued_jobs($run_id, self::JOBS_PER_BATCH);

            foreach ($jobs as $job) {
                if ((microtime(true) - $started) >= self::BATCH_TIME_LIMIT) {
                    break;
                }

                $this->process_job($run_id, $job);
                self::refresh_lock(self::WORKER_LOCK_OPTION);
            }

            if ($this->runs->has_pending_jobs($run_id)) {
                $this->sync_progress($run_id, 'indexing');
                self::schedule($run_id);

                return ['in_progress' => true, 'run_id' => $run_id];
            }

            return $this->finalize($run_id);
        } finally {
            self::release_lock(self::WORKER_LOCK_OPTION);
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function process_job(int $run_id, array $job): void {
        $job_id = (int) ($job['id'] ?? 0);
        $now = current_time('mysql');
        $this->runs->begin_job($job_id, $now);

        try {
            $payload = json_decode((string) ($job['payload_json'] ?? ''), true);

            if (!is_array($payload)) {
                throw new \RuntimeException(__('Invalid Knowledge source job.', 'agent-wordpress-terminal'));
            }

            /** @var array<string, mixed> $payload */
            $payload = array_filter(
                $payload,
                static fn(mixed $_value, mixed $key): bool => is_string($key),
                \ARRAY_FILTER_USE_BOTH,
            );

            $source_id = (string) ($job['source_id'] ?? '');
            $record = $this->index->source_record($source_id);
            $discovery_fingerprint = (string) ($job['discovery_fingerprint'] ?? '');
            $profile_current =
                is_array($record)
                && hash_equals((string) ($record['index_profile'] ?? ''), KnowledgeIndexProfile::value());
            $discovery_current =
                $profile_current
                && '' !== $discovery_fingerprint
                && hash_equals((string) ($record['discovery_fingerprint'] ?? ''), $discovery_fingerprint);
            $semantic_eligible =
                !array_key_exists('semantic_eligible', $payload) || (bool) $payload['semantic_eligible'];
            $embedding_current =
                !$semantic_eligible
                || !$this->embeddings->is_enabled()
                || !$this->index->source_needs_embedding_profile($source_id, $this->embeddings->profile());
            $local_vectors = 'local' === KnowledgeVectorIndex::resolve()->health()['backend'];

            if ($discovery_current && $embedding_current && $local_vectors) {
                $this->index->mark_source_seen($source_id, $run_id, $discovery_fingerprint);
                $this->runs->complete_job($job_id, $now);
                $this->runs->advance(
                    $run_id,
                    [
                        'processed_sources' => 1,
                        'unchanged_sources' => 1,
                    ],
                    $now,
                );

                return;
            }

            $source = $this->load_source($payload);

            if (null === $source) {
                if ($this->source_is_empty_or_removed($payload)) {
                    $this->runs->complete_job($job_id, $now);
                    $this->runs->advance($run_id, ['processed_sources' => 1], $now);

                    return;
                }

                throw new \RuntimeException(__(
                    'Source could not be read or contained no indexable text.',
                    'agent-wordpress-terminal',
                ));
            }

            $result = $this->source_indexer->index($source, $now, $run_id);

            if (null === $result) {
                $this->runs->complete_job($job_id, $now);
                $this->runs->advance($run_id, ['processed_sources' => 1], $now);

                return;
            }

            $this->runs->complete_job($job_id, $now);
            $this->runs->advance(
                $run_id,
                [
                    'processed_sources' => 1,
                    'updated_sources' => 'indexed' === $result['status'] ? 1 : 0,
                    'unchanged_sources' => 'skipped' === $result['status'] ? 1 : 0,
                    'indexed_chunks' => $result['chunks'],
                    'embedded_chunks' => $result['embedded'],
                ],
                $now,
            );
        } catch (\Throwable $throwable) {
            $retry = $this->runs->fail_job($job_id, $throwable->getMessage(), $now);

            if (!$retry) {
                $this->runs->advance(
                    $run_id,
                    [
                        'processed_sources' => 1,
                        'failed_sources' => 1,
                    ],
                    $now,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function load_source(array $payload): ?array {
        if ('filesystem' === (string) ($payload['kind'] ?? '') || '' !== (string) ($payload['path'] ?? '')) {
            return new FilesystemSourceReader()->load_descriptor($payload);
        }

        $post_id = (int) ($payload['post_id'] ?? 0);

        if ($post_id > 0) {
            $post = get_post($post_id);

            if (!$post instanceof \WP_Post) {
                return null;
            }

            $mapper = new KnowledgePostSourceMapper();

            return $mapper->from_post(
                $post,
                (string) ($payload['kind'] ?? 'wp_content'),
                $mapper->taxonomy_for_post_type($post->post_type),
            );
        }

        return '' !== trim((string) ($payload['content'] ?? '')) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function source_is_empty_or_removed(array $payload): bool {
        $path = (string) ($payload['path'] ?? '');

        if ('' !== $path) {
            return !file_exists($path) || is_readable($path);
        }

        $post_id = (int) ($payload['post_id'] ?? 0);

        return $post_id > 0 && !get_post($post_id) instanceof \WP_Post;
    }

    /**
     * @return array<string, mixed>
     */
    private function finalize(int $run_id): array {
        $run = $this->runs->find($run_id);
        $failed = (int) ($run['failed_sources'] ?? 0);
        $now = current_time('mysql');

        if ($failed > 0) {
            $message = sprintf(
                /* translators: %d: number of sources that failed indexing */
                __(
                    'Knowledge refresh stopped with %d source error(s); the previous usable rows were retained.',
                    'agent-wordpress-terminal',
                ),
                $failed,
            );
            $this->runs->finish($run_id, false, $now, $message);
            update_option('awpt_knowledge_last_error', $message, false);
            update_option('awpt_knowledge_stale', '1', false);
            $this->sync_progress($run_id, 'failed');

            return ['in_progress' => false, 'run_id' => $run_id, 'failed' => true];
        }

        $removed = $this->index->delete_sources_not_seen_in_run($run_id);

        if ([] !== $removed) {
            KnowledgeVectorIndex::resolve()->delete_chunks($removed);
        }

        $source_count = $this->index->count_sources();
        $chunk_count = $this->index->count_chunks();
        $embedded_count = $this->index->count_chunks_with_embedding_profile($this->embeddings->profile());
        update_option(
            self::COUNTS_OPTION,
            [
                'source_count' => $source_count,
                'chunk_count' => $chunk_count,
                'embedded_chunks' => $embedded_count,
            ],
            false,
        );
        update_option('awpt_knowledge_last_indexed_at', $now, false);
        update_option('awpt_knowledge_last_error', '', false);
        update_option('awpt_knowledge_stale', '0', false);
        $this->runs->finish($run_id, true, $now);
        $this->sync_progress($run_id, 'idle');

        return [
            'in_progress' => false,
            'run_id' => $run_id,
            'source_count' => $source_count,
            'chunk_count' => $chunk_count,
            'embedded_chunks' => $embedded_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array {
        return new KnowledgeIndexerStatus($this->index, $this->embeddings, $this->runs)->build();
    }

    /**
     * @return array<string, mixed>
     */
    public static function progress(): array {
        $raw = get_option(self::PROGRESS_OPTION, []);
        $progress = is_array($raw) ? $raw : [];

        return [
            'run_id' => max(0, (int) ($progress['run_id'] ?? 0)),
            'state' => in_array($progress['state'] ?? null, ['discovering', 'indexing', 'idle', 'failed'], true)
                ? $progress['state']
                : 'idle',
            'phase' => sanitize_key((string) ($progress['phase'] ?? 'idle')),
            'processed_sources' => max(0, (int) ($progress['processed_sources'] ?? 0)),
            'total_sources' => max(0, (int) ($progress['total_sources'] ?? 0)),
            'indexed_sources' => max(0, (int) ($progress['indexed_sources'] ?? 0)),
            'indexed_chunks' => max(0, (int) ($progress['indexed_chunks'] ?? 0)),
            'embedded_chunks' => max(0, (int) ($progress['embedded_chunks'] ?? 0)),
            'unchanged_sources' => max(0, (int) ($progress['unchanged_sources'] ?? 0)),
            'failed_sources' => max(0, (int) ($progress['failed_sources'] ?? 0)),
        ];
    }

    /**
     * @return array{source_count: int, chunk_count: int, embedded_chunks: int}
     */
    public static function cached_counts(): array {
        $raw = get_option(self::COUNTS_OPTION, []);
        $counts = is_array($raw) ? $raw : [];

        return [
            'source_count' => max(0, (int) ($counts['source_count'] ?? 0)),
            'chunk_count' => max(0, (int) ($counts['chunk_count'] ?? 0)),
            'embedded_chunks' => max(0, (int) ($counts['embedded_chunks'] ?? 0)),
        ];
    }

    public static function mark_rebuild_failed(): void {
        $progress = self::progress();
        self::update_progress('failed', array_merge($progress, ['phase' => 'failed']));
    }

    public static function rebuild_in_progress(): bool {
        return in_array(self::progress()['state'], ['discovering', 'indexing'], true);
    }

    public static function retrieval_is_available(): bool {
        return new KnowledgeIndexRepository()->count_chunks() > 0;
    }

    private function sync_progress(int $run_id, string $state): void {
        $run = $this->runs->find($run_id);

        if (!is_array($run)) {
            return;
        }

        self::update_progress($state, [
            'run_id' => $run_id,
            'phase' => (string) ($run['phase'] ?? $state),
            'processed_sources' => (int) ($run['processed_sources'] ?? 0),
            'total_sources' => (int) ($run['discovered_sources'] ?? 0),
            'indexed_sources' => (int) ($run['updated_sources'] ?? 0),
            'indexed_chunks' => (int) ($run['indexed_chunks'] ?? 0),
            'embedded_chunks' => (int) ($run['embedded_chunks'] ?? 0),
            'unchanged_sources' => (int) ($run['unchanged_sources'] ?? 0),
            'failed_sources' => (int) ($run['failed_sources'] ?? 0),
        ]);
    }

    /**
     * @param array<string, mixed> $counts
     */
    private static function update_progress(string $state, array $counts): void {
        update_option(self::PROGRESS_OPTION, array_merge($counts, ['state' => $state]), false);
    }

    private static function schedule(int $run_id): void {
        if (function_exists('wp_next_scheduled') && wp_next_scheduled(self::CRON_HOOK, [$run_id])) {
            return;
        }

        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 1, self::CRON_HOOK, [$run_id]);
        }
    }

    private static function acquire_lock(string $option): bool {
        $existing = (int) get_option($option, 0);

        if ($existing > 0 && ($existing + self::LOCK_TTL) >= time()) {
            return false;
        }

        if ($existing > 0) {
            delete_option($option);
        }

        return add_option($option, time(), '', false);
    }

    private static function refresh_lock(string $option): void {
        update_option($option, time(), false);
    }

    private static function release_lock(string $option): void {
        delete_option($option);
    }
}
