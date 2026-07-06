<?php

/**
 * Tests for AWPT\Abilities\MediaSideloadValidator.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\MediaSideloadValidator;

function test_media_sideload_validator(): void {
    $validator = new MediaSideloadValidator();

    Assert::same(
        null,
        $validator->validate_url('https://tenor.com/c5QNxVlvu06.gif'),
        'a direct https URL with an allowed extension should validate',
    );

    Assert::same(
        null,
        $validator->validate_url('https://tenor.com/view/c5QNxVlvu06'),
        'extensionless share URLs should pass initial validation for OG resolution',
    );

    Assert::same(
        null,
        $validator->validate_url('http://example.com/path/to/image.PNG'),
        'extension matching should be case-insensitive for direct media URLs',
    );

    Assert::true(null !== $validator->validate_url(''), 'an empty URL should be rejected');

    Assert::true(
        null !== $validator->validate_url('ftp://example.com/bear.gif'),
        'non-http(s) schemes should be rejected',
    );

    Assert::true(
        null !== $validator->validate_url('file:///etc/passwd'),
        'file:// URLs should be rejected (no local file access)',
    );

    Assert::true(
        null !== $validator->validate_direct_media_url('https://example.com/malware.php'),
        'disallowed file extensions should be rejected for direct media URLs',
    );

    Assert::true(
        null !== $validator->validate_direct_media_url('https://example.com/no-extension-here'),
        'direct media URLs with no recognizable extension should be rejected',
    );

    Assert::same('gif', $validator->extension_from_url('https://tenor.com/c5QNxVlvu06.gif'), 'extension extraction');

    Assert::true($validator->is_size_allowed(1024), 'a small file should be within the size limit');
    Assert::false($validator->is_size_allowed(0), 'a zero-byte file should not be allowed');
    Assert::false(
        $validator->is_size_allowed(MediaSideloadValidator::MAX_BYTES + 1),
        'a file over the size limit should not be allowed',
    );
    Assert::true(
        $validator->is_size_allowed(MediaSideloadValidator::MAX_BYTES),
        'a file exactly at the size limit should be allowed',
    );
}

test_media_sideload_validator();
