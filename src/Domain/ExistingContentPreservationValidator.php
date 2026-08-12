<?php

/**
 * Optional strict content-preservation for redesigns.
 *
 * Off by default: theme-enhanced redesign may restructure and compress copy.
 * Enable only when the admin explicitly requests full preservation or a
 * filter/request flag opts in.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Database\MessageRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/** Blocks lossy rewrites only when strict content preservation is requested. */
final class ExistingContentPreservationValidator {
    public function validate_for_session(int $session_id, string $before, string $after): ?\WP_Error {
        if ($session_id <= 0 || '' === trim($before)) {
            return null;
        }

        $message = new MessageRepository()->latest_user_message($session_id);
        return $this->validate($message, $before, $after, $session_id);
    }

    public function validate(string $message, string $before, string $after, int $session_id = 0): ?\WP_Error {
        if (!$this->strict_preservation_enabled($message, $session_id)) {
            return null;
        }

        if ($this->explicit_content_reduction($message)) {
            return null;
        }

        /**
         * Filter the strict preservation policy (only consulted when strict mode is on).
         *
         * @param array<string, mixed> $policy
         * @param int                  $session_id
         * @param string               $message
         */
        $policy = apply_filters(
            'awpt_presentation_preservation_policy',
            [
                'minimum_token_recall' => 0.9,
                'minimum_text_length_ratio' => 0.85,
                'preserve_links' => true,
                'preserve_numeric_tokens' => true,
            ],
            $session_id,
            $message,
        );
        $policy = is_array($policy) ? $policy : [];
        $before_text = $this->visible_text($before);
        $after_text = $this->visible_text($after);
        $before_tokens = $this->significant_tokens($before_text);
        $after_tokens = $this->significant_tokens($after_text);
        $recall = $this->multiset_recall($before_tokens, $after_tokens);
        $length_ratio = max(1, mb_strlen($before_text, 'UTF-8')) > 0
            ? mb_strlen($after_text, 'UTF-8') / max(1, mb_strlen($before_text, 'UTF-8'))
            : 1.0;
        $missing_links = true === ($policy['preserve_links'] ?? true)
            ? array_values(array_diff($this->links($before), $this->links($after)))
            : [];
        $missing_numbers = true === ($policy['preserve_numeric_tokens'] ?? true)
            ? array_values(array_diff($this->numeric_tokens($before_text), $this->numeric_tokens($after_text)))
            : [];
        $missing_short_fragments = $this->missing_short_fragments($before, $after_text);
        $minimum_recall = max(0.0, min(1.0, (float) ($policy['minimum_token_recall'] ?? 0.9)));
        $minimum_length = max(0.0, min(1.0, (float) ($policy['minimum_text_length_ratio'] ?? 0.85)));

        if (
            $recall >= $minimum_recall
            && $length_ratio >= $minimum_length
            && [] === $missing_links
            && [] === $missing_numbers
            && [] === $missing_short_fragments
        ) {
            return null;
        }

        return new \WP_Error(
            'awpt_presentation_content_loss',
            __(
                'Strict content preservation was requested, but the proposal removes or rewrites substantive existing content. Preserve the page copy, links, numbers, and legal references, or drop the strict-preservation requirement.',
                'agent-wordpress-terminal',
            ),
            [
                'status' => 409,
                'token_recall' => round($recall, 3),
                'minimum_token_recall' => $minimum_recall,
                'text_length_ratio' => round($length_ratio, 3),
                'minimum_text_length_ratio' => $minimum_length,
                'missing_links' => array_slice($missing_links, 0, 20),
                'missing_numeric_tokens' => array_slice($missing_numbers, 0, 30),
                'missing_short_fragments' => array_slice($missing_short_fragments, 0, 20),
                'missing_excerpt' => $this->missing_excerpt($before_text, $after_tokens),
                'recommended_next_tools' => [array_filter([
                    'tool' => 'awpt/propose-block-batch-update',
                    'reason' => __(
                        'Use verified block paths for conservation-oriented changes when strict preservation is required.',
                        'agent-wordpress-terminal',
                    ),
                    'input' => $this->recommendation_post_input($session_id),
                ])],
            ],
        );
    }

    /**
     * Strict mode is opt-in: explicit admin language, payload flag via filter, or filter force-on.
     */
    private function strict_preservation_enabled(string $message, int $session_id): bool {
        /**
         * Force strict content preservation for a session (default false).
         *
         * @param bool   $enabled
         * @param int    $session_id
         * @param string $message
         */
        $forced = (bool) apply_filters('awpt_strict_content_preservation', false, $session_id, $message);
        if ($forced) {
            return true;
        }

        return (bool) preg_match(
            '/\b('
            . 'preserve\s+all|keep\s+all|do\s+not\s+(?:remove|drop|delete|omit)|'
            . 'without\s+(?:removing|dropping|deleting|losing)|'
            . 'strict\s+(?:content\s+)?preservation|must\s+not\s+lose'
            . ')\b/i',
            $message,
        );
    }

