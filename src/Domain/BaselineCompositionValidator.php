<?php

/**
 * Theme-independent Gutenberg composition validation.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\PostCompositionValidator;

if (!defined('ABSPATH')) {
    exit();
}

final class BaselineCompositionValidator {
    /**
     * @return list<array<string, mixed>>
     */
    public function validate(string $content): array {
        if ('' === trim($content)) {
            return [];
        }

        $findings = [];
        $core_error = new PostCompositionValidator()->validate($content);

        if ($core_error instanceof \WP_Error) {
            $data = $core_error->get_error_data();
            $data = is_array($data) ? $data : [];
            $findings[] = $this->finding([
                'severity' => 'error',
                'code' => sanitize_key((string) $core_error->get_error_code()),
                'message' => $core_error->get_error_message(),
                'path' => (string) ($data['block_path'] ?? ''),
                'suggestion' => __('Correct the block markup before staging.', 'agent-wordpress-terminal'),
            ]);
        }

        $flat = $this->flatten(parse_blocks($content));
        $anchors = [];

        foreach ($flat as $row) {
            $block = $row['block'];
            $path = $row['path'];
            $name = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? \AWPT\Support\ArrayKey::string_map($block['attrs']) : [];

            if ('' === $name) {
                continue;
            }

            if (!$this->is_registered($name)) {
                $findings[] = $this->finding([
                    'severity' => 'error',
                    'code' => 'unregistered-block',
                    'message' => sprintf(
                        __('Block %s is not registered on this site.', 'agent-wordpress-terminal'),
                        $name,
                    ),
                    'path' => $path,
                    'suggestion' => __(
                        'Use a registered block or restore the plugin/theme that provides it.',
                        'agent-wordpress-terminal',
                    ),
                ], ['actual' => $name]);
            }

            foreach ($this->attribute_type_findings($name, $attrs, $path) as $finding) {
                $findings[] = $finding;
            }

            $anchor = sanitize_title((string) ($attrs['anchor'] ?? ''));

            if ('' !== $anchor) {
                if (array_key_exists($anchor, $anchors)) {
                    $findings[] = $this->finding([
                        'severity' => 'error',
                        'code' => 'duplicate-anchor',
                        'message' => sprintf(
                            __('Anchor “%s” appears more than once.', 'agent-wordpress-terminal'),
                            $anchor,
                        ),
                        'path' => $path,
                        'suggestion' => __(
                            'Use a unique anchor and update any matching in-document links.',
                            'agent-wordpress-terminal',
                        ),
                    ], ['expected' => 'unique', 'actual' => $anchor]);
                } else {
                    $anchors[$anchor] = $path;
                }
            }

            if (
                in_array($name, ['core/heading', 'core/button'], true)
                && '' === trim(wp_strip_all_tags((string) ($block['innerHTML'] ?? '')))
            ) {
                $findings[] = $this->finding([
                    'severity' => 'error',
                    'code' => 'empty-required-content',
                    'message' => sprintf(__('Block %s has no visible text.', 'agent-wordpress-terminal'), $name),
                    'path' => $path,
                    'suggestion' => __(
                        'Add meaningful visible text or remove the empty block.',
                        'agent-wordpress-terminal',
                    ),
                ]);
            }
        }

        return $this->unique($findings);
    }

    /**
     * @param array<int|string, mixed> $blocks
     * @return list<array{block: array<array-key, mixed>, path: string}>
     */
    private function flatten(array $blocks, string $parent = ''): array {
        $flat = [];

        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            $path = '' === $parent ? (string) $index : $parent . '.' . $index;
            $flat[] = ['block' => $block, 'path' => $path];
            $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            array_push($flat, ...$this->flatten($inner, $path));
        }

        return $flat;
    }

    private function is_registered(string $name): bool {
        if (!class_exists('\\WP_Block_Type_Registry')) {
            return true;
        }

        $registry = \WP_Block_Type_Registry::get_instance();

        return !method_exists($registry, 'is_registered') || $registry->is_registered($name);
    }

    /**
     * @param array<string, mixed> $attrs
     * @return list<array<string, mixed>>
     */
    private function attribute_type_findings(string $name, array $attrs, string $path): array {
        if (!class_exists('\\WP_Block_Type_Registry')) {
            return [];
        }

        $registry = \WP_Block_Type_Registry::get_instance();
        $type = method_exists($registry, 'get_registered') ? $registry->get_registered($name) : null;
        $schema = is_object($type) && is_array($type->attributes ?? null) ? $type->attributes : [];
        $findings = [];

        foreach ($attrs as $key => $value) {
            $attribute_schema = is_array($schema[$key] ?? null) ? $schema[$key] : [];
            $raw_expected = $attribute_schema['type'] ?? '';
            $expected_types = [];

            if (is_string($raw_expected) && '' !== $raw_expected) {
                $expected_types[] = $raw_expected;
            } elseif (is_array($raw_expected)) {
                foreach ($raw_expected as $candidate) {
                    if (is_string($candidate) && '' !== $candidate) {
                        $expected_types[] = $candidate;
                    }
                }
            }

            if (
                [] === $expected_types
                || array_any($expected_types, fn(string $expected): bool => $this->matches_type($value, $expected))
            ) {
                continue;
            }

            $expected = implode('|', $expected_types);

            $findings[] = $this->finding([
                'severity' => 'error',
                'code' => 'invalid-attribute-type',
                'message' => sprintf(
                    __('Attribute %1$s on %2$s has the wrong value type.', 'agent-wordpress-terminal'),
                    (string) $key,
                    $name,
                ),
                'path' => $path . '.attrs.' . sanitize_key((string) $key),
                'suggestion' => sprintf(__('Use the registered %s value type.', 'agent-wordpress-terminal'), $expected),
            ], ['expected' => $expected, 'actual' => get_debug_type($value)]);
        }

        return $findings;
    }

    private function matches_type(mixed $value, string $type): bool {
        return match ($type) {
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'null' => null === $value,
            default => true,
        };
    }

    /**
     * @param array{severity: string, code: string, message: string, path: string, suggestion: string} $data
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function finding(array $data, array $extra = []): array {
        return array_merge([
            'severity' => $data['severity'],
            'code' => $data['code'],
            'rule_id' => $data['code'],
            'message' => $data['message'],
            'block_path' => $data['path'],
            'source' => 'AWPT baseline',
            'suggestion' => $data['suggestion'],
            'pack_id' => '',
            'docs' => '',
        ], $extra);
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return list<array<string, mixed>>
     */
    private function unique(array $findings): array {
        $unique = [];

        foreach ($findings as $finding) {
            $key = (string) ($finding['code'] ?? '') . ':' . (string) ($finding['block_path'] ?? '');
            $unique[$key] = $finding;
        }

        return array_values($unique);
    }
}
