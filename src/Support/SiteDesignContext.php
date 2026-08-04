<?php

/**
 * Active-site design context for prompts, retrieval, and pattern policy.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves the effective WordPress design system without introducing a planner.
 */
final class SiteDesignContext {
    public const LEVEL_NONE = 'none';
    public const LEVEL_TOKENS = 'tokens';
    public const LEVEL_SECTION = 'section';
    public const LEVEL_COMPOSITION = 'composition';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $resolved = null;

    /**
     * @return array{
     *     theme_name: string,
     *     stylesheet: string,
     *     template: string,
     *     parent_theme_name: string,
     *     preferred_pattern_namespaces: list<string>,
     *     design_tokens: array<string, mixed>
     * }
     */
    public function resolve(): array {
        if (is_array($this->resolved)) {
            /** @var array{
             *     theme_name: string,
             *     stylesheet: string,
             *     template: string,
             *     parent_theme_name: string,
             *     preferred_pattern_namespaces: list<string>,
             *     design_tokens: array<string, mixed>
             * } $resolved
             */
            $resolved = $this->resolved;

            return $resolved;
        }

        $stylesheet = sanitize_key(get_stylesheet());
        $template = function_exists('get_template') ? sanitize_key(get_template()) : $stylesheet;
        $theme = wp_get_theme($stylesheet);
        $theme_name = $theme->exists() ? $theme->get('Name') : $stylesheet;
        $parent_name = '';

        if ('' !== $template && $template !== $stylesheet) {
            $parent = wp_get_theme($template);
            $parent_name = $parent->exists() ? $parent->get('Name') : $template;
        }

        $this->resolved = [
            'theme_name' => '' !== $theme_name ? $theme_name : $stylesheet,
            'stylesheet' => $stylesheet,
            'template' => $template,
            'parent_theme_name' => $parent_name,
            'preferred_pattern_namespaces' => array_values(array_unique(array_filter([
                '' !== $stylesheet ? $stylesheet . '/' : '',
                '' !== $template ? $template . '/' : '',
            ]))),
            'design_tokens' => $this->design_tokens($theme),
        ];

        /** @var array{
         *     theme_name: string,
         *     stylesheet: string,
         *     template: string,
         *     parent_theme_name: string,
         *     preferred_pattern_namespaces: list<string>,
         *     design_tokens: array<string, mixed>
         * } $resolved
         */
        $resolved = $this->resolved;

        return $resolved;
    }

    public function request_level(string $message): string {
        $message = mb_strtolower(trim($message));

        if ('' === $message) {
            return self::LEVEL_NONE;
        }

        if ((bool) preg_match(
            '/\b(create|generate|make|build|design|draft|redesign|rewrite|improve|polish)\b.*\b('
            . 'page|landing|homepage|post|article|template|layout'
            . ')\b/i',
            $message,
        )) {
            return self::LEVEL_COMPOSITION;
        }

        if ((bool) preg_match(
            '/\b(add|insert|create|design|replace|revise|change|improve)\b.*\b('
            . 'section|hero|cta|call to action|header|footer|gallery|columns?|features?|pricing|layout|pattern'
            . ')\b/i',
            $message,
        )) {
            return self::LEVEL_SECTION;
        }

        if ((bool) preg_match(
            '/\b(design|style|theme|color|palette|font|typography|spacing|padding|margin|css|scss|'
            . 'global styles?|template|width|alignment|border|background|responsive|mobile|desktop)\b/i',
            $message,
        )) {
            return self::LEVEL_TOKENS;
        }

        return self::LEVEL_NONE;
    }

    public function enrich_retrieval_query(string $message): string {
        $message = trim($message);
        $level = $this->request_level($message);

        if ('' === $message || self::LEVEL_NONE === $level) {
            return $message;
        }

        $context = $this->resolve();
        $terms = [
            $context['theme_name'],
            $context['stylesheet'],
            match ($level) {
                self::LEVEL_COMPOSITION => 'active theme design patterns theme.json layout',
                self::LEVEL_SECTION => 'active theme patterns layout section',
                default => 'active theme theme.json design tokens styles',
            },
        ];
        $lower = mb_strtolower($message);
        $append = [];

        foreach ($terms as $term) {
            $term = trim($term);

            if ('' !== $term && !str_contains($lower, mb_strtolower($term))) {
                $append[] = $term;
            }
        }

        return trim($message . ' ' . implode(' ', array_unique($append)));
    }

