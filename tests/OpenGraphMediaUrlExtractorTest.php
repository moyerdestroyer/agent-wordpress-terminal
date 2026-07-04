<?php

/**
 * Tests for AWPT\Abilities\OpenGraphMediaUrlExtractor.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\OpenGraphMediaUrlExtractor;

function test_open_graph_media_url_extractor(): void
{
    $extractor = new OpenGraphMediaUrlExtractor();

    Assert::true($extractor->looks_like_html("<!DOCTYPE html>\n<html><head></head></html>"), 'doctype should be detected as html');
    Assert::true($extractor->looks_like_html('<html lang="en"><head></head></html>'), 'bare html tag should be detected as html');
    Assert::false($extractor->looks_like_html("GIF89a\x01\x00\x01\x00"), 'binary GIF header should not be detected as html');

    // Real-world shape: a Tenor share page's Open Graph tags (property before content).
    $tenor_html = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head>
        <meta class="dynamic" property="og:image" content="https://media1.tenor.com/m/IiH55vdiGIoAAAAC/qqq.gif">
        <meta class="dynamic" property="og:image:type" content="image/gif">
        <meta class="dynamic" property="og:video" content="https://media.tenor.com/IiH55vdiGIoAAAPo/qqq.mp4">
        </head>
        <body></body>
        </html>
        HTML;

    Assert::same(
        'https://media1.tenor.com/m/IiH55vdiGIoAAAAC/qqq.gif',
        $extractor->extract($tenor_html),
        'og:image should be preferred over og:video when both are present',
    );

    // Attribute order can be reversed (content before property/name).
    $reversed_html = '<meta content="https://example.com/reversed.png" property="og:image">';
    Assert::same(
        'https://example.com/reversed.png',
        $extractor->extract($reversed_html),
        'meta tags with content before property should still be found',
    );

    // Falls back to og:video when there is no og:image.
    $video_only_html = '<meta property="og:video:secure_url" content="https://example.com/clip.mp4">';
    Assert::same(
        'https://example.com/clip.mp4',
        $extractor->extract($video_only_html),
        'should fall back to og:video when no og:image is present',
    );

    // No usable tags at all.
    Assert::same(
        null,
        $extractor->extract('<html><head><title>No media here</title></head></html>'),
        'should return null when no known meta tags are present',
    );

    // HTML entities in the URL should be decoded.
    $encoded_html = '<meta property="og:image" content="https://example.com/img.png?a=1&amp;b=2">';
    Assert::same(
        'https://example.com/img.png?a=1&b=2',
        $extractor->extract($encoded_html),
        'HTML entities in the extracted URL should be decoded',
    );
}

test_open_graph_media_url_extractor();
