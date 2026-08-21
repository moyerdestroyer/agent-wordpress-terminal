<?php

/**
 * Safe, path-addressed replacement of saved leaf-block markup.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/** Replaces complete saved HTML while preserving the block delimiter and attributes. */
final class BlockInnerHtmlUpdater {
    public const MAX_INNER_HTML_CHARS = 20_000;

    /** @var list<string> */
    private const SUPPORTED_BLOCKS = [
        'core/paragraph',
        'core/list',
        'core/image',
        'core/table',
    ];

    /**
     * @param array<string, mixed> $block
     * @return array{editable: bool, inner_html: string, truncated: bool, reason: string}
     */
    public function inspect(array $block): array {
        $inner_html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
        $too_large = mb_strlen($inner_html, 'UTF-8') > self::MAX_INNER_HTML_CHARS;
        $reason = $this->ineligible_reason($block, $inner_html);

        if ($too_large) {
            $reason = __('The block saved HTML is too large for a surgical replacement.', 'agent-wordpress-terminal');
        }

        return [
            'editable' => '' === $reason && !$too_large,
            'inner_html' => $too_large ? mb_substr($inner_html, 0, self::MAX_INNER_HTML_CHARS, 'UTF-8') : $inner_html,
            'truncated' => $too_large,
            'reason' => $reason,
        ];
    }

