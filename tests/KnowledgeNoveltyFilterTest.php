<?php

/**
 * Knowledge novelty selection contracts.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Knowledge\KnowledgeNoveltyFilter;
use AWPT\Knowledge\KnowledgeQueryNovelty;

function test_knowledge_novelty_separates_known_chunks_without_losing_references(): void {
    $candidates = [
        [
            'chunk_id' => 'known-a',
            'source_id' => 'theme:docs',
            'source_kind' => 'theme_file',
            'label' => 'Theme docs',
            'metadata' => ['heading_path' => 'Layouts'],
        ],
        [
            'chunk_id' => 'new-b',
            'source_id' => 'theme:styles',
            'source_kind' => 'theme_file',
            'label' => 'Theme styles',
            'metadata' => ['heading_path' => 'Hero'],
        ],
    ];
    $result = new KnowledgeNoveltyFilter()->select($candidates, ['known-a'], 5);

    Assert::same(1, $result['novel_count'], 'one unseen chunk should be returned');
    Assert::same('new-b', $result['items'][0]['chunk_id'] ?? '', 'new chunk should remain actionable');
    Assert::same(1, $result['reused_count'], 'known match should be reported');
    Assert::same('Layouts', $result['known_matches'][0]['heading_path'] ?? '', 'known section retained');
    Assert::false($result['exhausted'], 'a query with a new chunk is not exhausted');
}

function test_knowledge_novelty_marks_fully_repeated_query_exhausted(): void {
    $result = new KnowledgeNoveltyFilter()->select(
        [
            ['chunk_id' => 'known-a', 'source_id' => 'theme:docs', 'label' => 'Theme docs'],
        ],
        ['known-a'],
        5,
    );

    Assert::same(0, $result['novel_count'], 'repeated query should not repeat excerpts');
    Assert::true($result['exhausted'], 'known-only candidates should exhaust the refinement');
}

test_knowledge_novelty_separates_known_chunks_without_losing_references();
test_knowledge_novelty_marks_fully_repeated_query_exhausted();

function test_knowledge_query_novelty_detects_equivalent_but_allows_real_refinement(): void {
    $novelty = new KnowledgeQueryNovelty();
    $prior = ['CivicPress responsive hero layout CSS classes'];

    Assert::true(
        $novelty->repeats('responsive CivicPress hero CSS layout classes', $prior),
        'reordered equivalent query should be recognized',
    );
    Assert::false(
        $novelty->repeats('CivicPress footer navigation accessibility contract', $prior),
        'a materially different evidence gap should remain searchable',
    );
}

test_knowledge_query_novelty_detects_equivalent_but_allows_real_refinement();