    /**
     * @return array{post_id: int}|null
     */
    private function recommendation_post_input(int $session_id): ?array {
        if ($session_id <= 0) {
            return null;
        }

        $summary = new SessionRepository()->get_summary($session_id);
        $post_id = ArrayKey::as_int($summary['focus_post_id'] ?? 0);

        return $post_id > 0 ? ['post_id' => $post_id] : null;
    }

    private function explicit_content_reduction(string $message): bool {
        return (bool) preg_match(
            '/\b(shorten|condense|summari[sz]e|abridge|trim\s+(?:the\s+)?copy|remove|delete|drop|omit|rewrite\s+(?:the\s+)?copy)\b/i',
            $message,
        );
    }

    private function visible_text(string $content): string {
        $without_comments = preg_replace('/<!--.*?-->/s', ' ', $content);
        $text = html_entity_decode(
            wp_strip_all_tags(is_string($without_comments) ? $without_comments : $content),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> */
    private function significant_tokens(string $text): array {
        $matches = [];
        preg_match_all('/[\p{L}\p{N}]+(?:[.\/-][\p{L}\p{N}]+)*/u', mb_strtolower($text, 'UTF-8'), $matches);
        $stopwords = array_fill_keys([
            'the',
            'and',
            'for',
            'that',
            'with',
            'this',
            'from',
            'are',
            'was',
            'were',
            'have',
            'has',
            'had',
            'but',
            'not',
            'you',
            'your',
            'all',
        ], true);

        return array_values(array_filter(
            $matches[0] ?? [],
            static fn(string $word): bool => mb_strlen($word, 'UTF-8') >= 3 && !array_key_exists($word, $stopwords),
        ));
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    private function multiset_recall(array $before, array $after): float {
        if ([] === $before) {
            return 1.0;
        }

        $available = array_count_values($after);
        $matched = 0;

        foreach ($before as $token) {
            if (($available[$token] ?? 0) <= 0) {
                continue;
            }

            ++$matched;
            --$available[$token];
        }

        return $matched / count($before);
    }

    /** @return list<string> */
    private function links(string $content): array {
        $matches = [];
        preg_match_all('/<a\b[^>]*\bhref=(?:"([^"]+)"|\'([^\']+)\')/i', $content, $matches);
        $links = [];

        foreach (array_keys($matches[0] ?? []) as $index) {
            $url = html_entity_decode($matches[1][$index] ?? '' ?: $matches[2][$index] ?? '');

            if ('' !== $url) {
                $links[] = $url;
            }
        }

        return array_values(array_unique($links));
    }

    /** @return list<string> */
    private function numeric_tokens(string $text): array {
        $matches = [];
        preg_match_all('/\b\d+(?:[.\/-]\d+)*(?:\([a-z0-9]+\))?\b/i', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /** @return list<string> */
    private function missing_short_fragments(string $before, string $after_text): array {
        $fragments = [];
        $this->collect_leaf_fragments(parse_blocks($before), $fragments);
        $after_sequence = implode(' ', $this->significant_tokens($after_text));
        $missing = [];

        foreach (array_values(array_unique($fragments)) as $fragment) {
            $tokens = $this->significant_tokens($fragment);

            if (count($tokens) < 2 || count($tokens) > 12) {
                continue;
            }

            if (!str_contains($after_sequence, implode(' ', $tokens))) {
                $missing[] = $fragment;
            }
        }

        return $missing;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param list<string>                     $fragments
     */
    private function collect_leaf_fragments(array $blocks, array &$fragments): void {
        foreach ($blocks as $block) {
            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

            if ([] !== $inner) {
                /** @var array<int, array<string, mixed>> $inner_blocks */
                $inner_blocks = array_values(array_filter($inner, 'is_array'));
                $this->collect_leaf_fragments($inner_blocks, $fragments);
                continue;
            }

            $text = $this->visible_text((string) ($block['innerHTML'] ?? ''));

            if ('' !== $text) {
                $fragments[] = $text;
            }
        }
    }

    /** @param list<string> $after_tokens */
    private function missing_excerpt(string $before_text, array $after_tokens): string {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $before_text) ?: [];
        $after_counts = array_count_values($after_tokens);

        foreach ($sentences as $sentence) {
            $tokens = $this->significant_tokens($sentence);

            if (count($tokens) < 4) {
                continue;
            }

            $present = count(array_filter($tokens, static fn(string $word): bool => ($after_counts[$word] ?? 0) > 0));

            if (($present / count($tokens)) < 0.6) {
                return mb_substr(trim($sentence), 0, 300, 'UTF-8');
            }
        }

        return '';
    }
}
