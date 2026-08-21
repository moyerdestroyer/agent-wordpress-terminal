<?php

/**
 * Developer-facing conformance report for active theme Domain Packs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

final class DomainPackHealth {
    private DomainPackRegistry $registry;

    public function __construct(?DomainPackRegistry $registry = null) {
        $this->registry = $registry ?? DomainPackRegistry::instance();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function report(): array {
        return array_map($this->pack(...), $this->registry->all());
    }

    /**
     * @param array<string, mixed> $pack
     * @return array<string, mixed>
     */
    private function pack(array $pack): array {
        $issues = [];
        $guidance = ArrayKey::list_of_maps($pack['guidance'] ?? null);
        $guidance_scopes = [];

        foreach ($guidance as $item) {
            array_push($guidance_scopes, ...ArrayKey::list_of_strings($item['applies_to'] ?? null));
        }

        $recommended_scopes = ['compose', 'edit', 'template', 'navigation', 'global_styles', 'diagnose'];
        $has_all = in_array('all', $guidance_scopes, true);

        foreach ($recommended_scopes as $scope) {
            if (!(!$has_all && !in_array($scope, $guidance_scopes, true))) {
                continue;
            }

            $issues[] = $this->issue(
                'recommendation',
                'missing-guidance-scope',
                sprintf(__('No guidance module explicitly covers %s work.', 'agent-wordpress-terminal'), $scope),
            );
        }

        $catalog = $this->catalog($pack);
        $registered = $this->registered_names((string) ($pack['patterns']['namespace'] ?? ''));
        $catalog_names = array_keys($catalog);
        $stale = array_values(array_diff($catalog_names, $registered));
        $header_only = array_values(array_diff($registered, $catalog_names));

        if ([] !== $stale) {
            $issues[] = $this->issue(
                'warning',
                'stale-pattern-metadata',
                sprintf(
                    __('%d catalog entries do not match registered patterns.', 'agent-wordpress-terminal'),
                    count($stale),
                ),
            );
        }

        if ([] !== $header_only) {
            $issues[] = $this->issue(
                'recommendation',
                'header-only-patterns',
                sprintf(
                    __('%d registered patterns have only header-derived agent metadata.', 'agent-wordpress-terminal'),
                    count($header_only),
                ),
            );
        }

        $missing_docs = [];
        $broken_references = [];
        $sparse = [];
        $duplicate_slots = [];
        $unbound_slots = [];

        foreach ($catalog as $name => $metadata) {
            $docs = sanitize_text_field((string) ($metadata['docs'] ?? ''));

            if ('' !== $docs && !$this->pack_file_exists($pack, $docs)) {
                $missing_docs[] = $name;
            }

            $references = [
                ...ArrayKey::list_of_strings($metadata['companions'] ?? null),
                ...ArrayKey::list_of_strings($metadata['composed'] ?? null),
            ];
            $relationships = ArrayKey::as_map($metadata['relationships'] ?? null);

            foreach (['alternatives', 'recommended_before', 'recommended_after', 'conflicts'] as $key) {
                array_push($references, ...ArrayKey::list_of_strings($relationships[$key] ?? null));
            }

            foreach (array_unique($references) as $reference) {
                if (!(!array_key_exists($reference, $catalog) && !in_array($reference, $registered, true))) {
                    continue;
                }

                $broken_references[] = $name . ' → ' . $reference;
            }

            if (
                [] === ArrayKey::list_of_strings($metadata['use_when'] ?? null)
                || [] === ArrayKey::list_of_strings($metadata['avoid_when'] ?? null)
            ) {
                $sparse[] = $name;
            }

            $slots = ArrayKey::list_of_maps($metadata['slots'] ?? null);
            $slot_ids = array_values(array_filter(array_map(static fn(array $slot): string => sanitize_key(
                (string) ($slot['id'] ?? ''),
            ), $slots)));

            foreach ($slots as $slot) {
                if ('' !== trim((string) ($slot['block_path'] ?? ''))) {
                    continue;
                }

                $unbound_slots[] = $name . '#' . (string) ($slot['id'] ?? 'unknown');
            }

            if (count($slot_ids) !== count(array_unique($slot_ids))) {
                $duplicate_slots[] = $name;
            }
        }

        if ([] !== $missing_docs) {
            $issues[] = $this->issue(
                'warning',
                'missing-pattern-docs',
                sprintf(
                    __('%d catalog documentation paths do not exist.', 'agent-wordpress-terminal'),
                    count($missing_docs),
                ),
            );
        }

        if ([] !== $broken_references) {
            $issues[] = $this->issue(
                'warning',
                'broken-pattern-references',
                sprintf(
                    __('%d pattern relationships point to unknown slugs.', 'agent-wordpress-terminal'),
                    count($broken_references),
                ),
            );
        }

        if ([] !== $duplicate_slots) {
            $issues[] = $this->issue(
                'warning',
                'duplicate-pattern-slots',
                sprintf(
                    __('%d patterns contain duplicate editable slot IDs.', 'agent-wordpress-terminal'),
                    count($duplicate_slots),
                ),
            );
        }

        if ([] !== $unbound_slots) {
            $issues[] = $this->issue(
                'warning',
                'unbound-pattern-slots',
                sprintf(
                    __(
                        '%d editable slots are not bound to a block path and cannot be prepared reliably.',
                        'agent-wordpress-terminal',
                    ),
                    count($unbound_slots),
                ),
            );
        }

        if ([] !== $sparse) {
            $issues[] = $this->issue(
                'recommendation',
                'sparse-pattern-contracts',
                sprintf(
                    __(
                        '%d patterns are missing use-when or avoid-when selection guidance.',
                        'agent-wordpress-terminal',
                    ),
                    count($sparse),
                ),
            );
        }

        $rules = $this->rules($pack);
        $unsupported = array_values(array_filter(
            $rules,
            static fn(array $rule): bool => !in_array(
                (string) ($rule['type'] ?? ''),
                DomainRuleRepository::SUPPORTED_TYPES,
                true,
            ),
        ));

        if ([] !== $unsupported) {
            $issues[] = $this->issue(
                'warning',
                'unsupported-rules',
                sprintf(
                    __('%d declarative rules use unsupported types.', 'agent-wordpress-terminal'),
                    count($unsupported),
                ),
            );
        }

        $declared_validators = ArrayKey::list_of_strings($pack['validators'] ?? null);
        $registered_validators = $this->registry->validators((string) $pack['id']);

        if (count($registered_validators) < count($declared_validators)) {
            $issues[] = $this->issue(
                'warning',
                'missing-validator-callback',
                __('One or more declared PHP validators did not register.', 'agent-wordpress-terminal'),
            );
        }

        $design_catalog = new DesignCatalog($this->registry);
        $design = $design_catalog->all();
        $design_diagnostics = array_values(array_filter($design_catalog->diagnostics(), static fn(array $diagnostic): bool => str_starts_with(
            $diagnostic['pointer'],
            '/' . (string) $pack['id'],
        )));

        foreach (ArrayKey::list_of_maps($pack['_diagnostics'] ?? null) as $diagnostic) {
            $issues[] = $this->issue(
                sanitize_key((string) ($diagnostic['severity'] ?? 'warning')),
                sanitize_key((string) ($diagnostic['code'] ?? 'pack-diagnostic')),
                sanitize_text_field((string) ($diagnostic['message'] ?? '')),
                sanitize_text_field((string) ($diagnostic['pointer'] ?? '')),
            );
        }

        foreach ($design_diagnostics as $diagnostic) {
            $issues[] = $this->issue(
                $diagnostic['severity'],
                $diagnostic['code'],
                $diagnostic['message'],
                $diagnostic['pointer'],
            );
        }
        $pack_id = (string) $pack['id'];
        $design_count = static fn(string $section): int => count(array_filter(
            ArrayKey::as_map($design[$section] ?? null),
            static fn(array $record): bool => $pack_id === (string) ($record['pack_id'] ?? ''),
        ));
        $design_source = array_values(array_filter(
            ArrayKey::list_of_maps($design['sources'] ?? null),
            static fn(array $source): bool => $pack_id === (string) ($source['pack_id'] ?? ''),
        ))[0] ?? [];

        return [
            'pack_id' => (string) $pack['id'],
            'schema_version' => (int) ($pack['schema_version'] ?? 1),
            'status' => [] === array_filter($issues, static fn(array $issue): bool => in_array(
                $issue['severity'],
                ['error', 'warning'],
                true,
            ))
                ? 'healthy'
                : 'attention',
            'guidance_scopes' => array_values(array_unique($guidance_scopes)),
            'pattern_coverage' => [
                'registered' => count($registered),
                'enriched' => count(array_intersect($registered, $catalog_names)),
                'header_only' => count($header_only),
                'stale' => count($stale),
                'missing_docs' => count($missing_docs),
                'broken_references' => count($broken_references),
                'sparse_contracts' => count($sparse),
                'unbound_slots' => count($unbound_slots),
            ],
            'design_coverage' => [
                'catalog_hash' => (string) ($design_source['hash'] ?? ''),
                'token_roles' => $design_count('token_roles'),
                'components' => $design_count('components'),
                'style_variations' => $design_count('style_variations'),
                'archetypes' => $design_count('archetypes'),
                'diagnostics' => count($design_diagnostics),
            ],
            'rule_count' => count($rules) - count($unsupported),
            'issues' => $issues,
        ];
    }

    /** @param array<string, mixed> $pack */
    private function pack_file_exists(array $pack, string $relative): bool {
        $root = (string) ($pack['_root'] ?? '');
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ('' === $root || '' === $relative || str_contains($relative, '..')) {
            return false;
        }

        $path = realpath(trailingslashit($root) . $relative);

        return is_string($path) && str_starts_with($path, trailingslashit($root)) && is_file($path);
    }

    /**
     * @param array<string, mixed> $pack
     * @return array<string, array<string, mixed>>
     */
    private function catalog(array $pack): array {
        $config = ArrayKey::as_map($pack['patterns'] ?? null);
        $path = (string) ($config['catalog'] ?? '');
        $catalog_pack = $pack;
        $catalog_pack['_root'] = (string) ($config['_root'] ?? $pack['_root'] ?? '');
        $decoded = json_decode($this->registry->read_pack_file($catalog_pack, $path, 524_288), true);

        $catalog = [];

        foreach (ArrayKey::as_map(ArrayKey::as_map($decoded)['patterns'] ?? null) as $name => $metadata) {
            if (!is_array($metadata)) {
                continue;
            }

            $catalog[$name] = ArrayKey::string_map($metadata);
        }

        return $catalog;
    }

    /**
     * @param array<string, mixed> $pack
     * @return list<array<string, mixed>>
     */
    private function rules(array $pack): array {
        $config = ArrayKey::as_map($pack['rules'] ?? null);
        $rule_pack = $pack;
        $rule_pack['_root'] = (string) ($config['_root'] ?? $pack['_root'] ?? '');
        $decoded = json_decode(
            $this->registry->read_pack_file($rule_pack, (string) ($config['path'] ?? ''), 262_144),
            true,
        );

        return ArrayKey::list_of_maps(ArrayKey::as_map($decoded)['rules'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function registered_names(string $namespace): array {
        if (!class_exists('\\WP_Block_Patterns_Registry')) {
            return [];
        }

        $patterns = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
        $prefix = '' !== $namespace ? trailingslashit($namespace) : '';
        $names = [];

        foreach ($patterns as $pattern) {
            $name = (string) ($pattern['name'] ?? '');

            if ('' !== $name && ('' === $prefix || str_starts_with($name, $prefix))) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array{severity: string, code: string, message: string, pointer?: string}
     */
    private function issue(string $severity, string $code, string $message, string $pointer = ''): array {
        $issue = [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
        ];

        if ('' !== $pointer) {
            $issue['pointer'] = $pointer;
        }

        return $issue;
    }
}
