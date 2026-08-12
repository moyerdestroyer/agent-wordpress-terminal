<?php

/**
 * Evaluates the bounded Domain Pack v2 rule vocabulary.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;

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

        foreach ($this->rules->all() as $rule) {
            if (!$this->applies($rule, $context)) {
                continue;
            }

            array_push($findings, ...$this->evaluate($rule, $flat));
        }

        return $findings;
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

        foreach ($flat as $row) {
            foreach ($this->hardcoded_token_values(ArrayKey::as_map($row['attrs'] ?? null), $domains) as $value) {
                $findings[] = $this->finding($rule, (string) $row['path'], [
                    'expected' => 'registered preset',
                    'actual' => $value,
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
        $present_names = array_fill_keys(
            array_map(static fn(array $row): string => (string) $row['name'], $flat),
            true,
        );
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
                    'block_path' => '',
                    'source' => 'Pattern metadata',
                    'suggestion' => __('Restore the registered pattern structure.', 'agent-wordpress-terminal'),
                    'pack_id' => sanitize_key((string) ($metadata['pack_id'] ?? '')),
                    'expected' => $required,
                    'actual' => 'missing',
                    'docs' => (string) ($metadata['docs'] ?? ''),
                ];
            }
        }

        return $findings;
    }

    /**
     * @param array<int|string, mixed> $blocks
     * @return list<array{block: array<array-key, mixed>, path: string, name: string, attrs: array<array-key, mixed>, pattern: string, pattern_instance: string}>
     */
    private function flatten(array $blocks, string $parent = ''): array {
        $flat = [];

        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $path = '' === $parent ? (string) $index : $parent . '.' . $index;
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $metadata = is_array($attrs['metadata'] ?? null) ? $attrs['metadata'] : [];
            $flat[] = [
                'block' => $block,
                'path' => $path,
                'name' => (string) ($block['blockName'] ?? ''),
                'attrs' => $attrs,
                'pattern' => sanitize_text_field((string) ($metadata['patternName'] ?? '')),
                'pattern_instance' => sanitize_text_field((string) ($metadata['patternInstance'] ?? '')),
            ];
            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            array_push($flat, ...$this->flatten($inner, $path));
        }

        return $flat;
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
