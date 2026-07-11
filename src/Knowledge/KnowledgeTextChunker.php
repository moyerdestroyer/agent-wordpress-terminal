<?php

/**
 * Structure-aware text chunking for Knowledge indexing.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Splits text on headings/paragraphs, then windows large segments.
 */
final class KnowledgeTextChunker {
    public function __construct(
        private int $chunk_size = 3000,
        private int $chunk_overlap = 250,
    ) {
        $this->chunk_size = max(32, $chunk_size);
        $this->chunk_overlap = max(0, min($this->chunk_size - 1, $chunk_overlap));
    }

    /**
     * @return list<string>
     */
    public function chunk(string $content): array {
        $content = trim(str_replace(["\r\n", "\r"], "\n", $content));

        if ('' === $content) {
            return [];
        }

        $segments = $this->split_segments($content);
        $chunks = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);

            if ('' === $segment) {
                continue;
            }

            foreach ($this->window($segment) as $piece) {
                $chunks[] = $piece;
            }
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function split_segments(string $content): array {
        $parts = preg_split('/\n(?=#{1,6}\s)|\n{2,}/u', $content);

        if (!is_array($parts) || [] === $parts) {
            return [$content];
        }

        return array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => '' !== $part));
    }

    /**
     * @return list<string>
     */
    private function window(string $content): array {
        $normalized = preg_replace('/[ \t]+/', ' ', $content);
        $content = trim(is_string($normalized) ? $normalized : $content);
        $length = mb_strlen($content, 'UTF-8');

        if ($length <= $this->chunk_size) {
            return [$content];
        }

        $chunks = [];
        $offset = 0;

        while ($offset < $length) {
            $chunk = mb_substr($content, $offset, $this->chunk_size, 'UTF-8');

            if ('' !== trim($chunk)) {
                $chunks[] = trim($chunk);
            }

            $offset += $this->chunk_size - $this->chunk_overlap;
        }

        return $chunks;
    }
}
