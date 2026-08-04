<?php

/**
 * Runs deterministic Media Library repairs and rejects ambiguous local media.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

final class PostContentMediaIntegrity {
    public function __construct(
        private readonly PostCompositionNormalizer $normalizer = new PostCompositionNormalizer(),
        private readonly MediaBlockIntegrityValidator $validator = new MediaBlockIntegrityValidator(),
    ) {}

    /**
     * @return array{content: string, repairs: list<array{kind: string, block_path: string, block_name: string, description: string}>}|\WP_Error
     */
    public function prepare(string $content): array|\WP_Error {
        $normalized = $this->normalizer->normalize($content);
        $error = $this->validator->validate($normalized['content']);

        if ($error instanceof \WP_Error) {
            return $error;
        }

        return $normalized;
    }
}
