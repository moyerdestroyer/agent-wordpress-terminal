<?php

/**
 * Tests for EmbeddingService pure math helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Knowledge\EmbeddingService;

function test_cosine_identical_vectors(): void {
    $service = new EmbeddingService();
    $vector = [1.0, 0.0, 0.0];

    Assert::true(
        abs($service->cosine_similarity($vector, $vector) - 1.0) < 0.0001,
        'identical vectors should have raw cosine 1.0',
    );
}

function test_cosine_orthogonal_vectors(): void {
    $service = new EmbeddingService();
    $left = [1.0, 0.0];
    $right = [0.0, 1.0];

    Assert::true(
        abs($service->cosine_similarity($left, $right)) < 0.0001,
        'orthogonal vectors should have raw cosine 0.0',
    );
}

function test_cosine_empty_vectors(): void {
    $service = new EmbeddingService();

    Assert::same(0.0, $service->cosine_similarity([], [1.0]), 'empty left vector');
    Assert::same(0.0, $service->cosine_similarity([1.0], []), 'empty right vector');
    Assert::same(0.0, $service->cosine_similarity([1.0, 2.0], [1.0]), 'dimension mismatch');
}

test_cosine_identical_vectors();
test_cosine_orthogonal_vectors();
test_cosine_empty_vectors();
