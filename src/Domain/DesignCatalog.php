<?php

/**
 * Typed, fault-tolerant access to optional Domain Pack design catalogs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

final class DesignCatalog {
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /** @var list<array{severity: string, code: string, pointer: string, message: string}> */
    private array $diagnostics = [];

    private DomainPackRegistry $registry;

    public function __construct(?DomainPackRegistry $registry = null) {
        $this->registry = $registry ?? DomainPackRegistry::instance();
    }

    /** @return array<string, mixed> */
    public function all(): array {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $result = [
            'schema_version' => 1,
            'token_roles' => [],
            'components' => [],
            'style_variations' => [],
            'archetypes' => [],
            'guidance_sets' => [],
            'sources' => [],
        ];

        foreach ($this->registry->active() as $pack) {
            $config = ArrayKey::as_map($pack['design'] ?? null);
            $path = (string) ($config['catalog'] ?? '');

            if ('' === $path) {
                continue;
            }

            $catalog_pack = $pack;
            $catalog_pack['_root'] = (string) ($config['_root'] ?? $pack['_root'] ?? '');
            $raw = $this->registry->read_pack_file($catalog_pack, $path, 524_288);
            $decoded = '' !== $raw ? json_decode($raw, true) : null;
            $pack_id = (string) ($pack['id'] ?? '');

            if (!is_array($decoded)) {
                $this->warn(
                    $pack_id,
                    '',
                    'invalid-design-catalog',
                    __('The optional design catalog is unreadable or invalid JSON.', 'agent-wordpress-terminal'),
                );
                continue;
            }

            if (1 !== (int) ($decoded['schema_version'] ?? 0)) {
                $this->warn(
                    $pack_id,
                    '/schema_version',
                    'unsupported-design-schema',
                    __('The optional design catalog uses an unsupported schema version.', 'agent-wordpress-terminal'),
                );
                continue;
            }

            foreach (['token_roles', 'components', 'style_variations', 'archetypes'] as $section) {
                foreach (ArrayKey::as_map($decoded[$section] ?? null) as $id => $record) {
                    $clean = $this->sanitize_record($section, $id, $record, $pack_id);

                    if ([] !== $clean) {
                        $clean['pack_id'] = $pack_id;
                        $result[$section][sanitize_key($id)] = $clean;
                    }
                }
            }

            foreach (ArrayKey::as_map($decoded['guidance_sets'] ?? null) as $scope => $ids) {
                $clean = $this->strings($ids);

                if ([] === $clean) {
                    $this->warn(
                        $pack_id,
                        '/guidance_sets/' . $scope,
                        'invalid-design-record',
                        __('An empty or malformed guidance set was dropped.', 'agent-wordpress-terminal'),
                    );
                    continue;
                }

                $result['guidance_sets'][sanitize_key($scope)] = $clean;
            }

            $result['sources'][] = [
                'pack_id' => $pack_id,
                'pack_version' => (string) ($pack['version'] ?? ''),
                'catalog' => $path,
                'hash' => hash('sha256', $raw),
            ];
        }

        $this->cache = $result;
        return $result;
    }

    /** @return list<array{severity: string, code: string, pointer: string, message: string}> */
    public function diagnostics(): array {
        $this->all();
        return $this->diagnostics;
    }

    public function hash(): string {
        $data = $this->all();
        unset($data['sources']);
        return hash('sha256', (string) wp_json_encode($data));
    }

    /** @return list<string> */
    public function guidance_for(string $scope): array {
        $sets = ArrayKey::as_map($this->all()['guidance_sets'] ?? null);
        return array_values(array_unique([
            ...$this->strings($sets['all'] ?? null),
            ...$this->strings($sets[$scope] ?? null),
        ]));
    }

    /** @return array<string, mixed> */
    private function sanitize_record(string $section, string $id, mixed $record, string $pack_id): array {
        $record = ArrayKey::as_map($record);
        $pointer = '/' . $section . '/' . $id;

        if ('' === sanitize_key($id) || [] === $record) {
            $this->warn(
                $pack_id,
                $pointer,
                'invalid-design-record',
                __('A malformed design record was dropped.', 'agent-wordpress-terminal'),
            );
            return [];
        }

        if ('token_roles' === $section) {
            $domain = sanitize_key((string) ($record['domain'] ?? ''));
            $slugs = $this->strings($record['slugs'] ?? null);

            if (!in_array($domain, ['color', 'typography', 'spacing', 'border', 'shadow'], true) || [] === $slugs) {
                $this->warn(
                    $pack_id,
                    $pointer,
                    'invalid-design-record',
                    __('A token role without a supported domain and slugs was dropped.', 'agent-wordpress-terminal'),
                );
                return [];
            }

            return [
                'domain' => $domain,
                'slugs' => $slugs,
                'pairs_with' => $this->strings($record['pairs_with'] ?? null),
                'description' => sanitize_textarea_field((string) ($record['description'] ?? '')),
                'use_when' => $this->strings($record['use_when'] ?? null),
                'avoid_when' => $this->strings($record['avoid_when'] ?? null),
            ];
        }

        if ('components' === $section) {
            $block = sanitize_text_field((string) ($record['block'] ?? ''));
            $kind = sanitize_key((string) ($record['kind'] ?? ''));
            $name = sanitize_key((string) ($record['name'] ?? ''));

            if (
                '' === $block
                || !str_contains($block, '/')
                || !in_array($kind, ['style', 'variation'], true)
                || '' === $name
            ) {
                $this->warn(
                    $pack_id,
                    $pointer,
                    'invalid-design-record',
                    __('A component without a valid block, kind, and name was dropped.', 'agent-wordpress-terminal'),
                );
                return [];
            }

            return [
                'label' => sanitize_text_field((string) ($record['label'] ?? $id)),
                'block' => $block,
                'kind' => $kind,
                'name' => $name,
                'class_names' => $this->strings($record['class_names'] ?? null),
                'description' => sanitize_textarea_field((string) ($record['description'] ?? '')),
                'use_when' => $this->strings($record['use_when'] ?? null),
                'avoid_when' => $this->strings($record['avoid_when'] ?? null),
            ];
        }

        if ('style_variations' === $section) {
            $slug = sanitize_key((string) ($record['slug'] ?? ''));

            if ('' === $slug) {
                $this->warn(
                    $pack_id,
                    $pointer,
                    'invalid-design-record',
                    __('A style variation without a slug was dropped.', 'agent-wordpress-terminal'),
                );
                return [];
            }

            return [
                'label' => sanitize_text_field((string) ($record['label'] ?? $id)),
                'slug' => $slug,
                'description' => sanitize_textarea_field((string) ($record['description'] ?? '')),
            ];
        }

        $label = sanitize_text_field((string) ($record['label'] ?? ''));

        if ('' === $label) {
            $this->warn(
                $pack_id,
                $pointer,
                'invalid-design-record',
                __('An archetype without a label was dropped.', 'agent-wordpress-terminal'),
            );
            return [];
        }

        return [
            'label' => $label,
            'description' => sanitize_textarea_field((string) ($record['description'] ?? '')),
            'pattern_roles' => $this->strings($record['pattern_roles'] ?? null),
            'guidance' => $this->strings($record['guidance'] ?? null),
        ];
    }

    /** @return list<string> */
    private function strings(mixed $value): array {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => sanitize_text_field(is_scalar($item) ? (string) $item : ''),
            is_array($value) ? $value : [],
        ))));
    }

    private function warn(string $pack_id, string $pointer, string $code, string $message): void {
        $this->diagnostics[] = [
            'severity' => 'warning',
            'code' => $code,
            'pointer' => '/' . $pack_id . $pointer,
            'message' => $message,
        ];
    }
}
