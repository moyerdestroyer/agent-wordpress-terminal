<?php

/**
 * Tests for Knowledge index embedding backfill detection.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Database\KnowledgeIndexRepository;

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public int $next_get_var = 0;
        /** @var list<mixed> */
        public array $last_prepare_args = [];
        public string $last_query = '';

        public function prepare(string $query, mixed ...$args): string {
            $this->last_prepare_args = $args;

            return $query;
        }

        public function get_var(string $query): int {
            unset($query);

            return $this->next_get_var;
        }

        /** @return list<array<string, mixed>> */
        public function get_results(string $query, string $output): array {
            unset($output);
            $this->last_query = $query;

            return [];
        }
    }
}

function test_source_needs_embeddings_detects_missing_vectors(): void {
    awpt_test_reset_state();
    $wpdb = new wpdb();
    $GLOBALS['wpdb'] = $wpdb;
    $repository = new KnowledgeIndexRepository();

    $wpdb->next_get_var = 3;
    Assert::true(
        $repository->source_needs_embeddings('wp_content:1'),
        'an unchanged source with missing vectors should be selected for backfill',
    );

    $wpdb->next_get_var = 0;
    Assert::false(
        $repository->source_needs_embeddings('wp_content:1'),
        'a fully embedded source should remain eligible for the unchanged-content fast path',
    );
}

function test_embedding_chunks_are_loaded_in_cursor_batches(): void {
    awpt_test_reset_state();
    $wpdb = new wpdb();
    $GLOBALS['wpdb'] = $wpdb;
    $repository = new KnowledgeIndexRepository();

    $rows = $repository->list_chunks_with_embeddings(75, 900);

    Assert::same([], $rows, 'embedding batch query should return repository rows');
    Assert::true(str_contains($wpdb->last_query, 'c.id < %d'), 'subsequent batches should use an ID cursor');
    Assert::same([[900, 75]], $wpdb->last_prepare_args, 'cursor and bounded batch size should parameterize the query');
}

test_source_needs_embeddings_detects_missing_vectors();
test_embedding_chunks_are_loaded_in_cursor_batches();
