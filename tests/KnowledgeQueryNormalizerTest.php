<?php

/**
 * Knowledge query normalization tests.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Knowledge\KnowledgeQueryNormalizer;

function test_knowledge_query_normalizer_boosts_language_not_theme_slugs(): void {
    $normalizer = new KnowledgeQueryNormalizer();
    $out = $normalizer->for_retrieval('Why does the TOC look better in the editor than on the live page?');

    Assert::true(str_contains(strtolower($out), 'toc'), 'should keep toc');
    Assert::true(
        str_contains(strtolower($out), 'css') || str_contains(strtolower($out), 'frontend'),
        'should boost language terms like css/frontend',
    );
    Assert::false(
        str_contains(strtolower($out), 'layout-docs') || str_contains(strtolower($out), 'layout-page-documentation'),
        'must not inject theme-specific product slugs',
    );
    Assert::same(
        'Just a normal question about cats',
        $normalizer->for_retrieval('Just a normal question about cats'),
        'unrelated queries stay unchanged',
    );
}

test_knowledge_query_normalizer_boosts_language_not_theme_slugs();