    /**
     * Validate and sanitize replacement markup for a specific parsed block.
     *
     * @param array<string, mixed> $block
     */
    public function validate(array $block, string $replacement): string|\WP_Error {
        $current = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';
        $reason = $this->ineligible_reason($block, $current);

        if ('' !== $reason) {
            return $this->error($reason, $block);
        }

        if (mb_strlen($replacement, 'UTF-8') > self::MAX_INNER_HTML_CHARS) {
            return new \WP_Error(
                'awpt_block_inner_html_too_large',
                __('Replacement block HTML is too large.', 'agent-wordpress-terminal'),
                ['status' => 400, 'max_chars' => self::MAX_INNER_HTML_CHARS],
            );
        }

        $replacement = $this->strip_wrapping_block_comments($replacement);

        if (preg_match('/<!--\s*\/?wp:/i', $replacement)) {
            return new \WP_Error(
                'awpt_block_inner_html_contains_blocks',
                __('Replacement saved HTML must not contain Gutenberg block delimiters.', 'agent-wordpress-terminal'),
                [
                    'status' => 400,
                    'expected_example' => $current,
                    'got' => $replacement,
                ],
            );
        }

        if (preg_match('/<(?:script|style|iframe|object|embed|form)\b/i', $replacement)) {
            return new \WP_Error(
                'awpt_block_inner_html_unsafe',
                __('Replacement saved HTML contains an unsafe element.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $sanitized = wp_kses_post($replacement);
        $current_tag = $this->root_tag($current);
        $replacement_tag = $this->root_tag($sanitized);

        if (null === $current_tag || null === $replacement_tag || $current_tag !== $replacement_tag) {
            $repaired = $this->wrap_to_match($sanitized, $current_tag, $replacement_tag);

            if (null !== $repaired) {
                return $repaired;
            }

            return new \WP_Error(
                'awpt_block_inner_html_wrapper_mismatch',
                __('Replacement saved HTML must keep the existing outer wrapper element.', 'agent-wordpress-terminal'),
                [
                    'status' => 400,
                    'expected_wrapper' => $current_tag ?? '',
                    'received_wrapper' => $replacement_tag ?? '',
                    'expected_example' => $current,
                    'recovery' => sprintf(
                        /* translators: %s: HTML tag name */
                        __(
                            'Retry with html wrapped in <%1$s>...</%1$s> matching get-block innerHTML.',
                            'agent-wordpress-terminal',
                        ),
                        $current_tag ?? 'p',
                    ),
                ],
            );
        }

        return $sanitized;
    }

    /**
     * When the model sends a fragment or the wrong outer tag, wrap/re-wrap to the live leaf.
     * Does not invent structure for sibling multi-root markup.
     */
    private function wrap_to_match(string $sanitized, ?string $current_tag, ?string $replacement_tag): ?string {
        if (null === $current_tag || !preg_match('/^[a-z][a-z0-9-]*$/i', $current_tag)) {
            return null;
        }

        $inline = ['strong', 'em', 'b', 'i', 'a', 'span', 'code', 'br', 'img', 'small', 'sub', 'sup'];

        // Sibling roots (e.g. <p></p><p></p>) must stay rejected.
        if (
            null === $replacement_tag
            && 1 === preg_match('/^<([a-z][a-z0-9-]*)\b[^>]*>[\s\S]*<\/\1>\s*<([a-z][a-z0-9-]*)\b/i', $sanitized)
        ) {
            return null;
        }

        $inner = $sanitized;

        if (null === $replacement_tag) {
            // Fragment / bare text / inline-only markup.
        } elseif (in_array($replacement_tag, $inline, true)) {
            // Keep as-is for wrapping into paragraph/list-item shells.
        } elseif ($replacement_tag !== $current_tag) {
            $matches = [];

            if (1 === preg_match('/^<[^>]+>(.*)<\/[^>]+>\s*$/s', $sanitized, $matches)) {
                $inner = $matches[1] ?? $sanitized;
            }
        } else {
            return null;
        }

        $wrapped = wp_kses_post('<' . $current_tag . '>' . $inner . '</' . $current_tag . '>');

        return $this->root_tag($wrapped) === $current_tag ? $wrapped : null;
    }

    /** Replace markup after the complete batch has been validated. */
    public function apply(string $content, string $path, string $replacement): string|\WP_Error {
        $blocks = ArrayKey::list_of_maps(parse_blocks($content));
        $segments = $this->segments($path);

        if ([] === $segments) {
            return new \WP_Error(
                'awpt_invalid_block_path',
                __('Block path must be a dotted numeric path such as 0 or 2.1.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $updated = $this->replace_at($blocks, $segments, $replacement);

        return true === $updated ? new BlockTreePathHelpers()->serialize($blocks) : $updated;
    }

    /** @param array<string, mixed> $block */
    private function ineligible_reason(array $block, string $inner_html): string {
        $name = (string) ($block['blockName'] ?? '');

        if (!in_array($name, self::SUPPORTED_BLOCKS, true)) {
            return sprintf(__('Block %s does not support saved HTML replacement.', 'agent-wordpress-terminal'), $name);
        }

        if ([] !== ArrayKey::list_of_maps($block['innerBlocks'] ?? null)) {
            return __(
                'Blocks with nested inner blocks must be edited through their child paths.',
                'agent-wordpress-terminal',
            );
        }

        if (null === $this->root_tag($inner_html)) {
            return __('The block does not have one replaceable saved HTML wrapper.', 'agent-wordpress-terminal');
        }

        return '';
    }

    /** @param array<string, mixed> $block */
    private function error(string $message, array $block): \WP_Error {
        return new \WP_Error('awpt_block_inner_html_not_editable', $message, [
            'status' => 409,
            'block_name' => (string) ($block['blockName'] ?? ''),
            'inner_count' => count(ArrayKey::list_of_maps($block['innerBlocks'] ?? null)),
            'recommended_next_tools' => ['awpt/get-block', 'awpt/propose-content-update'],
        ]);
    }

    /** Remove a single wrapping <!-- wp:... --> / <!-- /wp:... --> pair when present. */
    public function strip_wrapping_block_comments(string $markup): string {
        $stripped = preg_replace('/^\s*<!--\s*wp:[^>]*-->\s*/i', '', $markup);
        $stripped = is_string($stripped) ? $stripped : $markup;
        $stripped = preg_replace('/\s*<!--\s*\/wp:[^>]*-->\s*$/i', '', $stripped);

        return is_string($stripped) ? $stripped : $markup;
    }

    private function root_tag(string $markup): ?string {
        $matches = [];
        $match_count = preg_match('/^\s*<([a-z][a-z0-9-]*)\b[^>]*>.*<\/\1>\s*$/is', $markup, $matches);

        if (1 !== $match_count) {
            return null;
        }

        $tag = strtolower($matches[1] ?? '');

        // The anchored wrapper expression alone would also accept two sibling
        // wrappers with the same tag by spanning from the first open to the
        // final close. Saved block HTML must have exactly one outer wrapper.
        if (
            1 === preg_match('/<\/\s*' . preg_quote($tag, '/') . '\s*>\s*<' . preg_quote($tag, '/') . '\b/i', $markup)
        ) {
            return null;
        }

        return $tag;
    }

    /** @return list<int> */
    private function segments(string $path): array {
        if (!preg_match('/^\d+(?:\.\d+)*$/', $path)) {
            return [];
        }

        return array_map('intval', explode('.', $path));
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     * @param list<int>                               $segments
     */
    private function replace_at(array &$blocks, array $segments, string $replacement): true|\WP_Error {
        $target = array_shift($segments);
        $visible_index = 0;

        foreach ($blocks as &$block) {
            if (!BlockTree::has_block_name($block)) {
                continue;
            }

            if ($visible_index !== $target) {
                ++$visible_index;
                continue;
            }

            if ([] !== $segments) {
                $inner_blocks = ArrayKey::list_of_maps($block['innerBlocks'] ?? null);
                $updated = $this->replace_at($inner_blocks, $segments, $replacement);

                if (true === $updated) {
                    $block['innerBlocks'] = $inner_blocks;
                }

                return $updated;
            }

            $block['innerHTML'] = $replacement;
            $block['innerContent'] = [$replacement];

            return true;
        }

        return new \WP_Error('awpt_block_not_found', __('Block path was not found.', 'agent-wordpress-terminal'), [
            'status' => 404,
        ]);
    }
}
