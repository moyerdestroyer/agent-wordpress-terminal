<?php

/**
 * Shared normalize → validate ordering for staged post content.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Applies safe composition repairs before validators, then optional media checks.
 *
 * Content-update and pattern-insert paths previously validated wrappers before
 * normalization, so model tagName drift burned a propose attempt even when the
 * normalizer could have repaired it.
 */
final class PostContentStagingPipeline {
    public function __construct(
        private readonly PostCompositionNormalizer $normalizer = new PostCompositionNormalizer(),
        private readonly MediaBlockIntegrityValidator $media = new MediaBlockIntegrityValidator(),
    ) {}

    /**
     * Safe Gutenberg serialization repairs only (no media rejection).
     *
     * @return array{
     *     content: string,
     *     repairs: list<array{kind: string, block_path: string, block_name: string, description: string}>
     * }
     */
    public function normalize(string $content): array {
        return $this->normalizer->normalize($content);
    }

    /**
     * Normalize, then reject unresolved same-site Media Library URLs.
     *
     * @return array{
     *     content: string,
     *     repairs: list<array{kind: string, block_path: string, block_name: string, description: string}>
     * }|\WP_Error
     */
    public function prepare(string $content): array|\WP_Error {
        $normalized = $this->normalize($content);
        $error = $this->media->validate($normalized['content']);

        if ($error instanceof \WP_Error) {
            return $error;
        }

        return $normalized;
    }

    /**
     * Media integrity only — use after content has already been normalized.
     */
    public function validate_media(string $content): ?\WP_Error {
        return $this->media->validate($content);
    }
}
