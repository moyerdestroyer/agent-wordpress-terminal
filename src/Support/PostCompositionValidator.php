<?php

/**
 * Validates generated post compositions before a staging draft is written.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/** Prevent malformed blocks and missing user-requested assets from reaching Preview. */
final class PostCompositionValidator {
    public function validate_syntax(string $content): ?\WP_Error {
        $structure_error = $this->validate_block_delimiters($content);

        if (null !== $structure_error) {
            return $structure_error;
        }

        if (preg_match('/<p\b[^>]*>(?:(?!<\/p>).)*<(?:h[1-6]|div|figure|section|p)\b/is', $content)) {
            return $this->error('awpt_invalid_block_html', __(
                'Generated content contains block-level HTML nested inside a paragraph.',
                'agent-wordpress-terminal',
            ));
        }

        return null;
    }

    /**
     * Return every independently detectable issue so one agent correction can
     * address the full proposal instead of discovering failures serially.
     *
     * @param list<int>    $required_attachment_ids
     * @param list<string> $required_links
     * @param array{pattern_name?: string, minimum_library_images?: int, minimum_visuals?: int, featured_image_id?: int} $requirements
     * @return list<array{code: string, message: string}>
     */
    public function diagnose(
        string $content,
        array $required_attachment_ids = [],
        array $required_links = [],
        string $required_pattern_prefix = '',
        array $requirements = [],
    ): array {
        $base_error = $this->validate($content);

        if (null !== $base_error) {
            return [$this->error_summary($base_error)];
        }

        $checks = [];

        if ('' !== $required_pattern_prefix) {
            $checks[] = $this->validate($content, [], [], $required_pattern_prefix, [
                'pattern_name' => $requirements['pattern_name'] ?? '',
            ]);
        }

        foreach ($required_attachment_ids as $attachment_id) {
            $checks[] = $this->validate($content, [$attachment_id]);
        }

        if ((int) ($requirements['minimum_library_images'] ?? 0) > 0) {
            $checks[] = $this->validate($content, [], [], '', [
                'minimum_library_images' => (int) $requirements['minimum_library_images'],
                'featured_image_id' => (int) ($requirements['featured_image_id'] ?? 0),
            ]);
        }

        if ((int) ($requirements['minimum_visuals'] ?? 0) > 0) {
            $checks[] = $this->validate($content, [], [], '', [
                'minimum_visuals' => (int) $requirements['minimum_visuals'],
                'featured_image_id' => (int) ($requirements['featured_image_id'] ?? 0),
            ]);
        }

        foreach ($required_links as $required_link) {
            $checks[] = $this->validate($content, [], [$required_link]);
        }

        $issues = [];

        foreach ($checks as $error) {
            if (!$error instanceof \WP_Error) {
                continue;
            }

            $summary = $this->error_summary($error);
            $issues[$summary['code'] . ':' . $summary['message']] = $summary;
        }

        return array_values($issues);
    }

