<?php

/**
 * WordPress block pattern discovery.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Presents registered and reusable block patterns through one safe read model.
 */
final class PatternCatalog {
    private SiteDesignContext $design;

    public function __construct(?SiteDesignContext $design = null) {
        $this->design = $design ?? new SiteDesignContext();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $search = '', int $max = 100, string $post_type = ''): array {
        $search = mb_strtolower(trim($search));
        $post_type = sanitize_key($post_type);
        $items = [];

        foreach ($this->registered_patterns() as $pattern) {
            if (!$this->matches($pattern, $search)) {
                continue;
            }

            $items[] = $this->rankable_summary($pattern, $search, $post_type);
        }

        foreach ($this->reusable_patterns() as $pattern) {
            if (!$this->matches($pattern, $search)) {
                continue;
            }

            $items[] = $this->rankable_summary($pattern, $search, $post_type);
        }

        usort($items, static function (array $left, array $right): int {
            $compatibility = (int) ($right['_compatibility_rank'] ?? 0) <=> (int) ($left['_compatibility_rank'] ?? 0);

            if (0 !== $compatibility) {
                return $compatibility;
            }

            $relevance = (int) ($right['_relevance'] ?? 0) <=> (int) ($left['_relevance'] ?? 0);

            if (0 !== $relevance) {
                return $relevance;
            }

            $ownership = (int) ($right['_owner_rank'] ?? 0) <=> (int) ($left['_owner_rank'] ?? 0);

            return 0 !== $ownership ? $ownership : strnatcasecmp((string) $left['title'], (string) $right['title']);
        });

        return array_values(array_map(
            static function (array $item): array {
                unset($item['_compatibility_rank'], $item['_relevance'], $item['_owner_rank']);

                return $item;
            },
            array_slice($items, 0, max(1, min(200, $max))),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $name): ?array {
        $name = sanitize_text_field($name);

        foreach ($this->registered_patterns() as $pattern) {
            if ($name === ($pattern['name'] ?? '')) {
                return $pattern;
            }
        }

        $matches = [];

        if (preg_match('/^reusable\/(\d+)$/', $name, $matches)) {
            $post = get_post((int) $matches[1]);

            if (
                $post instanceof \WP_Post
                && 'wp_block' === $post->post_type
                && current_user_can('read_post', $post->ID)
            ) {
                return $this->reusable_pattern($post);
            }
        }

        return null;
    }

    /**
     * Return evidence the agent can use to recover from an invented or stale name.
     * Results are suggestions, not an automatically selected replacement.
     *
     * @return list<array<string, mixed>>
     */
    public function suggestions(string $requested_name, int $max = 12, string $post_type = ''): array {
        $requested_name = mb_strtolower(trim($requested_name));
        $requested_terms = $this->search_terms($requested_name);
        $ranked = [];

        foreach ($this->list('', 200, $post_type) as $pattern) {
            $name = mb_strtolower((string) ($pattern['name'] ?? ''));
            $haystack = mb_strtolower(implode(' ', [
                $name,
                (string) ($pattern['title'] ?? ''),
                (string) ($pattern['description'] ?? ''),
                implode(' ', $this->string_list($pattern['categories'] ?? null)),
            ]));
            $relevance = 0;

            foreach ($requested_terms as $term) {
                if (strlen($term) < 3 || !str_contains($haystack, $term)) {
                    continue;
                }

                $relevance += 5;
            }

            if (str_contains($requested_name, 'cta') && str_contains($haystack, 'call to action')) {
                $relevance += 8;
            }

            if (str_contains($requested_name, 'hero') && str_contains($haystack, 'hero')) {
                $relevance += 8;
            }

            $ranked[] = [
                'relevance' => $relevance,
                'ownership' => $this->owner_rank((string) ($pattern['owner'] ?? 'other')),
                'pattern' => $pattern,
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            $relevance = (int) $right['relevance'] <=> (int) $left['relevance'];

            if (0 !== $relevance) {
                return $relevance;
            }

            $ownership = (int) $right['ownership'] <=> (int) $left['ownership'];

            if (0 !== $ownership) {
                return $ownership;
            }

            return strnatcasecmp(
                (string) ($left['pattern']['title'] ?? ''),
                (string) ($right['pattern']['title'] ?? ''),
            );
        });

        return array_values(array_map(
            static fn(array $item): array => $item['pattern'],
            array_slice($ranked, 0, max(1, min(24, $max))),
        ));
    }

    /**
     * @param array<string, mixed> $pattern
     * @return array<string, mixed>
     */
    public function summary(array $pattern, string $post_type = ''): array {
        $content = (string) ($pattern['content'] ?? '');
        $source = (string) ($pattern['source'] ?? 'registered');
        $name = (string) ($pattern['name'] ?? '');
        $pattern_post_types = $this->string_list($pattern['postTypes'] ?? null);
        $post_type = sanitize_key($post_type);
        $compatibility = 'unspecified';

        if ('' !== $post_type) {
            $compatibility = [] === $pattern_post_types || in_array($post_type, $pattern_post_types, true)
                ? 'compatible'
                : 'incompatible';
        }

        return [
            'name' => $name,
            'title' => (string) ($pattern['title'] ?? ''),
            'description' => (string) ($pattern['description'] ?? ''),
            'source' => $source,
            'owner' => $this->design->pattern_owner($name, $source),
            'compatibility' => $compatibility,
            'categories' => $this->string_list($pattern['categories'] ?? null),
            'block_types' => $this->string_list($pattern['blockTypes'] ?? null),
            'post_types' => $pattern_post_types,
            'viewport_width' => (int) ($pattern['viewportWidth'] ?? 0),
            'block_count' => count(array_filter(parse_blocks($content), BlockTree::has_block_name(...))),
            'content_hash' => hash('sha256', $content),
            'composition_scope' => $this->composition_scope($name, (string) ($pattern['title'] ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function design_context(): array {
        $context = $this->design->resolve();

        return [
            'theme_name' => $context['theme_name'],
            'stylesheet' => $context['stylesheet'],
            'template' => $context['template'],
            'preferred_pattern_namespaces' => $context['preferred_pattern_namespaces'],
            'policy' => 'theme_native_preferred',
        ];
    }

    public function has_site_native_patterns(string $post_type = ''): bool {
        foreach ($this->list('', 200, $post_type) as $pattern) {
            if (
                'incompatible' !== (string) ($pattern['compatibility'] ?? '')
                && $this->design->is_site_native_owner((string) ($pattern['owner'] ?? ''))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function registered_patterns(): array {
        if (!class_exists('WP_Block_Patterns_Registry')) {
            return [];
        }

        $registry = \WP_Block_Patterns_Registry::get_instance();

        if (!method_exists($registry, 'get_all_registered')) {
            return [];
        }

        /** @var list<array<string, mixed>> $patterns */
        $patterns = $registry->get_all_registered();
        /** @var list<array<string, mixed>> $items */
        $items = [];

        foreach ($patterns as $pattern) {
            if ('' === (string) ($pattern['name'] ?? '')) {
                continue;
            }

            $pattern['source'] = 'registered';
            $items[] = $pattern;
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reusable_patterns(): array {
        $posts = get_posts([
            'post_type' => 'wp_block',
            'post_status' => ['publish', 'private'],
            'posts_per_page' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        /** @var list<array<string, mixed>> $items */
        $items = [];

        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post || !current_user_can('read_post', $post->ID)) {
                continue;
            }

            $items[] = $this->reusable_pattern($post);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function reusable_pattern(\WP_Post $post): array {
        return [
            'name' => 'reusable/' . $post->ID,
            'title' => get_the_title($post),
            'description' => __('Reusable block', 'agent-wordpress-terminal'),
            'content' => $post->post_content,
            'categories' => ['reusable'],
            'blockTypes' => [],
            'postTypes' => [],
            'viewportWidth' => 0,
            'source' => 'reusable',
        ];
    }

    /**
     * @param array<string, mixed> $pattern
     */
    private function matches(array $pattern, string $search): bool {
        if ('' === $search) {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', [
            (string) ($pattern['name'] ?? ''),
            (string) ($pattern['title'] ?? ''),
            (string) ($pattern['description'] ?? ''),
            implode(' ', $this->string_list($pattern['categories'] ?? null)),
        ]));

        if (str_contains($haystack, $search)) {
            return true;
        }

        // Token/synonym match so "docs" hits layout-page-documentation, "toc" hits
        // two-column-toc, etc. Exact substring alone misses common shortenings.
        foreach ($this->expand_search_terms($search) as $term) {
            if (strlen($term) < 3) {
                continue;
            }

            if (str_contains($haystack, $term)) {
                return true;
            }

            // Prefix: "doc" matches "documentation" tokens in the slug/title.
            if (preg_match('/(?:^|[^a-z0-9])' . preg_quote($term, '/') . '[a-z0-9]*/', $haystack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $pattern
     * @return array<string, mixed>
     */
    private function rankable_summary(array $pattern, string $search, string $post_type): array {
        $summary = $this->summary($pattern, $post_type);
        $summary['_compatibility_rank'] = 'incompatible' === $summary['compatibility'] ? 0 : 1;
        $summary['_relevance'] = $this->relevance_score($pattern, $search);
        $summary['_owner_rank'] = $this->owner_rank((string) $summary['owner']);

        return $summary;
    }

    /**
     * @param array<string, mixed> $pattern
     */
    private function relevance_score(array $pattern, string $search): int {
        if ('' === $search) {
            return 0;
        }

        $haystack = mb_strtolower(implode(' ', [
            (string) ($pattern['name'] ?? ''),
            (string) ($pattern['title'] ?? ''),
            (string) ($pattern['description'] ?? ''),
            implode(' ', $this->string_list($pattern['categories'] ?? null)),
        ]));
        $score = str_contains($haystack, $search) ? 100 : 0;

        foreach ($this->expand_search_terms($search) as $term) {
            if (strlen($term) < 3 || !str_contains($haystack, $term)) {
                continue;
            }

            $score += 10;
        }

        return $score;
    }

    private function owner_rank(string $owner): int {
        return match ($owner) {
            'active_theme' => 50,
            'parent_theme' => 45,
            'reusable' => 35,
            'core' => 20,
            default => 10,
        };
    }

    private function composition_scope(string $name, string $title): string {
        $value = mb_strtolower($name . ' ' . $title);

        return match (true) {
            (bool) preg_match('/(?:^|\/)layout-|landing page|page layout/', $value) => 'layout',
            (bool) preg_match('/hero|header/', $value) => 'hero',
            (bool) preg_match('/call.to.action|\bcta\b/', $value) => 'cta',
            (bool) preg_match('/media|image|gallery|photo/', $value) => 'media',
            default => 'section',
        };
    }

    /**
     * @return array{custom_block_names: list<string>, custom_classes: list<string>, requires_theme_research: bool}
     */
    public function design_dependencies(string $content): array {
        $block_matches = [];
        preg_match_all('/<!--\s+wp:([a-z0-9_-]+(?:\/[a-z0-9_-]+)?)/i', $content, $block_matches);
        $custom_blocks = [];

        foreach (array_values(array_unique($block_matches[1] ?? [])) as $block_name) {
            $block_name = (string) $block_name;

            if (!str_contains($block_name, '/') || str_starts_with($block_name, 'core/')) {
                continue;
            }

            $custom_blocks[] = $block_name;
        }

        $class_matches = [];
        preg_match_all('/(?:className&quot;:&quot;|className":"|class=")([^"&]+|"[^"]*)/i', $content, $class_matches);
        $classes = [];

        foreach ($class_matches[1] ?? [] as $class_list) {
            $split_classes = preg_split('/\s+/', trim((string) $class_list, "\" \t\n\r\0\x0B"));

            foreach (false !== $split_classes ? $split_classes : [] as $class_name) {
                if (
                    '' === $class_name
                    || str_starts_with($class_name, 'wp-')
                    || str_starts_with($class_name, 'has-')
                    || str_starts_with($class_name, 'is-')
                ) {
                    continue;
                }

                $classes[] = sanitize_html_class($class_name);
            }
        }

        $custom_blocks = array_slice(array_values(array_unique($custom_blocks)), 0, 12);
        $classes = array_slice(array_values(array_filter(array_unique($classes))), 0, 20);

        return [
            'custom_block_names' => $custom_blocks,
            'custom_classes' => $classes,
            'requires_theme_research' => [] !== $custom_blocks || [] !== $classes,
        ];
    }

    /**
     * @return list<string>
     */
    private function expand_search_terms(string $search): array {
        $raw = $this->search_terms($search);
        // Language-level expansions only (no theme-specific pattern slugs).
        $aliases = [
            'docs' => ['documentation', 'document', 'guide', 'reference'],
            'doc' => ['documentation', 'document'],
            'documentation' => ['docs', 'document'],
            'toc' => ['table', 'contents', 'navigation'],
            'sidebar' => ['sticky', 'navigation', 'aside'],
            'cta' => ['call', 'action'],
            'hero' => ['header', 'cover'],
            'news' => ['posts', 'recent', 'blog'],
        ];
        $terms = $raw;

        foreach ($raw as $term) {
            if (!array_key_exists($term, $aliases)) {
                continue;
            }

            $terms = [...$terms, ...$aliases[$term]];
        }

        return array_values(array_unique($terms));
    }

    /**
     * @return list<string>
     */
    private function search_terms(string $search): array {
        $parts = preg_split('/[^a-z0-9]+/i', mb_strtolower($search));

        return false === $parts ? [] : array_values(array_filter($parts));
    }

    /** @return list<string> */
    private function string_list(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