    /**
     * @param bool $include_tokens When false, emit only the active-theme one-liner
     *                             (ordinary chat / non-design turns).
     */
    public function prompt_summary(string $message, bool $include_tokens = true): string {
        $context = $this->resolve();
        $level = $this->request_level($message);
        $parent = '' !== $context['parent_theme_name']
            ? sprintf(' Parent theme: %s (%s).', $context['parent_theme_name'], $context['template'])
            : '';
        $header = sprintf(
            'Active design authority: %s (%s).%s Composition policy level for this request: %s.'
            . ' Prefer active/parent-theme patterns, then site-owned reusable patterns; Core or custom composition'
            . ' is an allowed fallback when it better fits the request and the reason is recorded.',
            $context['theme_name'],
            $context['stylesheet'],
            $parent,
            $level,
        );

        // Callers (TurnProfile) decide when tokens are worth the payload; do not
        // re-impose LEVEL_NONE here so composition turns still get tokens when asked.
        if (!$include_tokens) {
            return $header;
        }

        $encoded = wp_json_encode($context['design_tokens'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tokens = is_string($encoded) ? $encoded : '{}';

        if (strlen($tokens) > 3500) {
            $tokens = mb_substr($tokens, 0, 3500, 'UTF-8') . '…';
        }

        return $header . "\nResolved WordPress design tokens:\n" . $tokens;
    }

    public function pattern_owner(string $name, string $source = 'registered'): string {
        if ('reusable' === $source || str_starts_with($name, 'reusable/')) {
            return 'reusable';
        }

        $context = $this->resolve();

        if ('' !== $context['stylesheet'] && str_starts_with($name, $context['stylesheet'] . '/')) {
            return 'active_theme';
        }

        if (
            '' !== $context['template']
            && $context['template'] !== $context['stylesheet']
            && str_starts_with($name, $context['template'] . '/')
        ) {
            return 'parent_theme';
        }

        return str_starts_with($name, 'core/') ? 'core' : 'other';
    }

    public function is_site_native_owner(string $owner): bool {
        return in_array($owner, ['active_theme', 'parent_theme', 'reusable'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function design_tokens(\WP_Theme $theme): array {
        $raw = [];

        if (class_exists('\WP_Theme_JSON_Resolver') && method_exists('\WP_Theme_JSON_Resolver', 'get_merged_data')) {
            $merged = \WP_Theme_JSON_Resolver::get_merged_data('custom');

            if (method_exists($merged, 'get_raw_data')) {
                $raw = $merged->get_raw_data();
            }
        }

        if ([] === $raw) {
            $path = trailingslashit($theme->get_stylesheet_directory()) . 'theme.json';

            if (is_readable($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                $raw = is_array($decoded) ? $decoded : [];
            }
        }

        $settings = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];
        $styles = is_array($raw['styles'] ?? null) ? $raw['styles'] : [];

        return array_filter(
            [
                'color_palette' => is_array($settings['color'] ?? null) ? $settings['color']['palette'] ?? null : null,
                'color_gradients' => is_array($settings['color'] ?? null)
                    ? $settings['color']['gradients'] ?? null
                    : null,
                'font_families' => is_array($settings['typography'] ?? null)
                    ? $settings['typography']['fontFamilies'] ?? null
                    : null,
                'font_sizes' => is_array($settings['typography'] ?? null)
                    ? $settings['typography']['fontSizes'] ?? null
                    : null,
                'spacing_sizes' => is_array($settings['spacing'] ?? null)
                    ? $settings['spacing']['spacingSizes'] ?? null
                    : null,
                'layout' => $settings['layout'] ?? null,
                'styles' => array_intersect_key($styles, array_flip(['color', 'spacing', 'typography'])),
                'custom_templates' => $raw['customTemplates'] ?? null,
                'template_parts' => $raw['templateParts'] ?? null,
            ],
            static fn(mixed $value): bool => null !== $value && [] !== $value && '' !== $value,
        );
    }
}
