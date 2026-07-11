<?php

/**
 * Tests for KnowledgeTextChunker.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Knowledge\KnowledgeTextChunker;

function test_knowledge_text_chunker_splits_on_headings(): void {
    $chunker = new KnowledgeTextChunker(80, 10);
    $chunks = $chunker->chunk(
        "# Brand\n\nWe sound warm and direct.\n\n## Voice\n\nPrefer short sentences.\n\n## Color\n\nPrimary is blue.",
    );

    Assert::true(count($chunks) >= 3, 'heading/paragraph splits should produce multiple chunks');
    Assert::true(
        str_contains(implode("\n", $chunks), 'Brand') && str_contains(implode("\n", $chunks), 'Voice'),
        'chunks should retain section content',
    );
}

function test_knowledge_text_chunker_windows_long_segments(): void {
    $chunker = new KnowledgeTextChunker(40, 5);
    $text = str_repeat('word ', 80);
    $chunks = $chunker->chunk($text);

    Assert::true(count($chunks) > 1, 'long paragraphs should be windowed');
    Assert::true(mb_strlen($chunks[0], 'UTF-8') <= 40, 'window size should stay near the configured chunk size');
}

test_knowledge_text_chunker_splits_on_headings();
test_knowledge_text_chunker_windows_long_segments();
