<?php

/**
 * Tests for KnowledgeTextChunker.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Knowledge\KnowledgeChunkIdentity;
use AWPT\Knowledge\KnowledgeTextChunker;

function test_knowledge_text_chunker_splits_on_headings(): void {
    $chunker = new KnowledgeTextChunker(80, 10);
    $chunks = $chunker->chunk(
        "# Brand\n\nWe sound warm and direct.\n\n## Voice\n\nPrefer short sentences.\n\n## Color\n\nPrimary is blue.",
    );

    Assert::true(count($chunks) >= 1, 'heading sections should produce searchable chunks');
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

function test_knowledge_text_chunker_preserves_heading_context(): void {
    $chunks = new KnowledgeTextChunker()->chunks(
        "# Brand\n\nOpening copy.\n\n## Voice\n\nPrefer direct sentences.\n\nAnother paragraph.",
    );
    $voice = array_values(array_filter($chunks, static fn(array $chunk): bool => str_contains(
        (string) ($chunk['heading_path'] ?? ''),
        'Voice',
    )));

    Assert::same(1, count($voice), 'paragraphs in one section should be packed together');
    Assert::true(
        str_contains((string) $voice[0]['heading_path'], 'Brand > Voice'),
        'heading ancestry should be retained',
    );
}

function test_knowledge_text_chunker_preserves_pdf_pages_and_json_paths(): void {
    $chunker = new KnowledgeTextChunker();
    $pdf = $chunker->chunks("First page.\fSecond page.", 'pdf');
    $json = $chunker->chunks('{"settings":{"color":{"primary":"#123456","secondary":"#ffffff"}}}', 'json');

    Assert::same(1, $pdf[0]['page_start'], 'first PDF chunk should identify page one');
    Assert::same(2, $pdf[1]['page_start'], 'second PDF chunk should identify page two');
    Assert::true(
        str_contains(implode(' ', array_column($json, 'heading_path')), 'settings.color'),
        'JSON chunks should retain object paths',
    );
}

function test_knowledge_text_chunker_respects_default_token_ceiling(): void {
    $chunks = new KnowledgeTextChunker()->chunks(str_repeat('A complete sentence with useful words. ', 900));

    Assert::true(count($chunks) > 1, 'long prose should create multiple chunks');

    foreach ($chunks as $chunk) {
        Assert::true((int) $chunk['token_estimate'] <= 900, 'production chunks must respect the token ceiling');
    }
}

function test_knowledge_chunk_identity_is_deterministic(): void {
    $first = KnowledgeChunkIdentity::make('wp_content:42', 'Brand > Voice', 0, 'content-hash');
    $second = KnowledgeChunkIdentity::make('wp_content:42', 'Brand > Voice', 0, 'content-hash');
    $changed = KnowledgeChunkIdentity::make('wp_content:42', 'Brand > Voice', 0, 'changed-hash');

    Assert::same($first, $second, 'unchanged chunk inputs should retain a stable id');
    Assert::true($first !== $changed, 'changed chunk content should receive a new id');
}

test_knowledge_text_chunker_splits_on_headings();
test_knowledge_text_chunker_windows_long_segments();
test_knowledge_text_chunker_preserves_heading_context();
test_knowledge_text_chunker_preserves_pdf_pages_and_json_paths();
test_knowledge_text_chunker_respects_default_token_ceiling();
test_knowledge_chunk_identity_is_deterministic();
