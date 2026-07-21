<?php

/**
 * Same-site frontend HTML inspection for layout diagnosis.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Fetches a same-site page and returns structure useful for CSS/layout debugging.
 *
 * Prefer inventory derived from the page over hard-coded product class lists.
 */
final class FrontendInspector {
    private SameSiteHttpClient $http;

    public function __construct(?SameSiteHttpClient $http = null) {
        $this->http = $http ?? new SameSiteHttpClient();
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function inspect(string $url, string $selector = '', int $snippet_chars = 6_000): array|\WP_Error {
        $fetch = $this->http->get($url, ['Accept' => 'text/html,application/xhtml+xml'], 20);
        $response = $fetch['response'];

        if (is_wp_error($response)) {
            return new \WP_Error('awpt_inspect_failed', $response->get_error_message(), ['status' => 502]);
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $header = wp_remote_retrieve_header($response, 'content-type');
        $content_type = is_array($header) ? (string) ($header[0] ?? '') : $header;
        $selector = trim($selector);
        $snippet_chars = max(1_000, min(8_000, $snippet_chars));
        $class_inventory = $this->class_inventory($body);
        $layout_signals = $this->layout_signals($body, $class_inventory);

        return [
            'url' => $url,
            'status_code' => $status_code,
            'content_type' => $content_type,
            'used_loopback' => $fetch['used_loopback'],
            'title' => $this->extract_title($body),
            'stylesheets' => $this->extract_stylesheets($body),
            'class_inventory' => array_slice($class_inventory, 0, 24),
            'layout_signals' => $layout_signals,
            'selector' => $selector,
            'html_snippet' => $this->snippet_for_selector($body, $selector, $snippet_chars, $class_inventory),
            'body_excerpt' => mb_substr(wp_strip_all_tags($body), 0, 800, 'UTF-8'),
            'recommended_next_tools' => $this->recommended_next_tools($class_inventory, $layout_signals),
        ];
    }

    private function extract_title(string $html): string {
        $matches = [];

        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $matches)) {
            return trim(html_entity_decode(wp_strip_all_tags($matches[1]), ENT_QUOTES | ENT_HTML5));
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function extract_stylesheets(string $html): array {
        $urls = [];
        $tags = [];

        if (preg_match_all('~<link[^>]+rel=["\']stylesheet["\'][^>]*>~i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                $href = [];

                if (preg_match('~href=["\']([^"\']+)["\']~i', $tag, $href)) {
                    $urls[] = html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5);
                }
            }
        }

        return array_values(array_unique(array_slice($urls, 0, 40)));
    }

    /**
     * Top class tokens found on the page (inventory, not a product checklist).
     *
     * @return list<array{class: string, count: int}>
     */
    private function class_inventory(string $html): array {
        $counts = [];
        $matches = [];

        if (preg_match_all('~\bclass=(["\'])(.*?)\1~is', $html, $matches)) {
            foreach ($matches[2] as $class_attr) {
                foreach (preg_split('/\s+/', trim($class_attr)) ?: [] as $token) {
                    $token = strtolower(trim($token));

                    if ('' === $token || strlen($token) < 3 || strlen($token) > 80) {
                        continue;
                    }

                    // Skip pure utility noise.
                    if (preg_match('/^(wp-container-|wp-elements-|is-layout-|has-global-padding)/', $token)) {
                        continue;
                    }

                    $counts[$token] = ($counts[$token] ?? 0) + 1;
                }
            }
        }

        arsort($counts);
        $items = [];

        foreach (array_slice($counts, 0, 40, true) as $class => $count) {
            $items[] = ['class' => $class, 'count' => (int) $count];
        }

        return $items;
    }

    /**
     * @param list<array{class: string, count: int}> $inventory
     * @return array{
     *     interesting_classes: list<string>,
     *     has_position_sticky: bool,
     *     has_columns: bool,
     *     has_site_header: bool,
     *     sticky_style_hint: string|null,
     *     has_admin_bar_offset_var: bool
     * }
     */
    private function layout_signals(string $html, array $inventory): array {
        $classes = array_column($inventory, 'class');
        $has = static fn(string $needle): bool => in_array($needle, $classes, true) || str_contains($html, $needle);

        $sticky_top = null;
        $m = [];

        if (
            preg_match('~is-position-sticky[^>]{0,200}style="([^"]*)"~i', $html, $m)
            || preg_match('~style="([^"]*position:\s*sticky[^"]*)"~i', $html, $m)
        ) {
            $sticky_top = $m[1] ?? '';
        }

        $css = [];

        if (preg_match('~\.wp-container-[^{]+\{[^}]*position:sticky[^}]*\}~i', $html, $css)) {
            $sticky_top = ($sticky_top ?? '') . ' | inline-css: ' . mb_substr($css[0], 0, 200);
        }

        $interesting = array_values(array_filter($classes, static function (string $class): bool {
            return (bool) preg_match(
                '/(layout|docs|toc|sticky|sidebar|sidenav|header|footer|nav|hero|columns)/i',
                $class,
            );
        }));

        return [
            'interesting_classes' => array_slice($interesting, 0, 24),
            'has_position_sticky' => $has('is-position-sticky') || str_contains($html, 'position:sticky'),
            'has_columns' => $has('wp-block-columns'),
            'has_site_header' => $has('site-header'),
            'sticky_style_hint' => $sticky_top,
            'has_admin_bar_offset_var' => str_contains($html, '--wp-admin--admin-bar--position-offset'),
        ];
    }

    /**
     * @param list<array{class: string, count: int}> $inventory
     */
    private function snippet_for_selector(string $html, string $selector, int $max_chars, array $inventory): string {
        if ('' !== $selector) {
            $token = ltrim($selector, '.#');
            $snippet = $this->window_around($html, $token, $max_chars);

            return '' !== $snippet ? $snippet : mb_substr($html, 0, min(2_000, $max_chars), 'UTF-8');
        }

        // Prefer a class from the page inventory that looks layout-related.
        foreach ($inventory as $row) {
            $class = $row['class'];

            if ('' === $class || !preg_match('/(layout|docs|toc|sticky|sidebar|sidenav|columns)/i', $class)) {
                continue;
            }

            $snippet = $this->window_around($html, $class, $max_chars);

            if ('' !== $snippet) {
                return $snippet;
            }
        }

        foreach (['wp-block-columns', 'wp-site-blocks', 'entry-content'] as $hint) {
            $snippet = $this->window_around($html, $hint, $max_chars);

            if ('' !== $snippet) {
                return $snippet;
            }
        }

        return mb_substr($html, 0, $max_chars, 'UTF-8');
    }

    /**
     * @param list<array{class: string, count: int}> $inventory
     * @param array{
     *     interesting_classes: list<string>,
     *     has_position_sticky: bool,
     *     has_columns: bool,
     *     has_site_header: bool,
     *     sticky_style_hint: string|null,
     *     has_admin_bar_offset_var: bool
     * } $layout_signals
     * @return list<array{tool: string, input: array<string, mixed>}>
     */
    private function recommended_next_tools(array $inventory, array $layout_signals): array {
        $terms = [];

        foreach (array_slice($inventory, 0, 12) as $row) {
            $class = $row['class'];

            if (preg_match('/(layout|docs|toc|sticky|sidebar|sidenav)/i', $class)) {
                $terms[] = $class;
            }
        }

        foreach ($layout_signals['interesting_classes'] as $class) {
            if ('' !== $class) {
                $terms[] = $class;
            }
        }

        $terms = array_values(array_unique(array_slice($terms, 0, 6)));
        $tools = [
            [
                'tool' => 'awpt/list-knowledge-sources',
                'input' => ['sample' => 12],
            ],
        ];

        if ([] !== $terms) {
            $tools[] = [
                'tool' => 'awpt/search-knowledge',
                'input' => ['query' => implode(' ', $terms), 'limit' => 8],
            ];
        } else {
            $tools[] = [
                'tool' => 'awpt/search-knowledge',
                'input' => ['query' => 'theme css layout styles', 'limit' => 8],
            ];
        }

        return $tools;
    }

    private function window_around(string $html, string $needle, int $max_chars): string {
        $pos = stripos($html, $needle);

        if (false === $pos) {
            return '';
        }

        $start = max(0, $pos - (int) ($max_chars / 4));

        return mb_substr($html, $start, $max_chars, 'UTF-8');
    }
}
