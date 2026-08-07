<?php

/**
 * Heuristic page section outline for pattern-native prepare/routing.
 *
 * Roles and preserve flags are advisory only — never hard-block freehand.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds a stable top-level section outline from post content / BlockTree.
 */
final class PageSectionModel {
    public const ROLE_HEADER = 'header';
    public const ROLE_HERO = 'hero';
    public const ROLE_BODY = 'body';
    public const ROLE_STEPS = 'steps';
    public const ROLE_FAQ = 'faq';
    public const ROLE_CTA = 'cta';
    public const ROLE_QUERY = 'query';
    public const ROLE_MEDIA = 'media';
    public const ROLE_UNKNOWN = 'unknown';

    /** @var list<string> */
    private const DYNAMIC_BLOCK_NAMES = [
        'core/query',
        'core/query-pagination',
        'core/query-pagination-next',
        'core/query-pagination-previous',
        'core/query-pagination-numbers',
        'core/query-title',
        'core/query-no-results',
        'core/post-template',
        'core/comments',
        'core/comment-template',
        'core/latest-posts',
        'core/latest-comments',
        'core/navigation',
    ];

    /**
     * @param array{title?: string, post_type?: string} $context
     * @return list<array<string, mixed>>
     */
    public static function from_content(string $content, array $context = []): array {
        return self::from_tree(BlockTree::from_content($content), $context);
    }

    /**
     * @param array{title?: string, post_type?: string} $context
     * @return list<array<string, mixed>>
     */
    public static function from_tree(BlockTree $tree, array $context = []): array {
        $normalized = $tree->normalized();
        $sections = [];
        $header_assigned = false;

        foreach ($normalized as $block) {
            if (!is_array($block)) {
                continue;
            }

            $path = (string) ($block['path'] ?? '');

            // Top-level only (no dots).
            if ('' === $path || str_contains($path, '.')) {
                continue;
            }

            $raw = $tree->get_block($path) ?? [];
            $name = (string) ($block['name'] ?? $raw['blockName'] ?? '');
            $fingerprint = (string) ($block['fingerprint'] ?? '');

            if ('' === $fingerprint && [] !== $raw) {
                $fingerprint = BlockTree::fingerprint($raw);
            }

            $markup = self::block_markup($raw);
            $plain = trim(wp_strip_all_tags($markup));
            $names = self::collect_block_names($raw);
            $has_dynamic = self::has_dynamic_names($names);
            $heading = self::first_heading($raw, $plain);
            $links = self::extract_links($markup);
            $numeric_tokens = self::extract_numeric_tokens($plain);
            $role = self::infer_role(
                $name,
                $names,
                $plain,
                $heading,
                $has_dynamic,
                count($sections),
                $header_assigned,
            );

            if (self::ROLE_HEADER === $role) {
                $header_assigned = true;
            }

            $preserve = $has_dynamic || self::ROLE_QUERY === $role;

            $sections[] = [
                'path' => $path,
                'name' => $name,
                'block_name' => $name,
                'fingerprint' => $fingerprint,
                'role' => $role,
                'heading' => $heading,
                'heading_text' => $heading,
                'has_dynamic_blocks' => $has_dynamic,
                'preserve_by_default' => $preserve,
                'links' => $links,
                'numeric_tokens' => $numeric_tokens,
                'excerpt' => mb_substr($plain, 0, 80, 'UTF-8'),
                'depth' => 0,
            ];
        }

        unset($context); // reserved for title/post_type boosts later

        return $sections;
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return array<string, mixed>|null
     */
    public static function find_by_path(array $sections, string $path): ?array {
        $path = sanitize_text_field($path);

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            if ((string) ($section['path'] ?? '') === $path) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Soft intent → section suggestions (role / heading / path keyword match).
     *
     * @param list<array<string, mixed>> $sections
     * @return list<array{path: string, role: string, score: int, heading: string}>
     */
    public static function suggest_for_intent(array $sections, string $intent): array {
        $intent_l = mb_strtolower(trim($intent), 'UTF-8');

        if ('' === $intent_l) {
            return [];
        }

        $role_keywords = [
            self::ROLE_HEADER => ['header', 'title', 'masthead', 'top section', 'page title'],
            self::ROLE_HERO => ['hero', 'banner', 'cover', 'above the fold', 'jumbotron'],
            self::ROLE_FAQ => ['faq', 'faqs', 'questions', 'accordion'],
            self::ROLE_STEPS => ['steps', 'how to', 'how-to', 'process', 'timeline'],
            self::ROLE_CTA => ['cta', 'call to action', 'contact', 'apply', 'signup', 'sign up', 'get started'],
            self::ROLE_QUERY => ['query', 'posts', 'blog list', 'archive', 'loop'],
            self::ROLE_MEDIA => ['gallery', 'images', 'media', 'photos'],
            self::ROLE_BODY => ['body', 'main content', 'middle', 'about'],
        ];

        $scored = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $role = (string) ($section['role'] ?? self::ROLE_UNKNOWN);
            $path = (string) ($section['path'] ?? '');
            $heading = (string) ($section['heading'] ?? '');
            $score = 0;

            foreach ($role_keywords as $target_role => $keywords) {
                if ($role !== $target_role) {
                    continue;
                }

                foreach ($keywords as $keyword) {
                    if (str_contains($intent_l, $keyword)) {
                        $score += 10;
                    }
                }
            }

            if ('' !== $heading && str_contains($intent_l, mb_strtolower($heading, 'UTF-8'))) {
                $score += 8;
            }

            // Path ordinals: "section 0", "first section", "second".
            if (preg_match('/\b(?:section|path)\s*' . preg_quote($path, '/') . '\b/', $intent_l)) {
                $score += 12;
            }

            if ('0' === $path && preg_match('/\b(first|top)\b/', $intent_l)) {
                $score += 4;
            }

            if ($score > 0) {
                $scored[] = [
                    'path' => $path,
                    'role' => $role,
                    'score' => $score,
                    'heading' => $heading,
                ];
            }
        }

        usort(
            $scored,
            static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['path'], $b['path']),
        );

        return $scored;
    }