    /**
     * @param list<int>    $required_attachment_ids
     * @param list<string> $required_links
     * @param array{pattern_name?: string, minimum_library_images?: int, minimum_visuals?: int, featured_image_id?: int} $requirements
     */
    public function validate(
        string $content,
        array $required_attachment_ids = [],
        array $required_links = [],
        string $required_pattern_prefix = '',
        array $requirements = [],
    ): ?\WP_Error {
        $pattern_name = $requirements['pattern_name'] ?? '';
        $required_minimum_library_images = max(0, (int) ($requirements['minimum_library_images'] ?? 0));
        $required_minimum_visuals = max(0, (int) ($requirements['minimum_visuals'] ?? 0));
        $featured_image_id = max(0, (int) ($requirements['featured_image_id'] ?? 0));
        $structure_error = $this->validate_syntax($content);

        if (null !== $structure_error) {
            return $structure_error;
        }

        $placeholder_error = $this->validate_placeholder_content($content);

        if (null !== $placeholder_error) {
            return $placeholder_error;
        }

        if ('' !== $required_pattern_prefix && !str_starts_with($pattern_name, $required_pattern_prefix)) {
            return $this->error('awpt_required_pattern_missing', sprintf(
                /* translators: %s: required pattern namespace. */
                __('The proposal must use a verified pattern from the %s namespace.', 'agent-wordpress-terminal'),
                $required_pattern_prefix,
            ));
        }

        $blocks = parse_blocks($content);
        $static_markup_error = $this->validate_static_block_markup($blocks);

        if (null !== $static_markup_error) {
            return $static_markup_error;
        }

        foreach ($required_attachment_ids as $attachment_id) {
            if (!$this->blocks_include_attachment($blocks, $attachment_id)) {
                return $this->error('awpt_required_inline_media_missing', sprintf(
                    /* translators: %d: Media Library attachment ID. */
                    __(
                        'Attachment #%d must appear in an inline Image or Cover block, not only as the featured image.',
                        'agent-wordpress-terminal',
                    ),
                    $attachment_id,
                ));
            }
        }

        if ($required_minimum_library_images > 0) {
            $inline_ids = $this->inline_image_attachment_ids($blocks);

            if ($featured_image_id > 0 && wp_attachment_is_image($featured_image_id)) {
                $inline_ids[] = $featured_image_id;
                $inline_ids = array_values(array_unique($inline_ids));
            }

            if (count($inline_ids) < $required_minimum_library_images) {
                return $this->error('awpt_required_media_count_missing', sprintf(
                    /* translators: 1: required image count, 2: detected image count. */
                    __(
                        'The proposal must use at least %1$d distinct Media Library images inline or as the featured image; %2$d were detected.',
                        'agent-wordpress-terminal',
                    ),
                    $required_minimum_library_images,
                    count($inline_ids),
                ));
            }
        }

        if ($required_minimum_visuals > 0) {
            $visual_count = $this->visual_placement_count($blocks);

            if ($featured_image_id > 0 && wp_attachment_is_image($featured_image_id)) {
                ++$visual_count;
            }

            if ($visual_count < $required_minimum_visuals) {
                return $this->error('awpt_required_visual_count_missing', sprintf(
                    /* translators: 1: required visual count, 2: detected visual count. */
                    __(
                        'The proposal must include at least %1$d visible image, cover, icon, or featured-image placements; %2$d were detected.',
                        'agent-wordpress-terminal',
                    ),
                    $required_minimum_visuals,
                    $visual_count,
                ));
            }
        }

        foreach ($required_links as $required_link) {
            if (!$this->content_has_link($content, $required_link)) {
                return $this->error('awpt_required_link_missing', sprintf(
                    /* translators: %s: required destination URL. */
                    __('The proposal is missing the requested link: %s', 'agent-wordpress-terminal'),
                    $required_link,
                ));
            }
        }

        return null;
    }

    private function validate_placeholder_content(string $content): ?\WP_Error {
        $visible_content = preg_replace('/<!--.*?-->/s', ' ', $content);
        $visible_text = html_entity_decode(wp_strip_all_tags(
            is_string($visible_content) ? $visible_content : $content,
        ));
        $placeholder_phrases = [
            'A short heading to introduce or highlight a key concept.',
            'These sections can be alternated left to right to tell a visually compelling story',
            'Everything up to this point should help people understand your agency or project',
            'The first paragraph might be larger, and provide a summary of the content that follows',
            'Column content should be related to each other in some way',
        ];
        $detected = [];

        foreach ($placeholder_phrases as $phrase) {
            if (false !== stripos($visible_text, $phrase)) {
                $detected[] = $phrase;
            }
        }

        if (preg_match(
            '/<a\b[^>]*class=(?:"[^"]*wp-block-button__link[^"]*"|\'[^\']*wp-block-button__link[^\']*\')[^>]*>\s*(?:optional\s+|secondary\s+)?call to action\s*<\/a>/i',
            $content,
        )) {
            $detected[] = 'Generic “Call to action” button label';
        }

        if ([] === $detected) {
            return null;
        }

        return new \WP_Error(
            'awpt_placeholder_content_remaining',
            sprintf(
                /* translators: %s: semicolon-separated placeholder excerpts. */
                __('Replace the remaining pattern placeholder content before staging: %s', 'agent-wordpress-terminal'),
                implode('; ', $detected),
            ),
            ['status' => 400, 'detected_placeholders' => $detected],
        );
    }

