<?php

/** Tests PDF extractor size/timeout guards. @package AWPT */

declare(strict_types=1);

use AWPT\Knowledge\PdfTextExtractor;

function test_pdf_text_extractor_skips_unreadable_paths(): void {
    $result = new PdfTextExtractor()->extract_with_metadata('/tmp/awpt-missing-' . uniqid('', true) . '.pdf');

    Assert::same('unavailable', $result['method'], 'missing PDFs should report unavailable');
    Assert::same('', $result['text'], 'missing PDFs should never invent content');
    Assert::true('' !== $result['warning'], 'missing PDFs should surface a warning');
}

test_pdf_text_extractor_skips_unreadable_paths();

function test_pdf_text_extractor_rejects_non_pdf_bytes_for_raw_scan_path(): void {
    $path = tempnam(sys_get_temp_dir(), 'awpt-not-pdf-');

    if (!is_string($path)) {
        Assert::true(false, 'temporary file should be created');

        return;
    }

    file_put_contents($path, 'not a pdf');
    $result = new PdfTextExtractor()->extract_with_metadata($path);
    unlink($path);

    Assert::same('unavailable', $result['method'], 'non-PDF bytes should not be treated as extractable PDF text');
    Assert::same('', $result['text'], 'non-PDF bytes should yield empty text');
}

test_pdf_text_extractor_rejects_non_pdf_bytes_for_raw_scan_path();
