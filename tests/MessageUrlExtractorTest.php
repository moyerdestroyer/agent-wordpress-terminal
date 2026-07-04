<?php

/**
 * Tests for AWPT\Support\MessageUrlExtractor.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\MessageUrlExtractor;

function test_message_url_extractor(): void
{
    $extractor = new MessageUrlExtractor();

    Assert::same(
        ['https://example.com/image.jpg'],
        $extractor->extract('Use this image: https://example.com/image.jpg'),
        'a bare URL in a sentence should be extracted',
    );

    Assert::same(
        [],
        $extractor->extract('There is no URL in this message.'),
        'a message with no URL should return an empty list',
    );

    Assert::same(
        ['https://example.com/image.jpg'],
        $extractor->extract('Check this out (https://example.com/image.jpg) it is great'),
        'a trailing closing paren from surrounding prose should be trimmed',
    );

    Assert::same(
        ['https://example.com/a.jpg', 'https://example.com/b.png'],
        $extractor->extract('First https://example.com/a.jpg and also https://example.com/b.png please'),
        'multiple URLs in one message should all be extracted, in order',
    );

    Assert::same(
        ['https://example.com/page'],
        $extractor->extract('See https://example.com/page. Thanks!'),
        'a trailing sentence period should be trimmed from the URL',
    );

    Assert::same(
        ['https://static.wikia.nocookie.net/marveldatabase/images/7/72/Wanda_Wilson_%28Earth-19647%29_from_Deadpool_Team-Up_Vol_3_4_001.jpg'],
        $extractor->extract(
            'Use this image: https://static.wikia.nocookie.net/marveldatabase/images/7/72/'
                . 'Wanda_Wilson_%28Earth-19647%29_from_Deadpool_Team-Up_Vol_3_4_001.jpg',
        ),
        'a real-world percent-encoded URL with parentheses should be extracted whole',
    );
}

test_message_url_extractor();