    /**
     * Catch common static-block save markup mismatches before Gutenberg marks
     * the blocks invalid in the editor.
     *
     * @param array<int|string, mixed> $blocks
     */
    private function validate_static_block_markup(array $blocks, string $parent_path = ''): ?\WP_Error {
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $path = '' === $parent_path ? (string) $index : $parent_path . '.' . $index;
            $name = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $inner_html = ltrim((string) ($block['innerHTML'] ?? ''));

            if (in_array($name, ['core/group', 'core/cover'], true)) {
                $expected_tag = strtolower((string) ($attrs['tagName'] ?? 'div'));
                $tag_match = [];

                if (preg_match('/^<([a-z][a-z0-9-]*)\b/i', $inner_html, $tag_match)) {
                    $actual_tag = strtolower($tag_match[1] ?? '');

                    if ($expected_tag !== $actual_tag) {
                        return new \WP_Error(
                            'awpt_block_wrapper_mismatch',
                            sprintf(
                                /* translators: 1: block path, 2: block name, 3: expected tag, 4: actual tag. */
                                __(
                                    'Block %1$s (%2$s) declares <%3$s> but saves <%4$s>. Make tagName and wrapper HTML agree.',
                                    'agent-wordpress-terminal',
                                ),
                                $path,
                                $name,
                                $expected_tag,
                                $actual_tag,
                            ),
                            [
                                'status' => 400,
                                'block_path' => $path,
                                'block_name' => $name,
                                'expected_tag' => $expected_tag,
                                'actual_tag' => $actual_tag,
                            ],
                        );
                    }
                }
            }

            if ('core/cover' === $name && (int) ($attrs['id'] ?? 0) > 0) {
                $image_class = 'wp-image-' . (int) $attrs['id'];

                if (!str_contains($inner_html, $image_class)) {
                    return $this->static_markup_error(
                        $path,
                        $name,
                        sprintf(
                            __('The Cover background image must include class %s.', 'agent-wordpress-terminal'),
                            $image_class,
                        ),
                    );
                }
            }

            if ('core/media-text' === $name && (int) ($attrs['mediaId'] ?? 0) > 0) {
                $size_class = 'size-' . sanitize_key((string) ($attrs['mediaSizeSlug'] ?? 'full'));

                if (!str_contains($inner_html, $size_class)) {
                    return $this->static_markup_error(
                        $path,
                        $name,
                        sprintf(
                            __(
                                'The Media & Text image must include its canonical %s class.',
                                'agent-wordpress-terminal',
                            ),
                            $size_class,
                        ),
                    );
                }
            }

            $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

            if ('core/list' === $name && str_contains($inner_html, '<li') && [] === $inner_blocks) {
                return $this->static_markup_error(
                    $path,
                    $name,
                    __('List items must use nested core/list-item block delimiters.', 'agent-wordpress-terminal'),
                );
            }

            $inner_error = $this->validate_static_block_markup($inner_blocks, $path);

            if (null !== $inner_error) {
                return $inner_error;
            }
        }

