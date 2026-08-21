<?php

/**
 * Merges resolved WordPress tokens with Domain Pack design knowledge.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Domain\DesignCatalog;
use AWPT\Domain\PatternMetadataCatalog;

if (!defined('ABSPATH')) {
    exit();
}

final class DesignSystemContextService {
    public const DETAIL_SLIM = 'slim';

    public const DETAIL_COMPOSE = 'compose';

    /** Evaluate planning: slim plus archetypes and a compact pattern-catalog index. */
    public const DETAIL_EVALUATE = 'evaluate';

    public const DETAIL_GLOBAL_STYLES = 'global_styles';

    private SiteDesignContext $site;

    private DesignCatalog $catalog;

    private PatternMetadataCatalog $patterns;

    public function __construct(
        ?SiteDesignContext $site = null,
        ?DesignCatalog $catalog = null,
        ?PatternMetadataCatalog $patterns = null,
    ) {
        $this->site = $site ?? new SiteDesignContext();
        $this->catalog = $catalog ?? new DesignCatalog();
        $this->patterns = $patterns ?? new PatternMetadataCatalog();
    }

    /** @return array<string, mixed> */
    public function snapshot(string $scope = 'edit'): array {
        $site = $this->site->resolve();
        $catalog = $this->catalog->all();
        $patterns = $this->patterns->all();
        $pattern_summary = [];

        foreach ($patterns as $name => $pattern) {
            $pattern_summary[$name] = array_filter(
                [
                    'role' => (string) ($pattern['role'] ?? ''),
                    'design' => ArrayKey::as_map($pattern['design'] ?? null),
                    'placement' => ArrayKey::as_map($pattern['placement'] ?? null),
                    'relationships' => ArrayKey::as_map($pattern['relationships'] ?? null),
                ],
                static fn(mixed $value): bool => !in_array($value, ['', []], true),
            );
        }

        $sections = [
            'tokens' => $this->theme_tokens(ArrayKey::as_map($site['design_tokens'])),
            'token_roles' => ArrayKey::as_map($catalog['token_roles'] ?? null),
            'components' => ArrayKey::as_map($catalog['components'] ?? null),
            'style_variations' => ArrayKey::as_map($catalog['style_variations'] ?? null),
            'archetypes' => ArrayKey::as_map($catalog['archetypes'] ?? null),
            'patterns' => $pattern_summary,
            'constraints' => [
                'guidance_ids' => $this->catalog->guidance_for($scope),
                'prefer_presets' => true,
                'preserve_pattern_semantics' => true,
            ],
        ];
        $identity = [
            'theme_name' => $site['theme_name'],
            'stylesheet' => $site['stylesheet'],
            'template' => $site['template'],
            'sources' => $catalog['sources'] ?? [],
        ];

        return [
            'identity' => $identity,
            'hash' => hash('sha256', (string) wp_json_encode([$identity, $sections])),
            'catalog_hash' => $this->catalog->hash(),
            'pattern_catalog' => $this->patterns->index(),
            'pattern_catalog_hash' => $this->patterns->hash(),
            'sections' => $sections,
            'diagnostics' => $this->catalog->diagnostics(),
        ];
    }

    /** @return array<string, mixed> */
    public function read(
        array $requested = [],
        string $scope = 'edit',
        string $intent = '',
        string $block = '',
    ): array {
        $snapshot = $this->snapshot($scope);
        $available = ArrayKey::as_map($snapshot['sections'] ?? null);
        $allowed = ['tokens', 'components', 'style_variations', 'archetypes', 'patterns', 'constraints'];
        $requested = [] === $requested
            ? $allowed
            : array_values(array_intersect($allowed, array_map('sanitize_key', $requested)));
        $sections = [];

        foreach ($requested as $section) {
            $value = $available[$section] ?? [];

            if ('tokens' === $section) {
                $value = ['resolved' => $value, 'roles' => $available['token_roles'] ?? []];
            } elseif ('components' === $section && '' !== $block) {
                $value = array_filter(
                    ArrayKey::as_map($value),
                    static fn(array $component): bool => $block === (string) ($component['block'] ?? ''),
                );
            } elseif ('patterns' === $section) {
                $value = [
                    'index' => $this->patterns->index(),
                    'next' => 'Call awpt/recommend-patterns with the concrete intent for ranked selection guidance.',
                ];
            }

            $sections[$section] = $value;
        }

        return [
            'identity' => $snapshot['identity'],
            'hash' => $snapshot['hash'],
            'catalog_hash' => $snapshot['catalog_hash'],
            'pattern_catalog_hash' => $snapshot['pattern_catalog_hash'],
            'sections' => $sections,
            'diagnostics' => $snapshot['diagnostics'],
        ];
    }

    public function format_for_prompt(string $scope, string $message, string $detail = self::DETAIL_SLIM): string {
        $snapshot = $this->snapshot($scope);
        $sections = ArrayKey::as_map($snapshot['sections'] ?? null);
        $tokens = ArrayKey::as_map($sections['tokens'] ?? null);
        $constraints = ArrayKey::as_map($sections['constraints'] ?? null);
        $summary = [
            'identity' => $snapshot['identity'],
            'design_context_hash' => $snapshot['hash'],
            'catalog_hash' => $snapshot['catalog_hash'],
            'pattern_catalog' => $snapshot['pattern_catalog'],
            'pattern_catalog_hash' => $snapshot['pattern_catalog_hash'],
            'scope' => sanitize_key($scope),
            'token_counts' => array_map(static fn(mixed $items): int => is_array($items) ? count($items) : 0, $tokens),
            'guidance_ids' => $constraints['guidance_ids'] ?? [],
            'prefer_presets' => ArrayKey::rest_bool($constraints['prefer_presets'] ?? true),
        ];

        if (self::DETAIL_COMPOSE === $detail) {
            $summary['token_roles'] = $sections['token_roles'] ?? [];
            $summary['components'] = array_slice(ArrayKey::as_map($sections['components'] ?? null), 0, 30, true);
            $summary['archetypes'] = $sections['archetypes'] ?? [];
        } elseif (self::DETAIL_EVALUATE === $detail) {
            $summary['archetypes'] = $sections['archetypes'] ?? [];
        } elseif (self::DETAIL_GLOBAL_STYLES === $detail) {
            $summary['tokens'] = $tokens;
            $summary['token_roles'] = $sections['token_roles'] ?? [];
            $summary['style_variations'] = $sections['style_variations'] ?? [];
        }

        return 'Design system authority (already injected; call awpt/read-design-system only to expand a named section):'
        . "\n"
        . (string) wp_json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function theme_tokens(array $tokens): array {
        $clean = [];

        foreach ($tokens as $domain => $value) {
            if (!is_array($value)) {
                $clean[$domain] = $value;
                continue;
            }

            // Merged theme.json collections are keyed by origin. Exclude Core defaults
            // when the active theme supplies its own authority for that token domain.
            if (isset($value['theme']) || isset($value['custom'])) {
                $items = [];

                foreach (['theme', 'custom'] as $origin) {
                    if (!is_array($value[$origin] ?? null)) {
                        continue;
                    }

                    array_push($items, ...$value[$origin]);
                }

                $clean[$domain] = $items;
            } else {
                $clean[$domain] = $value;
            }
        }

        return ArrayKey::string_map($clean);
    }
}
