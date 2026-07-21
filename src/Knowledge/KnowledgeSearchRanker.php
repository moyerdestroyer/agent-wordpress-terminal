<?php

/**
 * Knowledge search ranking helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Scores and formats indexed Knowledge search rows.
 */
final class KnowledgeSearchRanker {
    /**
     * Common chat/instruction words that match too much site content when used as
     * Knowledge query tokens (e.g. "write a post about X" should not rank on "post").
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'about',
        'after',
        'again',
        'also',
        'and',
        'any',
        'are',
        'because',
        'been',
        'before',
        'being',
        'both',
        'but',
        'can',
        'could',
        'create',
        'draft',
        'each',
        'from',
        'have',
        'here',
        'how',
        'into',
        'just',
        'like',
        'make',
        'more',
        'most',
        'need',
        'only',
        'other',
        'our',
        'out',
        'over',
        'page',
        'pages',
        'please',
        'post',
        'posts',
        'should',
        'some',
        'such',
        'than',
        'that',
        'the',
        'their',
        'them',
        'then',
        'there',
        'these',
        'they',
        'this',
        'those',
        'through',
        'too',
        'under',
        'very',
        'want',
        'was',
        'were',
        'what',
        'when',
        'where',
        'which',
        'while',
        'who',
        'will',
        'with',
        'word',
        'words',
        'would',
        'write',
        'writing',
        'your',
        'ish',
    ];

    /**
     * @return list<string>
     */
    public function tokens(string $query): array {
        $raw = preg_split('/[^\pL\pN_]+/u', strtolower($query));
        $tokens = [];
        $stopwords = array_fill_keys(self::STOPWORDS, true);

        foreach (is_array($raw) ? $raw : [] as $token) {
            if (mb_strlen($token, 'UTF-8') < 3) {
                continue;
            }

            if (isset($stopwords[$token])) {
                continue;
            }

            // Pure numbers / "1000ish"-style length cues are not useful Knowledge keys.
            if (preg_match('/^\d+([a-z]{0,4})?$/u', $token)) {
                continue;
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param array<string, mixed> $row Search row.
     * @param list<string>         $tokens Query tokens.
     * @return array<string, mixed>|null
     */
    public function format_result(array $row, array $tokens): ?array {
        $score = $this->score((string) ($row['chunk_text'] ?? ''), (string) ($row['label'] ?? ''), $tokens);

        if ($score <= 0) {
            return null;
        }

        $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'source_kind' => (string) $row['source_kind'],
            'source_id' => (string) $row['source_id'],
            'source_post_id' => null !== $row['source_post_id'] ? (int) $row['source_post_id'] : null,
            'label' => (string) $row['label'],
            'uri' => (string) $row['uri'],
            'excerpt' => $this->excerpt((string) $row['chunk_text'], $tokens),
            'score' => $score,
            'metadata' => is_array($metadata) ? $metadata : [],
        ];
    }

    /**
     * @param list<string> $tokens Query tokens.
     */
    private function score(string $text, string $label, array $tokens): int {
        $haystack = strtolower($label . ' ' . $text);
        $score = 0;

        foreach ($tokens as $token) {
            $score += substr_count($haystack, $token);

            if (str_contains(strtolower($label), $token)) {
                $score += 3;
            }
        }

        return $score;
    }

    /**
     * @param list<string> $tokens Query tokens.
     */
    private function excerpt(string $text, array $tokens): string {
        $stripped = preg_replace('/\s+/', ' ', wp_strip_all_tags($text));
        $plain = trim(is_string($stripped) ? $stripped : $text);
        $lower = strtolower($plain);
        $position = $this->first_token_position($lower, $tokens);
        $excerpt = mb_substr($plain, $position, 420, 'UTF-8');

        if ($position > 0) {
            $excerpt = '...' . $excerpt;
        }

        if (mb_strlen($plain, 'UTF-8') > ($position + 420)) {
            $excerpt .= '...';
        }

        return $excerpt;
    }

    /**
     * @param list<string> $tokens Query tokens.
     */
    private function first_token_position(string $text, array $tokens): int {
        foreach ($tokens as $token) {
            if (!str_contains($text, $token)) {
                continue;
            }

            $found = strpos($text, $token);

            if (false !== $found) {
                return max(0, $found - 140);
            }
        }

        return 0;
    }
}
