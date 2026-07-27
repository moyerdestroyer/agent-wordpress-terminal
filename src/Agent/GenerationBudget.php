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
    /**
     * @param array{
     *     prior_user_messages?: list<string>,
     *     has_open_new_post_proposal?: bool
     * } $context Session evidence for retry classification.
     */
    public function for_message(string $message, int $tool_round = 0, array $context = []): int {
        if (!$this->is_content_request($message, $context)) {
            return 8192;
        }

        // The first call generally selects tools; reserve the larger budget for the
        // post-tool draft without allowing every loop iteration to consume it.
        return $tool_round > 0 ? 24_000 : 6_000;
    }

    /**
     * @param array{
     *     prior_user_messages?: list<string>,
     *     has_open_new_post_proposal?: bool
     * } $context Session evidence for retry classification.
     */
    public function is_content_request(string $message, array $context = []): bool {
        if ($this->message_is_content_request($message)) {
            return true;
        }

        if (!$this->message_is_retry_request($message)) {
            return false;
        }

        if ($context['has_open_new_post_proposal'] ?? false) {
            return true;
        }

        $prior = $context['prior_user_messages'] ?? [];

        foreach ($prior as $prior_message) {
            if ('' === trim($prior_message)) {
                continue;
            }

            // Skip the current message if the caller included it in the prior list.
            if (trim($prior_message) === trim($message)) {
                continue;
            }

            if ($this->message_is_content_request($prior_message)) {
                return true;
            }
        }

        return false;
    }

    private function message_is_content_request(string $message): bool {
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

    private function message_is_retry_request(string $message): bool {
        return (bool) preg_match(
            '/\b('
            . 'try\s+again|try\s+it\s+again|retry|once\s+more|keep\s+going|finish\s+it|do\s+it\s+again'
            . ')\b/i',
            $message,
        );
    }
}
