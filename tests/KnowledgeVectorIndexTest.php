<?php

/**
 * Tests for the replaceable Knowledge vector index contract.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\ListKnowledgeSources;
use AWPT\Abilities\SearchKnowledge;
use AWPT\Knowledge\KnowledgeVectorIndex;
use AWPT\Knowledge\KnowledgeVectorIndexInterface;

final class AwptTestVectorIndex implements KnowledgeVectorIndexInterface {
    public function upsert_chunks(array $chunks, string $profile): void {
        unset($chunks, $profile);
    }

    public function delete_chunks(array $chunk_ids): void {
        unset($chunk_ids);
    }

    public function query(array $query_vector, string $profile, int $limit): array {
        unset($query_vector, $profile, $limit);

        return [['chunk_id' => 'stable-id', 'score' => 0.9]];
    }

    public function health(): array {
        return ['backend' => 'test-vector', 'available' => true, 'detail' => 'Ready'];
    }
}

function test_knowledge_vector_index_is_replaceable(): void {
    awpt_test_reset_state();
    $backend = new AwptTestVectorIndex();
    add_filter('awpt_knowledge_vector_index', static fn(): KnowledgeVectorIndexInterface => $backend);

    Assert::same('test-vector', KnowledgeVectorIndex::resolve()->health()['backend'], 'filtered vector backend');
}

function test_knowledge_discovery_requires_admin_capability(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_current_user_can'] = static fn(string $capability): bool => 'edit_posts' === $capability;

    Assert::true(
        !new SearchKnowledge()->can_search(['query' => 'private draft']),
        'editors must not search the administrator corpus',
    );
    Assert::true(!new ListKnowledgeSources()->can_list([]), 'editors must not enumerate the administrator corpus');

    $GLOBALS['awpt_test_current_user_can'] = static fn(string $capability): bool => 'manage_options' === $capability;
    Assert::true(new SearchKnowledge()->can_search([
        'query' => 'private draft',
    ]), 'administrators may search the corpus');
}

test_knowledge_vector_index_is_replaceable();
test_knowledge_discovery_requires_admin_capability();
