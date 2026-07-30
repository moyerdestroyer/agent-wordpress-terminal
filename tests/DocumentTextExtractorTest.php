<?php

/** Tests deterministic document extraction. @package AWPT */

declare(strict_types=1);

use AWPT\Knowledge\DocumentTextExtractor;

function test_document_text_extractor_reads_plain_text_with_metadata(): void {
    $path = tempnam(sys_get_temp_dir(), 'awpt-document-test-');

    if (!is_string($path)) {
        Assert::true(false, 'temporary document should be created');

        return;
    }

    file_put_contents($path, "First source paragraph.\n\nSecond source paragraph.");
    $result = new DocumentTextExtractor()->extract($path, 'text/plain', 'source.txt');
    unlink($path);

    Assert::same('plain_text', $result['method'], 'plain text should use the deterministic local extractor');
    Assert::true(
        str_contains($result['text'], 'Second source paragraph'),
        'extracted document content should preserve source text',
    );
    Assert::same(1, $result['page_count'], 'plain text should report one source page');
}

test_document_text_extractor_reads_plain_text_with_metadata();

function test_document_text_extractor_rejects_unsupported_binary_formats(): void {
    $result = new DocumentTextExtractor()->extract('/not/read.txt', 'application/octet-stream', 'archive.bin');

    Assert::same('unsupported', $result['method'], 'unknown binary documents should fail explicitly');
    Assert::same('', $result['text'], 'unknown binary documents should never invent content');
}

test_document_text_extractor_rejects_unsupported_binary_formats();
