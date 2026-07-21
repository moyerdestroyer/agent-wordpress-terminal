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
    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $search = '', int $max = 100): array {
        $search = mb_strtolower(trim($search));
        $items = [];

        foreach ($this->registered_patterns() as $pattern) {
            if (!$this->matches($pattern, $search)) {
                continue;
            }

            $items[] = $this->summary($pattern);
        }

        foreach ($this->reusable_patterns() as $pattern) {
            if (!$this->matches($pattern, $search)) {
                continue;
            }

            $items[] = $this->summary($pattern);
        }

        usort($items, static fn(array $a, array $b): int => strnatcasecmp((string) $a['title'], (string) $b['title']));

        return array_slice($items, 0, max(1, min(200, $max)));
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
    public function suggestions(string $requested_name, int $max = 12): array {
        $requested_name = mb_strtolower(trim($requested_name));
        $active_prefix = function_exists('get_stylesheet') ? sanitize_key(get_stylesheet()) . '/' : '';
        $requested_terms = array_values(array_filter(preg_split('/[^a-z0-9]+/i', $requested_name) ?: []));
        $ranked = [];

        foreach ($this->list('', 200) as $pattern) {
            $name = mb_strtolower((string) ($pattern['name'] ?? ''));
            $haystack = mb_strtolower(implode(' ', [
                $name,
                (string) ($pattern['title'] ?? ''),
                (string) ($pattern['description'] ?? ''),
                implode(' ', $this->string_list($pattern['categories'] ?? null)),
            ]));
            $score = '' !== $active_prefix && str_starts_with($name, $active_prefix) ? 20 : 0;

            foreach ($requested_terms as $term) {
                if (strlen($term) >= 3 && str_contains($haystack, $term)) {
                    $score += 5;
                }
            }

            if (str_contains($requested_name, 'cta') && str_contains($haystack, 'call to action')) {
                $score += 8;
            }

            if (str_contains($requested_name, 'hero') && str_contains($haystack, 'hero')) {
                $score += 8;
            }

            $ranked[] = ['score' => $score, 'pattern' => $pattern];
        }

        usort($ranked, static function (array $left, array $right): int {
            $score = (int) $right['score'] <=> (int) $left['score'];

            if (0 !== $score) {
                return $score;
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
    public function summary(array $pattern): array {
        $content = (string) ($pattern['content'] ?? '');

        return [
            'name' => (string) ($pattern['name'] ?? ''),
            'title' => (string) ($pattern['title'] ?? ''),
            'description' => (string) ($pattern['description'] ?? ''),
            'source' => (string) ($pattern['source'] ?? 'registered'),
            'categories' => $this->string_list($pattern['categories'] ?? null),
            'block_types' => $this->string_list($pattern['blockTypes'] ?? null),
            'post_types' => $this->string_list($pattern['postTypes'] ?? null),
            'viewport_width' => (int) ($pattern['viewportWidth'] ?? 0),
            'block_count' => count(array_filter(parse_blocks($content), BlockTree::has_block_name(...))),
        ];
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
     * @return list<string>
     */
    private function expand_search_terms(string $search): array {
        $raw = array_values(array_filter(preg_split('/[^a-z0-9]+/i', mb_strtolower($search)) ?: []));
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
            if (isset($aliases[$term])) {
                $terms = [...$terms, ...$aliases[$term]];
            }
        }

        return array_values(array_unique($terms));
    }

    /** @return list<string> */
    private function string_list(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
