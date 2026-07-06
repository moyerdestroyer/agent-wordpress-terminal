<?php

/**
 * Bounds tool output size for provider context and persistence.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Truncates large ability outputs while preserving structured summaries.
 */
final class ToolResultTruncator {
    private const PROVIDER_MAX_CHARS = 12_000;
    private const STORAGE_MAX_CHARS = 32_000;

    private ToolResultFieldShrinker $shrinker;
    private ToolResultSummaryBuilder $summary;

    public function __construct(?ToolResultFieldShrinker $shrinker = null, ?ToolResultSummaryBuilder $summary = null) {
        $this->shrinker = $shrinker ?? new ToolResultFieldShrinker();
        $this->summary = $summary ?? new ToolResultSummaryBuilder($this->shrinker);
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    public function for_provider(string $tool, array $output): array {
        return $this->truncate($tool, $output, self::PROVIDER_MAX_CHARS);
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    public function for_storage(string $tool, array $output): array {
        return $this->truncate($tool, $output, self::STORAGE_MAX_CHARS);
    }

    /**
     * @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private function truncate(string $tool, array $output, int $max_chars): array {
        if (ToolRegistry::is_proposal_ability($tool)) {
            return $output;
        }

        $output = $this->shrinker->shrink($tool, $output);
        $encoded = (string) wp_json_encode($output);

        if (mb_strlen($encoded, 'UTF-8') <= $max_chars) {
            return $output;
        }

        return $this->summary->build($tool, $output, strlen($encoded));
    }
}
