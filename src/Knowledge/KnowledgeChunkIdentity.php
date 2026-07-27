<?php

/**
 * Stable Knowledge chunk identity.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

final class KnowledgeChunkIdentity {
    public static function make(
        string $source_id,
        string $section_key,
        int $section_ordinal,
        string $chunk_hash,
    ): string {
        return hash('sha256', implode("\0", [
            $source_id,
            KnowledgeTextChunker::VERSION,
            $section_key,
            (string) $section_ordinal,
            $chunk_hash,
        ]));
    }
}