    /**
     * Soft least-destructive operation hint from intent + optional target section.
     *
     * @param array<string, mixed>|null $target_section
     * @return array{operation: string, reason: string}
     */
    public static function recommend_operation(string $intent, ?array $target_section = null, string $mode = ''): array {
        $intent_l = mb_strtolower(trim($intent), 'UTF-8');
        $mode = sanitize_key($mode);

        if (preg_match('/\b(copy|typo|wording|rewrite text|proofread|attrs? only|batch)\b/', $intent_l)) {
            return [
                'operation' => 'batch_or_attrs',
                'reason' => 'Intent looks copy/attribute only; prefer batch or attr updates over pattern replace.',
            ];
        }

        if (preg_match('/\b(whole\s*page|full\s*page|redesign\s+entire|from\s+scratch|restructure\s+page)\b/', $intent_l)) {
            return [
                'operation' => 'multi_section_or_redesign',
                'reason' => 'Whole-page restructure: prefer multi section replace/insert (M4 redesign only if hops are excessive).',
            ];
        }

        if ('insert' === $mode || preg_match('/\b(add|insert|append|new section|new block)\b/', $intent_l)) {
            return [
                'operation' => 'pattern_insert',
                'reason' => 'Intent is additive; prefer prepare-pattern-change mode=insert / propose-pattern-insert.',
            ];
        }

        if (is_array($target_section) && !empty($target_section['preserve_by_default'])) {
            return [
                'operation' => 'pattern_replace_with_preserve_warning',
                'reason' => 'Target has dynamic blocks; preserve by default unless intent explicitly replaces them.',
            ];
        }

        if ('replace' === $mode || preg_match('/\b(replace|swap|redesign section|new layout for)\b/', $intent_l)) {
            return [
                'operation' => 'pattern_replace',
                'reason' => 'Structural section change; prefer prepare-pattern-change mode=replace → propose-pattern-replace.',
            ];
        }

        return [
            'operation' => 'pattern_replace_or_surgical',
            'reason' => 'Default: try section prepare/replace when structure changes; freehand remains available when no pattern fits.',
        ];
    }