        return null;
    }

    private function static_markup_error(string $path, string $name, string $detail): \WP_Error {
        return new \WP_Error(
            'awpt_invalid_static_block_markup',
            sprintf(
                /* translators: 1: block path, 2: block name, 3: repair detail. */
                __('Block %1$s (%2$s) will be invalid in the editor. %3$s', 'agent-wordpress-terminal'),
                $path,
                $name,
                $detail,
            ),
            ['status' => 400, 'block_path' => $path, 'block_name' => $name, 'detail' => $detail],
        );
    }

    /** @param array<int|string, mixed> $blocks */
    private function visual_placement_count(array $blocks): int {
        $count = 0;

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $inner_html = (string) ($block['innerHTML'] ?? '');

            if ('core/image' === $name) {
                ++$count;
            } elseif ('core/cover' === $name) {
                if (
                    (int) ($attrs['id'] ?? $attrs['mediaId'] ?? 0) > 0
                    || '' !== (string) ($attrs['url'] ?? '')
                    || str_contains($inner_html, '<img')
                    || str_contains($inner_html, 'background-image')
                ) {
                    ++$count;
                }
            } elseif (str_contains($name, 'icon') || 'core/site-logo' === $name) {
                ++$count;
            } else {
                $count += substr_count(strtolower($inner_html), '<img');
            }

            $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            $count += $this->visual_placement_count($inner_blocks);
        }

        return $count;
    }

    /**
     * @param array<int|string, mixed> $blocks
     * @return list<int>
     */
    private function inline_image_attachment_ids(array $blocks): array {
        $ids = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $inner_html = (string) ($block['innerHTML'] ?? '');

            if (in_array($name, ['core/image', 'core/cover'], true)) {
                $id = (int) ($attrs['id'] ?? $attrs['mediaId'] ?? 0);

                $matches = [];

                if ($id <= 0 && preg_match('/\bwp-image-(\d+)\b/', $inner_html, $matches)) {
                    $id = (int) ($matches[1] ?? 0);
                }

                if ($id > 0 && wp_attachment_is_image($id)) {
                    $ids[$id] = true;
                }
            }

            $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

            foreach ($this->inline_image_attachment_ids($inner_blocks) as $id) {
                $ids[$id] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    private function validate_block_delimiters(string $content): ?\WP_Error {
        if (!str_contains($content, '<!-- wp:') && !str_contains($content, '<!-- /wp:')) {
            return null;
        }

        $matches = [];
        $match_count = preg_match_all(
            '/<!--\s*(\/?)wp:([a-z0-9_-]+(?:\/[a-z0-9_-]+)?)(\s+\{.*?\})?\s*(\/?)-->/is',
            $content,
            $matches,
            \PREG_SET_ORDER,
        );

        if (
            false === $match_count
            || $match_count !== (substr_count($content, '<!-- wp:') + substr_count($content, '<!-- /wp:'))
        ) {
            return $this->error('awpt_invalid_block_markup', __(
                'Generated Gutenberg block comments could not be parsed.',
                'agent-wordpress-terminal',
            ));
        }

        $stack = [];

        foreach ($matches as $match) {
            $closing = '/' === ($match[1] ?? '');
            $name = $match[2] ?? '';
            $attributes = trim($match[3] ?? '');
            $self_closing = '/' === ($match[4] ?? '');

            if ('' !== $attributes) {
                json_decode($attributes, true);

                if (JSON_ERROR_NONE !== json_last_error()) {
                    return $this->error('awpt_invalid_block_attributes', sprintf(
                        /* translators: %s: Gutenberg block name. */
                        __('The %s block contains invalid JSON attributes.', 'agent-wordpress-terminal'),
                        $name,
                    ));
                }
            }

            if ($self_closing) {
                continue;
            }

            if ($closing) {
                $open = (string) array_pop($stack);

                if ($name !== $open) {
                    return new \WP_Error(
                        'awpt_unbalanced_block_markup',
                        sprintf(
                            /* translators: 1: expected Gutenberg block name, 2: encountered closing block name. */
                            __(
                                'Generated Gutenberg block delimiters are mismatched: expected /wp:%1$s but found /wp:%2$s.',
                                'agent-wordpress-terminal',
                            ),
                            '' !== $open ? $open : __('none', 'agent-wordpress-terminal'),
                            $name,
                        ),
                        [
                            'status' => 400,
                            'expected_closing_block' => $open,
                            'actual_closing_block' => $name,
                            'open_block_stack' => [...$stack, $open],
                        ],
                    );
                }

                continue;
            }

            $stack[] = $name;
        }

        if ([] !== $stack) {
            return new \WP_Error(
                'awpt_unbalanced_block_markup',
                sprintf(
                    /* translators: %s: comma-separated Gutenberg block names. */
                    __('Generated Gutenberg block delimiters are not balanced; close: %s.', 'agent-wordpress-terminal'),
                    implode(', ', array_map(static fn(string $name): string => '/wp:' . $name, array_reverse($stack))),
                ),
                ['status' => 400, 'unclosed_blocks' => array_reverse($stack)],
            );
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $blocks
     */
    private function blocks_include_attachment(array $blocks, int $attachment_id): bool {
        $attachment_url = (string) wp_get_attachment_url($attachment_id);

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $inner_html = (string) ($block['innerHTML'] ?? '');

            if (in_array($name, ['core/image', 'core/cover'], true)) {
                $block_id = (int) ($attrs['id'] ?? $attrs['mediaId'] ?? 0);

                if (
                    $attachment_id === $block_id
                    || str_contains($inner_html, 'wp-image-' . $attachment_id)
                    || '' !== $attachment_url && str_contains($inner_html, $attachment_url)
                ) {
                    return true;
                }
            }

            $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];

            if ($this->blocks_include_attachment($inner_blocks, $attachment_id)) {
                return true;
            }
        }

        return false;
    }

    private function content_has_link(string $content, string $required_link): bool {
        $matches = [];
        preg_match_all('/\bhref\s*=\s*(["\'])(.*?)\1/is', $content, $matches);

        foreach ($matches[2] ?? [] as $href) {
            if (untrailingslashit(html_entity_decode($href)) === untrailingslashit($required_link)) {
                return true;
            }
        }

        return false;
    }

    private function error(string $code, string $message): \WP_Error {
        return new \WP_Error($code, $message, ['status' => 400]);
    }

    /** @return array{code: string, message: string} */
    private function error_summary(\WP_Error $error): array {
        return [
            'code' => (string) $error->get_error_code(),
            'message' => $error->get_error_message(),
        ];
    }
}
