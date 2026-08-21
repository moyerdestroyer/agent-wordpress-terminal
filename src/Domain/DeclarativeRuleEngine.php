<?php

/**
 * Evaluates the bounded Domain Pack v2 rule vocabulary.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;
use AWPT\Support\SiteDesignContext;

if (!defined('ABSPATH')) {
    exit();
}

final class DeclarativeRuleEngine {
    private DomainRuleRepository $rules;

    private PatternMetadataCatalog $patterns;

    public function __construct(?DomainRuleRepository $rules = null, ?PatternMetadataCatalog $patterns = null) {
        $this->rules = $rules ?? new DomainRuleRepository();
        $this->patterns = $patterns ?? new PatternMetadataCatalog();
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public function validate(string $content, array $context = []): array {
        if ('' === trim($content)) {
            return [];
        }

        $flat = $this->flatten(parse_blocks($content));
        $findings = $this->validate_pattern_metadata($flat);
        array_push($findings, ...$this->validate_design_components($flat));
        array_push($findings, ...$this->validate_color_pairs($flat));

        $saw_preset_rule = false;

        foreach ($this->rules->all() as $rule) {
            if (!$this->applies($rule, $context)) {
                continue;
            }

            if ('tokens.require_presets' === (string) $rule['type']) {
                $saw_preset_rule = true;
            }

            array_push($findings, ...$this->evaluate($rule, $flat));
        }

        $baseline = $this->theme_preset_rule();

        if (!$saw_preset_rule && [] !== $baseline && $this->applies($baseline, $context)) {
            array_push($findings, ...$this->preset_tokens(
                $baseline,
                $flat,
                ArrayKey::as_map($baseline['config'] ?? null),
            ));
        }

        return ArrayKey::list_of_maps($findings);
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array{block: array<array-key, mixed>, path: string, name: string, attrs: array<array-key, mixed>, pattern: string, pattern_instance: string}> $flat
     * @return list<array<string, mixed>>
     */
    private function evaluate(array $rule, array $flat): array {
        $config = ArrayKey::as_map($rule['config'] ?? null);
        $type = (string) $rule['type'];

        return match ($type) {
            'blocks.disallow' => $this->disallowed_blocks($rule, $flat, $this->names($config)),
            'blocks.require' => $this->required_blocks($rule, $flat, $this->names($config)),
            'blocks.count' => $this->block_count($rule, $flat, $config),
            'headings.single_h1' => $this->single_h1($rule, $flat),
            'headings.no_skips' => $this->heading_skips($rule, $flat),
            'anchors.unique' => $this->unique_anchors($rule, $flat),
            'attributes.require' => $this->required_attributes($rule, $flat, $config),
            'patterns.max' => $this->pattern_limit($rule, $flat, $config),
            'patterns.require_blocks' => $this->pattern_required_blocks($rule, $flat, $config),
            'tokens.require_presets' => $this->preset_tokens($rule, $flat, $config),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     */
    private function applies(array $rule, array $context): bool {
        $scope = ArrayKey::list_of_strings($rule['scope'] ?? null);

        if ([] === $scope || in_array('all', $scope, true)) {
            return true;
        }

        $candidates = array_values(array_filter([
            sanitize_key((string) ($context['work_type'] ?? '')),
            sanitize_key((string) ($context['operation'] ?? '')),
            sanitize_key((string) ($context['post_type'] ?? '')),
            sanitize_key((string) ($context['phase'] ?? '')),
        ]));

        return [] !== array_intersect($scope, $candidates);
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function names(array $config): array {
        $names = ArrayKey::list_of_strings($config['names'] ?? null);
        $single = sanitize_text_field((string) ($config['name'] ?? ''));

        if ('' !== $single) {
            $names[] = $single;
        }

        return array_values(array_unique(array_filter($names)));
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $flat
     * @param list<string> $names
     * @return list<array<string, mixed>>
     */
    private function disallowed_blocks(array $rule, array $flat, array $names): array {
        $findings = [];

        foreach ($flat as $row) {
            if (!in_array((string) $row['name'], $names, true)) {
                continue;
            }

            $findings[] = $this->finding($rule, (string) $row['path'], ['actual' => $row['name']]);
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $flat
     * @param list<string> $names
     * @return list<array<string, mixed>>
     */
    private function required_blocks(array $rule, array $flat, array $names): array {
        $present = array_fill_keys(array_map(static fn(array $row): string => (string) $row['name'], $flat), true);
        $findings = [];

        foreach ($names as $name) {
            if (array_key_exists($name, $present)) {
                continue;
            }

            $findings[] = $this->finding($rule, '', ['expected' => $name, 'actual' => 'missing']);
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $flat
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function block_count(array $rule, array $flat, array $config): array {
        $name = sanitize_text_field((string) ($config['name'] ?? ''));
        $count = count(array_filter($flat, static fn(array $row): bool => $name === (string) $row['name']));
        $minimum = max(0, (int) ($config['min'] ?? 0));
        $maximum = max(0, (int) ($config['max'] ?? PHP_INT_MAX));

        return $count < $minimum || $count > $maximum ? [$this->finding($rule, '', [
                'expected' => "{$minimum}–{$maximum}",
                'actual' => $count,
            ])] : [];
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $flat
     * @return list<array<string, mixed>>
     */
    private function single_h1(array $rule, array $flat): array {
        $h1 = array_values(array_filter(
            $flat,
            static fn(array $row): bool => in_array((string) $row['name'], ['core/post-title', 'core/heading'], true)
            && 1 === (int) ($row['attrs']['level'] ?? 2),
        ));

        return count($h1) > 1 ? [$this->finding($rule, (string) ($h1[1]['path'] ?? ''), [
                'expected' => 1,
                'actual' => count($h1),
            ])] : [];
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $flat
     * @return list<array<string, mixed>>
     */
    private function heading_skips(array $rule, array $flat): array {
        $previous = 0;

        foreach ($flat as $row) {
            if ('core/heading' !== (string) $row['name']) {
                continue;
            }

            $level = (int) ($row['attrs']['level'] ?? 2);

            if ($previous > 0 && $level > ($previous + 1)) {
                return [$this->finding($rule, (string) $row['path'], [
                    'expected' => $previous + 1,
                    'actual' => $level,
                ])];
            }

            $previous = $level;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $flat
     * @return list<array<string, mixed>>
     */
    private function unique_anchors(array $rule, array $flat): array {
        $seen = [];

        foreach ($flat as $row) {
            $anchor = sanitize_title((string) ($row['attrs']['anchor'] ?? ''));

            if ('' === $anchor) {
                continue;
            }

            if (array_key_exists($anchor, $seen)) {
                return [$this->finding($rule, (string) $row['path'], ['expected' => 'unique', 'actual' => $anchor])];
            }

            $seen[$anchor] = true;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $flat
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function required_attributes(array $rule, array $flat, array $config): array {
        $name = sanitize_text_field((string) ($config['block'] ?? ''));
        $attributes = ArrayKey::list_of_strings($config['attributes'] ?? null);
        $findings = [];

        foreach ($flat as $row) {
            if ($name !== (string) $row['name']) {
                continue;
            }

            $row_attrs = ArrayKey::as_map($row['attrs'] ?? null);

            foreach ($attributes as $attribute) {
                if (array_key_exists($attribute, $row_attrs)) {
                    continue;
                }

                $findings[] = $this->finding($rule, (string) $row['path'] . '.attrs.' . sanitize_key($attribute), [
                    'expected' => $attribute,
                    'actual' => 'missing',
                ]);
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $flat
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function pattern_limit(array $rule, array $flat, array $config): array {
        $pattern = sanitize_text_field((string) ($config['pattern'] ?? ''));
        $maximum = max(0, (int) ($config['max'] ?? 1));
        $count = $this->pattern_count($flat, $pattern);

        return $count > $maximum ? [$this->finding($rule, '', ['expected' => $maximum, 'actual' => $count])] : [];
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $flat
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function pattern_required_blocks(array $rule, array $flat, array $config): array {
        $pattern = sanitize_text_field((string) ($config['pattern'] ?? ''));

        if (!array_filter($flat, static fn(array $row): bool => $pattern === (string) $row['pattern'])) {
            return [];
        }

        return $this->required_blocks($rule, $flat, ArrayKey::list_of_strings($config['blocks'] ?? null));
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $flat
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function preset_tokens(array $rule, array $flat, array $config): array {
        $domains = ArrayKey::list_of_strings($config['domains'] ?? null);
        $findings = [];
        $known = $this->registered_preset_slugs();

        foreach ($flat as $row) {
            foreach ($this->hardcoded_token_values(ArrayKey::as_map($row['attrs'] ?? null), $domains) as $value) {
                $findings[] = $this->finding($rule, (string) $row['path'], [
                    'expected' => 'registered preset',
                    'actual' => $value,
                ]);
            }

            foreach ($this->referenced_presets(ArrayKey::as_map($row['attrs'] ?? null)) as $reference) {
                [$domain, $slug, $path] = $reference;

                if (!isset($known[$domain]) || in_array($slug, $known[$domain], true)) {
                    continue;
                }

                $findings[] = $this->finding($rule, (string) $row['path'] . '.attrs.' . $path, [
                    'code' => 'unknown-design-preset',
                    'expected' => 'registered ' . $domain . ' preset',
                    'actual' => $slug,
                ]);
            }
        }

        return $findings;
    }

    /**
     * Count materializations, rather than every root block stamped by one materialization.
     *
     * Older content has no instance identifier, so it is conservatively treated as one
     * occurrence per pattern instead of generating false blocking findings.
     *
     * @param list<array<string, mixed>> $flat
     */
    private function pattern_count(array $flat, string $pattern): int {
        $instances = [];
        $legacy_present = false;

        foreach ($flat as $row) {
            if ($pattern !== (string) ($row['pattern'] ?? '')) {
                continue;
            }

            $instance = (string) ($row['pattern_instance'] ?? '');

            if ('' === $instance) {
                $legacy_present = true;
                continue;
            }

            $instances[$instance] = true;
        }

        return count($instances) + ($legacy_present ? 1 : 0);
    }

    /**
     * @param array<string, mixed> $attrs
     * @param list<string> $domains
     * @return list<string>
     */
    private function hardcoded_token_values(array $attrs, array $domains, string $path = ''): array {
        $values = [];

        foreach ($attrs as $key => $value) {
            $next_path = '' === $path ? $key : $path . '.' . $key;

            if (is_array($value)) {
                array_push($values, ...$this->hardcoded_token_values(
                    ArrayKey::string_map($value),
                    $domains,
                    $next_path,
                ));
                continue;
            }

            if (
                !is_string($value)
                || str_contains($value, 'var:preset|')
                || str_contains($value, 'var(--wp--preset--')
            ) {
                continue;
            }

            $lower_path = strtolower($next_path);
            $hardcoded =
                in_array('color', $domains, true)
                && str_contains($lower_path, 'color')
                && (bool) preg_match('/(?:#[0-9a-f]{3,8}\b|rgba?\(|hsla?\()/i', $value)
                || in_array('spacing', $domains, true)
                && (bool) preg_match('/(?:padding|margin|blockgap)/i', $lower_path)
                && (bool) preg_match('/^-?\d+(?:\.\d+)?(?:px|r?em|vh|vw)$/i', trim($value))
                || in_array('font_size', $domains, true)
                && str_contains($lower_path, 'fontsize')
                && (bool) preg_match('/^\d+(?:\.\d+)?(?:px|r?em)$/i', trim($value))
                || in_array('radius', $domains, true)
                && str_contains($lower_path, 'radius')
                && (bool) preg_match('/^\d+(?:\.\d+)?px$/i', trim($value));

            if ($hardcoded) {
                $values[] = $next_path . '=' . $value;
            }
        }

        return $values;
    }

    /**
     * Enforce constraints already carried by structured pattern metadata.
     *
     * @param list<array<string, mixed>> $flat
     * @return list<array<string, mixed>>
     */
    private function validate_pattern_metadata(array $flat): array {
        $findings = [];
        $patterns = [];

        foreach ($flat as $row) {
            $pattern = (string) $row['pattern'];

            if ('' !== $pattern) {
                $patterns[$pattern] = true;
            }
        }

        foreach (array_keys($patterns) as $pattern) {
            $count = $this->pattern_count($flat, $pattern);
            $metadata = $this->patterns->get($pattern);
            $maximum = (int) ($metadata['max_per_document'] ?? 0);

            if ($maximum > 0 && $count > $maximum) {
                $findings[] = [
                    'severity' => 'error',
                    'code' => 'pattern-maximum-exceeded',
                    'rule_id' => 'pattern-maximum-exceeded',
                    'message' => sprintf(
                        __('Pattern %s exceeds its document limit.', 'agent-wordpress-terminal'),
                        $pattern,
                    ),
                    'block_path' => '',
                    'source' => 'Pattern metadata',
                    'suggestion' => sprintf(
                        __('Use this pattern no more than %d time(s).', 'agent-wordpress-terminal'),
                        $maximum,
                    ),
                    'pack_id' => sanitize_key((string) ($metadata['pack_id'] ?? '')),
                    'expected' => $maximum,
                    'actual' => $count,
                    'docs' => (string) ($metadata['docs'] ?? ''),
                ];
            }

            $instances = [];

            foreach ($flat as $row) {
                if ($pattern !== (string) $row['pattern']) {
                    continue;
                }

                $instance = (string) ($row['pattern_instance'] ?? '');
                $instances['' !== $instance ? $instance : 'legacy'][(string) $row['name']] = true;
            }

            foreach ($instances as $instance => $present_names) {
                foreach (ArrayKey::list_of_strings($metadata['required_blocks'] ?? null) as $required) {
                    if (array_key_exists($required, $present_names)) {
                        continue;
                    }

                    $findings[] = [
                        'severity' => 'error',
                        'code' => 'pattern-required-block-missing',
                        'rule_id' => 'pattern-required-block-missing',
                        'message' => sprintf(
                            __('Pattern %1$s requires block %2$s.', 'agent-wordpress-terminal'),
                            $pattern,
                            $required,
                        ),
                        'block_path' => $this->instance_path($flat, $pattern, $instance),
                        'source' => 'Pattern metadata',
                        'suggestion' => __('Restore the registered pattern structure.', 'agent-wordpress-terminal'),
                        'pack_id' => sanitize_key((string) ($metadata['pack_id'] ?? '')),
                        'expected' => $required,
                        'actual' => 'missing',
                        'docs' => (string) ($metadata['docs'] ?? ''),
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<int|string, mixed> $blocks
     * @return list<array{block: array<array-key, mixed>, path: string, name: string, attrs: array<array-key, mixed>, pattern: string, pattern_instance: string}>
     */
    private function flatten(
        array $blocks,
        string $parent = '',
        string $inherited_pattern = '',
        string $inherited_instance = '',
    ): array {
        $flat = [];

        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $path = '' === $parent ? (string) $index : $parent . '.' . $index;
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $metadata = is_array($attrs['metadata'] ?? null) ? $attrs['metadata'] : [];
            $pattern = sanitize_text_field((string) ($metadata['patternName'] ?? $inherited_pattern));
            $instance = sanitize_text_field((string) ($metadata['patternInstance'] ?? $inherited_instance));
            $flat[] = [
                'block' => $block,
                'path' => $path,
                'name' => (string) ($block['blockName'] ?? ''),
                'attrs' => $attrs,
                'pattern' => $pattern,
                'pattern_instance' => $instance,
            ];
            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            array_push($flat, ...$this->flatten($inner, $path, $pattern, $instance));
        }

        return $flat;
    }

    /**
     * Theme-derived preset enforcement when a pack does not already declare
     * tokens.require_presets. Only domains with theme/custom slugs are checked.
     *
     * @return array<string, mixed>
     */
    private function theme_preset_rule(): array {
        $known = $this->registered_preset_slugs();
        $domains = [];

        if ([] !== ($known['color'] ?? [])) {
            $domains[] = 'color';
        }

        if ([] !== ($known['spacing'] ?? [])) {
            $domains[] = 'spacing';
        }

        if ([] !== ($known['font-size'] ?? [])) {
            $domains[] = 'font_size';
        }

        if ([] === $domains) {
            return [];
        }

        return [
            'id' => 'theme-require-presets',
            'type' => 'tokens.require_presets',
            'severity' => 'error',
            'scope' => ['compose', 'edit', 'template', 'new_post', 'content_update', 'template_update'],
            'config' => ['domains' => $domains],
            'message' => __(
                'New or rewritten markup must use registered theme color, spacing, and type presets instead of hardcoded values.',
                'agent-wordpress-terminal',
            ),
            'suggestion' => __(
                'Replace hardcoded visual values with the closest theme.json preset.',
                'agent-wordpress-terminal',
            ),
            'source' => 'Active theme',
            'pack_id' => '',
            'docs' => '',
        ];
    }

    /** @return array<string, list<string>> */
    private function registered_preset_slugs(): array {
        $tokens = ArrayKey::as_map(new SiteDesignContext()->resolve()['design_tokens']);
        $map = [
            'color' => 'color_palette',
            'gradient' => 'color_gradients',
            'font-size' => 'font_sizes',
            'font-family' => 'font_families',
            'spacing' => 'spacing_sizes',
        ];
        $known = [];

        foreach ($map as $domain => $key) {
            $collection = $tokens[$key] ?? [];
            $records = [];

            if (is_array($collection) && (isset($collection['theme']) || isset($collection['custom']))) {
                foreach (['theme', 'custom'] as $origin) {
                    if (!is_array($collection[$origin] ?? null)) {
                        continue;
                    }

                    array_push($records, ...$collection[$origin]);
                }
            } elseif (is_array($collection)) {
                $records = $collection;
            }

            $known[$domain] = array_values(array_filter(array_map(static fn(mixed $record): string => is_array($record)
                ? sanitize_key((string) ($record['slug'] ?? ''))
                : '', $records)));
        }

        return $known;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function referenced_presets(array $attrs, string $path = ''): array {
        $references = [];

        foreach ($attrs as $key => $value) {
            $next = '' === $path ? (string) $key : $path . '.' . $key;

            if (is_array($value)) {
                array_push($references, ...$this->referenced_presets(ArrayKey::string_map($value), $next));
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $match = [];

            if (preg_match('/var:preset\|([a-z-]+)\|([a-z0-9-]+)/i', $value, $match) === 1) {
                $references[] = [sanitize_key($match[1]), sanitize_key($match[2]), $next];
            }

            $shorthand = match ((string) $key) {
                'backgroundColor', 'textColor' => 'color',
                'gradient' => 'gradient',
                'fontSize' => 'font-size',
                'fontFamily' => 'font-family',
                default => '',
            };

            if ('' !== $shorthand && '' !== sanitize_key($value)) {
                $references[] = [$shorthand, sanitize_key($value), $next];
            }
        }

        return $references;
    }

    /** @param list<array<string, mixed>> $flat @return list<array<string, mixed>> */
    private function validate_design_components(array $flat): array {
        $components = ArrayKey::as_map(new DesignCatalog()->all()['components'] ?? null);
        /** @var array<string, list<array<string, mixed>>> $by_class */
        $by_class = [];

        foreach (ArrayKey::list_of_maps(array_values($components)) as $component) {
            foreach (ArrayKey::list_of_strings($component['class_names'] ?? null) as $class) {
                $by_class[$class][] = $component;
            }
        }

        $findings = [];

        foreach ($flat as $row) {
            $split = preg_split('/\s+/', trim((string) ($row['attrs']['className'] ?? '')));
            $classes = false === $split ? [] : $split;

            foreach ($classes as $class) {
                $matches = ArrayKey::list_of_maps($by_class[$class] ?? null);

                if (
                    [] === $matches
                    || [] !== array_filter(
                        $matches,
                        static fn(array $component): bool => (
                            (string) ($component['block'] ?? '') === (string) $row['name']
                        ),
                    )
                ) {
                    continue;
                }

                $component = $matches[0];
                $findings[] = [
                    'severity' => 'error',
                    'code' => 'design-component-block-mismatch',
                    'rule_id' => 'design-component-block-mismatch',
                    'message' => sprintf(
                        __('Design component class %1$s is not registered for block %2$s.', 'agent-wordpress-terminal'),
                        $class,
                        (string) $row['name'],
                    ),
                    'block_path' => (string) $row['path'],
                    'source' => 'Design catalog',
                    'suggestion' => sprintf(
                        __('Use the component on %s or remove its class.', 'agent-wordpress-terminal'),
                        (string) $component['block'],
                    ),
                    'pack_id' => (string) ($component['pack_id'] ?? ''),
                    'expected' => (string) $component['block'],
                    'actual' => (string) $row['name'],
                    'docs' => '',
                ];
            }
        }

        return $findings;
    }

    /** @param list<array<string, mixed>> $flat @return list<array<string, mixed>> */
    private function validate_color_pairs(array $flat): array {
        $roles = ArrayKey::as_map(new DesignCatalog()->all()['token_roles'] ?? null);
        $slug_roles = [];

        foreach ($roles as $id => $role) {
            if ('color' !== (string) ($role['domain'] ?? '')) {
                continue;
            }
            foreach (ArrayKey::list_of_strings($role['slugs'] ?? null) as $slug) {
                $slug_roles[$slug][] = $id;
            }
        }

        $findings = [];
        foreach ($flat as $row) {
            $background = sanitize_key((string) ($row['attrs']['backgroundColor'] ?? ''));
            $foreground = sanitize_key((string) ($row['attrs']['textColor'] ?? ''));
            if (
                '' === $background
                || '' === $foreground
                || !isset($slug_roles[$background], $slug_roles[$foreground])
            ) {
                continue;
            }
            $allowed = false;
            foreach ($slug_roles[$background] as $background_role) {
                if (
                    [] === array_intersect(
                        ArrayKey::list_of_strings($roles[$background_role]['pairs_with'] ?? null),
                        $slug_roles[$foreground],
                    )
                ) {
                    continue;
                }

                $allowed = true;
            }
            if (!$allowed) {
                $findings[] = [
                    'severity' => 'warning',
                    'code' => 'design-color-pair-review',
                    'rule_id' => 'design-color-pair-review',
                    'message' => __(
                        'This explicit foreground/background pair is not documented by the active design catalog.',
                        'agent-wordpress-terminal',
                    ),
                    'block_path' => (string) $row['path'],
                    'source' => 'Design catalog',
                    'suggestion' => __(
                        'Use a documented semantic color pair or verify contrast and inheritance.',
                        'agent-wordpress-terminal',
                    ),
                    'pack_id' => '',
                    'expected' => 'documented semantic pair',
                    'actual' => $foreground . ' on ' . $background,
                    'docs' => '',
                ];
            }
        }
        return $findings;
    }

    /** @param list<array<string, mixed>> $flat */
    private function instance_path(array $flat, string $pattern, string $instance): string {
        foreach ($flat as $row) {
            $row_instance = '' !== (string) $row['pattern_instance'] ? (string) $row['pattern_instance'] : 'legacy';
            if ($pattern === (string) $row['pattern'] && $instance === $row_instance) {
                return (string) $row['path'];
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function finding(array $rule, string $path, array $extra = []): array {
        return array_merge([
            'severity' => (string) $rule['severity'],
            'code' => (string) $rule['id'],
            'rule_id' => (string) $rule['id'],
            'message' => (string) $rule['message'],
            'block_path' => $path,
            'source' => (string) $rule['source'],
            'suggestion' => (string) $rule['suggestion'],
            'pack_id' => (string) $rule['pack_id'],
            'docs' => (string) $rule['docs'],
        ], $extra);
    }
}
