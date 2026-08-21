<?php

/**
 * Structured pattern semantics supplied by active domain packs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

final class PatternMetadataCatalog {
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    private DomainPackRegistry $registry;

    public function __construct(?DomainPackRegistry $registry = null) {
        $this->registry = $registry ?? DomainPackRegistry::instance();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $items = [];

        foreach ($this->registry->active() as $pack) {
            $header_patterns = $this->header_metadata($pack);
            $pattern_config = ArrayKey::as_map($pack['patterns'] ?? null);
            $path = (string) ($pattern_config['catalog'] ?? '');
            $catalog_pack = $pack;
            $catalog_pack['_root'] = (string) ($pattern_config['_root'] ?? $pack['_root'] ?? '');

            $decoded = '' !== $path
                ? json_decode($this->registry->read_pack_file($catalog_pack, $path, 524_288), true)
                : [];
            $decoded_map = ArrayKey::as_map($decoded);
            $patterns = ArrayKey::as_map($decoded_map['patterns'] ?? null);
            $patterns = array_replace_recursive($header_patterns, $patterns);

            foreach ($patterns as $name => $metadata) {
                if (!is_array($metadata) || !str_contains($name, '/')) {
                    continue;
                }

                $metadata['pack_id'] = (string) $pack['id'];
                $metadata['pack_version'] = (string) $pack['version'];
                $metadata['name'] = sanitize_text_field($name);
                $items[$metadata['name']] = $this->sanitize(ArrayKey::string_map($metadata));
            }
        }

        $this->cache = $items;

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $pattern_name): array {
        return $this->all()[sanitize_text_field($pattern_name)] ?? [];
    }

    public function hash(): string {
        $items = $this->all();
        ksort($items);

        return hash('sha256', (string) wp_json_encode($items));
    }

    /** @return array{count: int, namespaces: list<string>, roles: list<string>, hash: string} */
    public function index(): array {
        $namespaces = [];
        $roles = [];

        foreach ($this->all() as $name => $metadata) {
            $parts = explode('/', $name, 2);
            $namespace = sanitize_key($parts[0]);
            $role = sanitize_key((string) ($metadata['role'] ?? ''));

            if ('' !== $namespace) {
                $namespaces[] = $namespace;
            }
            if ('' !== $role) {
                $roles[] = $role;
            }
        }

        $namespaces = array_values(array_unique($namespaces));
        $roles = array_values(array_unique($roles));
        sort($namespaces);
        sort($roles);

        return [
            'count' => count($this->all()),
            'namespaces' => $namespaces,
            'roles' => $roles,
            'hash' => $this->hash(),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function sanitize(array $metadata): array {
        $clean = [
            'name' => sanitize_text_field((string) ($metadata['name'] ?? '')),
            'pack_id' => sanitize_key((string) ($metadata['pack_id'] ?? '')),
            'pack_version' => sanitize_text_field((string) ($metadata['pack_version'] ?? '')),
            'role' => sanitize_key((string) ($metadata['role'] ?? '')),
            'summary' => sanitize_textarea_field((string) ($metadata['summary'] ?? '')),
            'dynamic_content' => filter_var($metadata['dynamic_content'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'max_per_document' => max(0, (int) ($metadata['max_per_document'] ?? 0)),
            'docs' => sanitize_text_field((string) ($metadata['docs'] ?? '')),
        ];

        foreach ([
            'intents',
            'use_when',
            'avoid_when',
            'post_types',
            'required_blocks',
            'companions',
            'composed',
            'search_terms',
        ] as $key) {
            $values = is_array($metadata[$key] ?? null) ? $metadata[$key] : [];
            $clean[$key] = array_values(array_filter(array_map(static fn(mixed $value): string => sanitize_text_field(
                is_scalar($value) ? (string) $value : '',
            ), $values)));
        }

        $clean['content_rules'] = is_array($metadata['content_rules'] ?? null)
            ? $this->sanitize_rule_map($metadata['content_rules'])
            : [];
        $clean['validation'] = is_array($metadata['validation'] ?? null)
            ? $this->sanitize_rule_map($metadata['validation'])
            : [];
        $clean['placement'] = $this->sanitize_named_map($metadata['placement'] ?? null, [
            'regions' => 'list',
            'recommended_position' => 'text',
            'conflicts_with_roles' => 'list',
        ]);
        $clean['relationships'] = $this->sanitize_named_map($metadata['relationships'] ?? null, [
            'alternatives' => 'list',
            'recommended_before' => 'list',
            'recommended_after' => 'list',
            'conflicts' => 'list',
        ]);
        $clean['design'] = $this->sanitize_named_map($metadata['design'] ?? null, [
            'width' => 'text',
            'background_roles' => 'list',
            'foreground_roles' => 'list',
            'spacing_register' => 'text',
            'block_styles' => 'list',
            'notes' => 'list',
        ]);
        $clean['dependencies'] = $this->sanitize_named_map($metadata['dependencies'] ?? null, [
            'block_names' => 'list',
            'custom_block_names' => 'list',
        ]);
        $clean['preview'] = $this->sanitize_named_map($metadata['preview'] ?? null, [
            'image' => 'path',
            'alt' => 'text',
        ]);
        $clean['slots'] = $this->sanitize_slots($metadata['slots'] ?? null);

        return $clean;
    }

    /**
     * @param array<string, string> $shape
     * @return array<string, mixed>
     */
    private function sanitize_named_map(mixed $value, array $shape): array {
        $source = ArrayKey::as_map($value);
        $clean = [];

        foreach ($shape as $key => $type) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            if ('list' === $type) {
                $clean[$key] = array_values(array_filter(array_map(
                    static fn(mixed $item): string => sanitize_text_field(is_scalar($item) ? (string) $item : ''),
                    is_array($source[$key]) ? $source[$key] : [],
                )));
            } elseif ('path' === $type) {
                $path = ltrim(str_replace('\\', '/', sanitize_text_field((string) $source[$key])), '/');
                $clean[$key] = str_contains($path, '..') ? '' : $path;
            } else {
                $clean[$key] = sanitize_textarea_field((string) $source[$key]);
            }
        }

        return $clean;
    }

    /** @return list<array<string, mixed>> */
    private function sanitize_slots(mixed $value): array {
        $allowed_types = ['text', 'rich_text', 'link', 'image', 'query', 'number', 'date', 'taxonomy'];
        $slots = [];

        foreach (ArrayKey::list_of_maps($value) as $slot) {
            $id = sanitize_key((string) ($slot['id'] ?? ''));
            $type = sanitize_key((string) ($slot['type'] ?? ''));

            if ('' === $id || !in_array($type, $allowed_types, true)) {
                continue;
            }

            $slots[] = [
                'id' => $id,
                'label' => sanitize_text_field((string) ($slot['label'] ?? $id)),
                'type' => $type,
                'block_path' => sanitize_text_field((string) ($slot['block_path'] ?? '')),
                'required' => filter_var($slot['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'max_characters' => max(0, (int) ($slot['max_characters'] ?? 0)),
                'allowed_blocks' => array_values(array_filter(array_map(
                    static fn(mixed $item): string => sanitize_text_field(is_scalar($item) ? (string) $item : ''),
                    is_array($slot['allowed_blocks'] ?? null) ? $slot['allowed_blocks'] : [],
                ))),
                'requires_verified_value' => filter_var(
                    $slot['requires_verified_value'] ?? false,
                    FILTER_VALIDATE_BOOLEAN,
                ),
                'description' => sanitize_textarea_field((string) ($slot['description'] ?? '')),
            ];
        }

        return $slots;
    }

    /**
     * @param array<string, mixed> $pack
     * @return array<string, array<string, mixed>>
     */
    private function header_metadata(array $pack): array {
        $root = (string) ($pack['_root'] ?? '');
        $files = '' !== $root ? glob(trailingslashit($root) . 'patterns/*.php') : false;
        $items = [];

        foreach (is_array($files) ? array_slice($files, 0, 200) : [] as $file) {
            if (!is_readable($file)) {
                continue;
            }

            $headers = get_file_data($file, [
                'slug' => 'Slug',
                'description' => 'Description',
                'guidelines' => 'Guidelines',
                'docs' => 'Docs',
                'keywords' => 'Keywords',
            ]);
            $slug = sanitize_text_field($headers['slug'] ?? '');

            if ('' === $slug) {
                continue;
            }

            $items[$slug] = [
                'summary' => sanitize_textarea_field($headers['guidelines'] ?? $headers['description'] ?? ''),
                'docs' => sanitize_text_field($headers['docs'] ?? ''),
                'search_terms' => array_values(array_filter(array_map('trim', explode(
                    ',',
                    $headers['keywords'] ?? '',
                )))),
            ];
        }

        return $items;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private function sanitize_rule_map(array $values): array {
        $clean = [];

        foreach ($values as $key => $value) {
            $key = sanitize_key((string) $key);

            if ('' === $key) {
                continue;
            }

            if (is_scalar($value)) {
                $clean[$key] = sanitize_textarea_field((string) $value);
            } elseif (is_array($value)) {
                $clean[$key] = array_values(array_map(static fn(mixed $item): string => sanitize_textarea_field(
                    is_scalar($item) ? (string) $item : '',
                ), $value));
            }
        }

        return $clean;
    }
}
