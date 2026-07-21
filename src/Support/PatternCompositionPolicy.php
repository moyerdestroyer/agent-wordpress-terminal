<?php

/**
 * Resolves pattern_mode and prevents raw-pattern + filled-layout twins.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Keeps propose-new-post pattern composition fail-closed:
 * - adapted: agent owns the full document; never prepend raw pattern markup
 * - prepend: server owns the pattern; agent may only supply a short body tail
 */
final class PatternCompositionPolicy {
    public const MODE_PREPEND = 'prepend';
    public const MODE_ADAPTED = 'adapted';

    /** Soft cap for a legitimate prepend tail (body after an unchanged pattern). */
    private const PREPEND_TAIL_MAX_CHARS = 1_200;

    /**
     * Resolve the effective pattern mode.
     *
     * When a pattern is named and the caller omits mode, default to adapted so a
     * filled post_content is not silently concatenated with the raw pattern.
     */
    public function resolve_mode(string $requested_mode, string $pattern_name, string $existing_mode = ''): string {
        $requested = sanitize_key($requested_mode);
        $existing = sanitize_key($existing_mode);

        if (in_array($requested, [self::MODE_PREPEND, self::MODE_ADAPTED], true)) {
            return $requested;
        }

        if (in_array($existing, [self::MODE_PREPEND, self::MODE_ADAPTED], true)) {
            return $existing;
        }

        if ('' !== trim($pattern_name)) {
            return self::MODE_ADAPTED;
        }

        return self::MODE_PREPEND;
    }

    /**
     * Whether the server should inject the registered pattern before post_content.
     */
    public function should_prepend(string $mode, string $pattern_content, string $post_content): bool {
        if (self::MODE_PREPEND !== $mode) {
            return false;
        }

        $pattern = trim($pattern_content);
        $body = ltrim($post_content);

        if ('' === $pattern) {
            return false;
        }

        return !str_starts_with($body, $pattern);
    }

    /**
     * Reject prepend when post_content already looks like a full layout.
     */
    public function conflict_if_prepend_would_duplicate(
        string $mode,
        string $pattern_content,
        string $post_content,
    ): ?\WP_Error {
        if (self::MODE_PREPEND !== $mode || !$this->should_prepend($mode, $pattern_content, $post_content)) {
            return null;
        }

        if (!$this->looks_like_full_composition($post_content, $pattern_content)) {
            return null;
        }

        return new \WP_Error(
            'awpt_pattern_mode_mismatch',
            __(
                'post_content already looks like a full layout. Use pattern_mode "adapted" with the complete customized composition (pattern_name is provenance only), or pass a short body-only tail under pattern_mode "prepend". Do not stack a filled layout under an unchanged pattern.',
                'agent-wordpress-terminal',
            ),
            [
                'status' => 400,
                'recovery' => __(
                    'Resubmit one awpt/propose-new-post call with pattern_mode adapted and a single full composition in post_content. Call awpt/read-pattern first when adapting.',
                    'agent-wordpress-terminal',
                ),
                'recommended_next_tools' => [
                    ['tool' => 'awpt/read-pattern', 'input' => []],
                    ['tool' => 'awpt/propose-new-post', 'input' => ['pattern_mode' => self::MODE_ADAPTED]],
                ],
            ],
        );
    }

    /**
     * Detect the classic twin after composition: raw pattern still present as a
     * contiguous prefix/substring plus a separate multi-block layout.
     */
    public function conflict_if_raw_pattern_twin(string $pattern_content, string $composed_content): ?\WP_Error {
        if (null === $this->raw_pattern_twin_remainder($pattern_content, $composed_content)) {
            return null;
        }

        return new \WP_Error(
            'awpt_pattern_content_twin',
            __(
                'The draft still contains the unchanged pattern markup plus a separate filled layout. Keep a single composition: use pattern_mode adapted with customized markup only, or prepend with a short body tail and no second layout.',
                'agent-wordpress-terminal',
            ),
            [
                'status' => 400,
                'recovery' => __(
                    'Remove either the raw pattern block or the duplicated filled section, then resubmit one propose-new-post with a single document.',
                    'agent-wordpress-terminal',
                ),
            ],
        );
    }

    /**
     * For adapted mode, drop the first raw pattern occurrence when a filled
     * remainder remains so revisions that re-paste registered markup still stage.
     *
     * @return string|null Cleaned composition, or null when no twin repair applies.
     */
    public function strip_raw_pattern_twin(string $pattern_content, string $composed_content): ?string {
        return $this->raw_pattern_twin_remainder($pattern_content, $composed_content);
    }

    /**
     * @return string|null Remainder after removing the raw pattern twin, else null.
     */
    private function raw_pattern_twin_remainder(string $pattern_content, string $composed_content): ?string {
        $pattern = trim($pattern_content);
        $composed = trim($composed_content);

        if ('' === $pattern || '' === $composed || $pattern === $composed) {
            return null;
        }

        $position = strpos($composed, $pattern);

        if (false === $position) {
            return null;
        }

        $remainder = trim(substr($composed, 0, $position) . substr($composed, $position + strlen($pattern)));

        if ('' === $remainder || !$this->looks_like_full_composition($remainder, $pattern)) {
            return null;
        }

        return $remainder;
    }

    /**
     * True when agent content is a layout document rather than a short body tail.
     */
    public function looks_like_full_composition(string $content, string $pattern_content = ''): bool {
        $body = trim($content);

        if ('' === $body) {
            return false;
        }

        $named_blocks = $this->top_level_block_names($body);

        if ([] === $named_blocks) {
            // Free text / classic content without block comments — treat long
            // tails as full documents so prepend cannot hide a second article.
            return mb_strlen($body, 'UTF-8') > self::PREPEND_TAIL_MAX_CHARS;
        }

        foreach ($named_blocks as $name) {
            if ($this->is_layout_block($name)) {
                return true;
            }
        }

        // Three or more top-level text blocks is a document, not a short tail.
        if (count($named_blocks) >= 3) {
            return true;
        }

        // One or two simple text blocks are a legitimate prepend tail unless huge.
        if (mb_strlen($body, 'UTF-8') > self::PREPEND_TAIL_MAX_CHARS) {
            return true;
        }

        $pattern_names = $this->top_level_block_names($pattern_content);

        if (count($pattern_names) >= 2 && $this->shares_block_skeleton($named_blocks, $pattern_names)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function top_level_block_names(string $content): array {
        $blocks = parse_blocks($content);
        $names = [];

        foreach ($blocks as $block) {
            $name = ArrayKey::as_string($block['blockName'] ?? null);

            if (null === $name || '' === $name) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    private function is_layout_block(string $block_name): bool {
        return in_array(
            $block_name,
            [
                'core/group',
                'core/cover',
                'core/columns',
                'core/media-text',
                'core/gallery',
                'core/query',
                'core/template-part',
            ],
            true,
        );
    }

    /**
     * @param list<string> $candidate
     * @param list<string> $pattern
     */
    private function shares_block_skeleton(array $candidate, array $pattern): bool {
        if ([] === $candidate || [] === $pattern) {
            return false;
        }

        $length = min(count($candidate), count($pattern), 4);
        $candidate_slice = array_slice($candidate, 0, $length);
        $pattern_slice = array_slice($pattern, 0, $length);

        return $candidate_slice === $pattern_slice;
    }
}
