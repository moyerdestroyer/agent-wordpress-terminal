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
        if (!$this->is_content_request($message, $context) && !$this->is_content_edit_request($message, $context)) {
            return 8192;
        }

        // The first call generally selects tools; reserve the larger budget for the
        // post-tool draft without allowing every loop iteration to consume it.
        return $tool_round > 0 ? 24_000 : 6_000;
    }

    /**
     * Existing-content edits need composition headroom without entering the
     * new-post-only discovery gate.
     *
     * @param array{
     *     prior_user_messages?: list<string>,
     *     has_open_new_post_proposal?: bool
     * } $context Session evidence for follow-up classification.
     */
    public function is_content_edit_request(string $message, array $context = []): bool {
        if ($this->message_is_content_edit_request($message)) {
            return true;
        }

        if (!$this->message_inherits_content_edit($message)) {
            return false;
        }

        foreach ($context['prior_user_messages'] ?? [] as $prior_message) {
            if ('' === trim($prior_message) || trim($prior_message) === trim($message)) {
                continue;
            }

            if ($this->message_is_content_edit_request($prior_message)) {
                return true;
            }
        }

        return false;
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
        // Existing numeric targets are edits even when composition words overlap.
        // Keep them out of the new-post-only path unless creation is explicit.
        if (
            (bool) preg_match('/\b(page|post)\s*#?\s*\d+\b/i', $message)
            && !(bool) preg_match('/\b(create|generate|build|draft|write|new)\b/i', $message)
        ) {
            return false;
        }

        if ((bool) preg_match(
            '/\b(create|generate|make|build|design|draft|write)\b.*\b(page|landing|post|article|homepage)\b/i',
            $message,
        )) {
            return true;
        }

        // Preservation constraints must not turn an in-place edit into a new
        // composition request. For example, "do not add or remove content"
        // is a guardrail on a heading change, not an instruction to add a new
        // content section.
        $positive_message = (string) preg_replace(
            '/\b(?:do\s+not|don\'t|without|never)\s+(?:add|include|append|insert|update|revise|change|improve|expand|extend|rewrite|replace)\b[^.!?]*/i',
            '',
            $message,
        );

        // Revisions of staged drafts are still full composition turns (complete
        // post_content + propose-new-post), even when the user only names a section.
        return (bool) preg_match(
            '/\b('
            . 'add|include|append|insert|update|revise|change|improve|expand|extend|rewrite|replace|need|want'
            . ')\b.+\b('
            . 'section|hero|pattern|block|paragraph|image|content|draft|proposal|page|post|layout|footer|header'
            . '|recent posts'
            . ')\b/i',
            $positive_message,
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

    /**
     * Short corrections after an edit turn (or preservation follow-ups) inherit
     * the content-edit budget when a prior user message was an edit request.
     */
    private function message_inherits_content_edit(string $message): bool {
        if ((bool) preg_match('/\b(original|preserve|keep|minimal|modified|change as little)\b/i', $message)) {
            return true;
        }

        // "Can you fix?", "put the breaks back", "restore paragraph spacing".
        return (bool) preg_match(
            '/\b('
            . 'fix|restore|correct|repair|revise|update|change|rewrite|undo|'
            . 'put\s+back|bring\s+back|can\s+you\s+fix|please\s+fix|'
            . 'paragraphs?|line\s*breaks?|spacing|wording|copy|typos?|breaks?'
            . ')\b/i',
            $message,
        );
    }

    private function message_is_content_edit_request(string $message): bool {
        // Soft cleanup verbs ("clean up page 410") must share the edit path so
        // they get the longer wall and content/block proposal tools.
        $edit_verbs =
            'fix|format|reformat|update|revise|change|adjust|modify|edit|convert|promote|demote|restyle|resize|enlarge|shrink|'
            . 'increase|decrease|make|restore|correct|repair|clean\s*up|cleanup|tidy|polish|simplify';
        $edit_targets =
            'page|post|content|layout|formatting|paragraphs?|line\s*breaks?|spacing|wording|copy|typos?|'
            . 'blocks?|icons?|images?|buttons?|headings?|sections?|columns?|fonts?|text|size|width|height';

        if ((bool) preg_match('/\b(' . $edit_verbs . ')\b.*\b(' . $edit_targets . ')\b/i', $message)) {
            return true;
        }

        // Target-first: "paragraph breaks … fix", "page 410 cleanup".
        if ((bool) preg_match('/\b(' . $edit_targets . ')\b.*\b(' . $edit_verbs . ')\b/i', $message)) {
            return true;
        }

        // Explicit existing targets should receive edit discovery even when the
        // mutation wording is novel. Evidence, not this classifier, selects the
        // eventual proposal operation.
        if (
            (bool) preg_match('/\b(page|post)\s*#?\s*\d+\b/i', $message)
            && (bool) preg_match(
                '/\b(adjust|modify|edit|resize|enlarge|shrink|increase|decrease|bigger|smaller|larger|'
                . 'icon|image|button|block|style|size|width|height)\b/i',
                $message,
            )
        ) {
            return true;
        }

        // Reverse order with page/post ID: "page 408 documentation".
        return (bool) preg_match(
            '/\b(page|post)\s*#?\s*\d+\b.*\b(' . $edit_verbs . '|documentation|layout|format|formatting|style)\b/i',
            $message,
        );
    }
}
