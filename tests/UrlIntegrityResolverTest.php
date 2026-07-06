<?php

/**
 * Regression test for AWPT\Support\UrlIntegrityResolver.
 *
 * Guards against a real, reproduced failure: a model retyping a URL into a tool call
 * dropped the "(Earth-19647)" parenthetical segment from a real Wikia URL, producing a
 * URL that 404s, even though the user's original message (character-for-character)
 * still had the correct, working URL.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\UrlIntegrityResolver;

function test_url_integrity_resolver(): void {
    $resolver = new UrlIntegrityResolver();

    $real_url =
        'https://static.wikia.nocookie.net/marveldatabase/images/7/72/'
        . 'Wanda_Wilson_%28Earth-19647%29_from_Deadpool_Team-Up_Vol_3_4_001.jpg';

    // The exact real-world corruption: the model dropped the parentheses entirely.
    $corrupted_url =
        'https://static.wikia.nocookie.net/marveldatabase/images/7/72/'
        . 'Wanda_Wilson_Earth-19647_from_Deadpool_Team-Up_Vol_3_4_001.jpg';

    Assert::same(
        $real_url,
        $resolver->resolve($corrupted_url, [$real_url]),
        'a URL with dropped parentheses should resolve back to the verified original',
    );

    // The same corruption, but where the "known" URL used literal (not percent-encoded)
    // parentheses — should still match after normalization.
    $real_url_literal_parens =
        'https://static.wikia.nocookie.net/marveldatabase/images/7/72/'
        . 'Wanda_Wilson_(Earth-19647)_from_Deadpool_Team-Up_Vol_3_4_001.jpg';

    Assert::same(
        $real_url_literal_parens,
        $resolver->resolve($corrupted_url, [$real_url_literal_parens]),
        'matching should work regardless of whether the known URL uses literal or percent-encoded punctuation',
    );

    // An exact match is returned unchanged.
    Assert::same(
        $real_url,
        $resolver->resolve($real_url, [$real_url]),
        'an already-correct URL should be returned unchanged',
    );

    // A genuinely different URL (not a corrupted match of anything known) is left alone
    // rather than being forced to match something unrelated.
    $unrelated_url = 'https://example.com/totally-different-image.png';
    Assert::same(
        $unrelated_url,
        $resolver->resolve($unrelated_url, [$real_url]),
        'an unrelated URL should not be rewritten to a known URL that does not match it',
    );

    // No known URLs at all: candidate is returned unchanged.
    Assert::same(
        $corrupted_url,
        $resolver->resolve($corrupted_url, []),
        'with no known URLs to compare against, the candidate should be returned unchanged',
    );
}

test_url_integrity_resolver();