    /**
     * @param list<string> $names
     * @param list<string> $collected
     */
    private static function infer_role(
        string $root_name,
        array $names,
        string $plain,
        string $heading,
        bool $has_dynamic,
        int $index,
        bool $header_assigned,
    ): string {
        $plain_l = mb_strtolower($plain, 'UTF-8');
        $heading_l = mb_strtolower($heading, 'UTF-8');
        $name_blob = mb_strtolower(implode(' ', $names) . ' ' . $root_name, 'UTF-8');

        if ($has_dynamic || self::has_dynamic_names($names)) {
            return self::ROLE_QUERY;
        }

        $looks_header = str_contains($name_blob, 'post-title')
            || str_contains($name_blob, 'site-title')
            || str_contains($name_blob, 'content-header')
            || str_contains($name_blob, 'page-header');

        if (!$header_assigned && (0 === $index) && ($looks_header || '' !== $heading && mb_strlen($heading, 'UTF-8') < 80)) {
            // First section with a short heading often is the page header/hero band.
            if ($looks_header) {
                return self::ROLE_HEADER;
            }
        }

        if (!$header_assigned && $looks_header) {
            return self::ROLE_HEADER;
        }

        if (
            str_contains($name_blob, 'core/cover')
            || str_contains($plain_l, 'hero')
            || str_contains($heading_l, 'hero')
            || (str_contains($name_blob, 'core/media-text') && 0 === $index)
        ) {
            return self::ROLE_HERO;
        }

        if (
            str_contains($plain_l, 'faq')
            || str_contains($heading_l, 'faq')
            || str_contains($plain_l, 'frequently asked')
            || str_contains($name_blob, 'core/details')
            || (str_contains($name_blob, 'core/list') && (str_contains($plain_l, 'question') || substr_count($plain_l, '?') >= 2))
        ) {
            return self::ROLE_FAQ;
        }

        if (
            str_contains($plain_l, 'step ')
            || str_contains($heading_l, 'how to')
            || str_contains($heading_l, 'steps')
            || str_contains($plain_l, 'how it works')
            || (str_contains($name_blob, 'core/list') && preg_match('/\b(1\.|2\.|3\.)\b/', $plain_l))
        ) {
            return self::ROLE_STEPS;
        }

        if (
            str_contains($name_blob, 'core/buttons')
            || str_contains($name_blob, 'core/button')
            || preg_match('/\b(contact us|get started|apply now|sign up|book now|call to action)\b/', $plain_l)
        ) {
            // Buttons alone can be CTA; prefer CTA when CTA language or buttons dominate short section.
            if (
                preg_match('/\b(contact|apply|sign up|get started|book now)\b/', $plain_l)
                || (str_contains($name_blob, 'core/button') && mb_strlen($plain, 'UTF-8') < 200)
            ) {
                return self::ROLE_CTA;
            }
        }

        $media_names = array_filter(
            $names,
            static fn (string $n): bool => str_contains($n, 'image')
                || str_contains($n, 'gallery')
                || str_contains($n, 'media-text')
                || str_contains($n, 'video')
                || str_contains($n, 'embed'),
        );

        if (
            count($media_names) > 0
            && count($media_names) >= max(1, (int) floor(count($names) / 2))
            && mb_strlen($plain, 'UTF-8') < 120
        ) {
            return self::ROLE_MEDIA;
        }

        if (
            str_contains($name_blob, 'core/group')
            || str_contains($name_blob, 'core/columns')
            || str_contains($name_blob, 'core/paragraph')
            || mb_strlen($plain, 'UTF-8') > 40
        ) {
            return self::ROLE_BODY;
        }

        return self::ROLE_UNKNOWN;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private static function collect_block_names(array $block): array {
        $names = [];
        $name = (string) ($block['blockName'] ?? $block['name'] ?? '');

        if ('' !== $name) {
            $names[] = $name;
        }

        $inner = ArrayKey::list_of_maps($block['innerBlocks'] ?? $block['inner'] ?? null);

        foreach ($inner as $child) {
            $names = array_merge($names, self::collect_block_names($child));
        }

        return $names;
    }

    /**
     * @param list<string> $names
     */
    private static function has_dynamic_names(array $names): bool {
        foreach ($names as $name) {
            $name = mb_strtolower((string) $name, 'UTF-8');

            if (in_array($name, self::DYNAMIC_BLOCK_NAMES, true)) {
                return true;
            }

            // Theme patterns sometimes namespace query wrappers.
            if (str_contains($name, 'query') && str_contains($name, 'loop')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function block_markup(array $block): string {
        if ([] === $block) {
            return '';
        }

        if (function_exists('serialize_block')) {
            $serialized = serialize_block($block);

            if (is_string($serialized) && '' !== $serialized) {
                return $serialized;
            }
        }

        $html = (string) ($block['innerHTML'] ?? '');
        $inner = ArrayKey::list_of_maps($block['innerBlocks'] ?? null);

        foreach ($inner as $child) {
            $html .= self::block_markup($child);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function first_heading(array $block, string $plain_fallback): string {
        $found = self::find_heading_text($block);

        if ('' !== $found) {
            return mb_substr($found, 0, 120, 'UTF-8');
        }

        // First non-empty line of plain text as weak heading.
        $lines = preg_split('/\R+/', $plain_fallback) ?: [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ('' !== $line) {
                return mb_substr($line, 0, 120, 'UTF-8');
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function find_heading_text(array $block): string {
        $name = (string) ($block['blockName'] ?? $block['name'] ?? '');

        if ('core/heading' === $name || 'core/post-title' === $name) {
            $html = (string) ($block['innerHTML'] ?? '');
            $text = trim(wp_strip_all_tags($html));

            if ('' !== $text) {
                return $text;
            }
        }

        $html = (string) ($block['innerHTML'] ?? '');

        if (preg_match('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $m)) {
            $text = trim(wp_strip_all_tags((string) $m[1]));

            if ('' !== $text) {
                return $text;
            }
        }

        $inner = ArrayKey::list_of_maps($block['innerBlocks'] ?? $block['inner'] ?? null);

        foreach ($inner as $child) {
            $text = self::find_heading_text($child);

            if ('' !== $text) {
                return $text;
            }
        }

        return '';
    }

    /** @return list<string> */
    private static function extract_links(string $markup): array {
        if ('' === $markup) {
            return [];
        }

        $matches = [];
        preg_match_all('/<a\b[^>]*\bhref=(?:"([^"]+)"|\'([^\']+)\')/i', $markup, $matches);
        $links = [];

        foreach (array_keys($matches[0] ?? []) as $index) {
            $url = html_entity_decode((string) (($matches[1][$index] ?? '') ?: ($matches[2][$index] ?? '')));

            if ('' !== $url) {
                $links[] = $url;
            }
        }

        return array_values(array_unique($links));
    }

    /** @return list<string> */
    private static function extract_numeric_tokens(string $text): array {
        if ('' === $text) {
            return [];
        }

        $matches = [];
        preg_match_all('/\b\d+(?:[.\/-]\d+)*(?:\([a-z0-9]+\))?\b/i', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }
}
