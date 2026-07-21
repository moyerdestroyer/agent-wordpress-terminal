<?php

/**
 * Completion token budgets for agent turns.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/** Chooses a bounded completion budget for a single agent turn. */
final class GenerationBudget {
    public function for_message(string $message, int $tool_round = 0): int {
        if (!$this->is_content_request($message)) {
            return 8192;
        }

        // The first call generally selects tools; reserve the larger budget for the
        // post-tool draft without allowing every loop iteration to consume it.
        return $tool_round > 0 ? 24_000 : 6_000;
    }

    public function is_content_request(string $message): bool {
        if ((bool) preg_match(
            '/\b(create|generate|make|build|design|draft|write)\b.*\b(page|landing|post|article|homepage)\b/i',
            $message,
        )) {
            return true;
        }

        // Revisions of staged drafts are still full composition turns (complete
        // post_content + propose-new-post), even when the user only names a section.
        return (bool) preg_match(
            '/\b('
            . 'add|include|append|insert|update|revise|change|improve|expand|extend|rewrite|replace|need|want'
            . ')\b.+\b('
            . 'section|hero|pattern|block|paragraph|image|content|draft|proposal|page|post|layout|footer|header'
            . '|recent posts'
            . ')\b/i',
            $message,
        );
    }
}
