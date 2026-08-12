<?php

/**
 * Theme-provided domain pack discovery and extension registration.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Loads declarative theme packs and stores their trusted PHP extension callbacks.
 */
final class DomainPackRegistry {
    public const MANIFEST_FILE = 'awpt-domain.json';

    public const SCHEMA_VERSION = 2;

    /** @var list<int> */
    private const SUPPORTED_SCHEMA_VERSIONS = [1, 2];

    private static ?self $instance = null;

    /** @var array<string, array<string, mixed>> */
    private array $packs = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $validators = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $recommenders = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $materializers = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $proposal_operations = [];

    private bool $loaded = false;

    public static function instance(): self {
        self::$instance ??= new self();

        return self::$instance;
    }

    public function boot(): void {
        add_action('after_setup_theme', [$this, 'load_active_theme_packs'], 20);
    }

    /**
     * Discover parent then child manifests so child records can override parent records.
     */
    public function load_active_theme_packs(): void {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (!function_exists('get_template_directory') || !function_exists('get_stylesheet_directory')) {
            return;
        }

        $template = get_template_directory();
        $stylesheet = get_stylesheet_directory();

        if ('' !== $template) {
            $this->load_manifest($template, 'parent');
        }

        if ('' !== $stylesheet && $stylesheet !== $template) {
            $this->load_manifest($stylesheet, 'child');
        }

        /**
         * Fires after declarative packs are loaded so trusted theme/plugin PHP can
         * attach validators, recommenders, materializers, and proposal operations.
         */
        if (function_exists('do_action')) {
            do_action('awpt_domain_packs_init', $this);
        }
    }

    /**
     * Load one manifest from a known theme root.
     *
     * @return array<string, mixed>|\WP_Error|null
     */
    public function load_manifest(string $theme_root, string $source = 'theme'): array|\WP_Error|null {
        $manifest_path = trailingslashit($theme_root) . self::MANIFEST_FILE;

        if (!is_readable($manifest_path)) {
            return null;
        }

        $real_root = realpath($theme_root);
        $real_manifest = realpath($manifest_path);

        if (
            !is_string($real_root)
            || !is_string($real_manifest)
            || !str_starts_with($real_manifest, trailingslashit($real_root))
        ) {
            return new \WP_Error('awpt_domain_manifest_path', __(
                'The domain manifest must remain inside its theme.',
                'agent-wordpress-terminal',
            ));
        }

        $size = filesize($real_manifest);

        if (!is_int($size) || $size <= 0 || $size > 262_144) {
            return new \WP_Error('awpt_domain_manifest_size', __(
                'The domain manifest is empty or exceeds 256 KB.',
                'agent-wordpress-terminal',
            ));
        }

        $raw = file_get_contents($real_manifest);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            return new \WP_Error('awpt_domain_manifest_json', __(
                'The domain manifest is not valid JSON.',
                'agent-wordpress-terminal',
            ));
        }

        $validated = $this->validate_manifest(ArrayKey::string_map($decoded), $real_root);

        if (is_wp_error($validated)) {
            return $validated;
        }

        $id = (string) $validated['id'];
        $validated['_root'] = $real_root;
        $validated['_manifest'] = $real_manifest;
        $validated['_source'] = sanitize_key($source);

        if (isset($this->packs[$id])) {
            $validated = $this->merge_pack($this->packs[$id], $validated);
        }

        $this->packs[$id] = $validated;

        return $validated;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(bool $include_disabled = true): array {
        $this->ensure_loaded();
        $disabled = $this->disabled_ids();
        $packs = [];

        foreach ($this->packs as $pack) {
            $pack['enabled'] = !in_array((string) $pack['id'], $disabled, true);

            if ($include_disabled || $pack['enabled']) {
                $packs[] = $pack;
            }
        }

        return $packs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function active(): array {
        return $this->all(false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $pack_id): ?array {
        $this->ensure_loaded();

        return $this->packs[sanitize_key($pack_id)] ?? null;
    }

    /**
     * Public, bounded pack status for REST and prompt observability.
     *
     * @return list<array<string, mixed>>
     */
    public function status(): array {
        return array_map(static fn(array $pack): array => [
            'id' => (string) $pack['id'],
            'label' => (string) $pack['label'],
            'version' => (string) $pack['version'],
            'schema_version' => (int) ($pack['schema_version'] ?? 1),
            'source' => (string) ($pack['_source'] ?? 'theme'),
            'enabled' => (bool) ($pack['enabled'] ?? false),
            'guidance_count' => count(is_array($pack['guidance'] ?? null) ? $pack['guidance'] : []),
            'pattern_catalog' => (string) ($pack['patterns']['catalog'] ?? ''),
            'rules' => (string) ($pack['rules']['path'] ?? ''),
        ], $this->all());
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    public function sanitize_disabled_ids(array $ids): array {
        $known = array_fill_keys(array_keys($this->packs), true);
        $clean = [];

        foreach ($ids as $id) {
            $id = sanitize_key($id);

            if ('' !== $id && isset($known[$id])) {
                $clean[] = $id;
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * @param array<string, mixed> $args
     */
    public function register_validator(string $pack_id, string $validator_id, array $args): void {
        $this->register_extension($this->validators, $pack_id, $validator_id, $args, 'validators');
    }

    /**
     * @param array<string, mixed> $args
     */
    public function register_recommender(string $pack_id, string $recommender_id, array $args): void {
        $this->register_extension($this->recommenders, $pack_id, $recommender_id, $args, 'recommenders');
    }

    /**
     * @param array<string, mixed> $args
     */
    public function register_materializer(string $pack_id, string $materializer_id, array $args): void {
        $this->register_extension($this->materializers, $pack_id, $materializer_id, $args, 'materializers');
    }

    /**
     * @param array<string, mixed> $args
     */
    public function register_proposal_operation(string $pack_id, string $operation_id, array $args): void {
        $required = [
            'ability_name',
            'input_schema',
            'permission_callback',
            'sanitize_callback',
            'stage_callback',
            'apply_callback',
        ];

        foreach ($required as $key) {
            if (!array_key_exists($key, $args)) {
                return;
            }
        }

        $pack_id = sanitize_key($pack_id);
        $operation_id = sanitize_key($operation_id);
        $ability_name = sanitize_text_field((string) ($args['ability_name'] ?? ''));

        if (
            '' === $pack_id
            || '' === $operation_id
            || !str_contains($ability_name, '/')
            || !$this->is_declared($pack_id, 'proposal_operations', $operation_id)
            || !is_array($args['input_schema'])
        ) {
            return;
        }

        foreach (['permission_callback', 'sanitize_callback', 'stage_callback', 'apply_callback'] as $callback) {
            if (!is_callable($args[$callback])) {
                return;
            }
        }

        foreach (['preview_callback', 'validate_callback', 'cleanup_callback'] as $callback) {
            if (array_key_exists($callback, $args) && !is_callable($args[$callback])) {
                return;
            }
        }

        $args['pack_id'] = $pack_id;
        $args['operation_id'] = $operation_id;
        $args['ability_name'] = $ability_name;

        if (
            true !== ($args['irreversible'] ?? false)
            && (
                !is_callable($args['snapshot_callback'] ?? null)
                || !is_callable($args['fingerprint_callback'] ?? null)
                || !is_callable($args['rollback_callback'] ?? null)
            )
        ) {
            return;
        }

        foreach ($this->proposal_operations as $registered_pack_id => $operations) {
            foreach ($operations as $registered_operation_id => $operation) {
                if (
                    ($registered_pack_id !== $pack_id || $registered_operation_id !== $operation_id)
                    && (
                        $registered_operation_id === $operation_id
                        || $ability_name === (string) ($operation['ability_name'] ?? '')
                    )
                ) {
                    return;
                }
            }
        }

        $this->proposal_operations[$pack_id][$operation_id] = $args;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function validators(string $pack_id): array {
        $this->ensure_loaded();

        return array_values($this->validators[sanitize_key($pack_id)] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recommenders(string $pack_id): array {
        $this->ensure_loaded();

        return array_values($this->recommenders[sanitize_key($pack_id)] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function materializers(string $pack_id): array {
        $this->ensure_loaded();

        return array_values($this->materializers[sanitize_key($pack_id)] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function proposal_operations(): array {
        $this->ensure_loaded();
        $active = array_fill_keys(
            array_map(static fn(array $pack): string => (string) $pack['id'], $this->active()),
            true,
        );
        $operations = [];

        foreach ($this->proposal_operations as $pack_id => $pack_operations) {
            if (!isset($active[$pack_id])) {
                continue;
            }

            array_push($operations, ...array_values($pack_operations));
        }

        return $operations;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function proposal_operation(string $operation): ?array {
        foreach ($this->proposal_operations() as $candidate) {
            if ($operation === (string) ($candidate['operation_id'] ?? '')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Pattern namespaces declared by active packs (and active stylesheet as fallback).
     *
     * @return list<string>
     */
    public function pattern_namespaces(): array {
        $this->ensure_loaded();
        $namespaces = [];

        foreach ($this->active() as $pack) {
            $pattern_config = ArrayKey::as_map($pack['patterns'] ?? null);
            $namespace = sanitize_key((string) ($pattern_config['namespace'] ?? ''));

            if ('' !== $namespace) {
                $namespaces[] = $namespace;
            }

            if ('' === $namespace && '' !== (string) ($pack['id'] ?? '')) {
                $namespaces[] = sanitize_key((string) $pack['id']);
            }
        }

        if (function_exists('get_stylesheet')) {
            $stylesheet = sanitize_key(get_stylesheet());

            if ('' !== $stylesheet) {
                $namespaces[] = $stylesheet;
            }
        }

        if (function_exists('get_template')) {
            $template = sanitize_key(get_template());

            if ('' !== $template) {
                $namespaces[] = $template;
            }
        }

        return array_values(array_unique(array_filter($namespaces, static fn(string $value): bool => '' !== $value)));
    }

    /**
     * Pattern thrash aliases from active packs (requested name → canonical registered slug).
     *
     * @return array<string, string>
     */
    public function pattern_aliases(): array {
        $this->ensure_loaded();
        $aliases = [];

        foreach ($this->active() as $pack) {
            $pattern_config = ArrayKey::as_map($pack['patterns'] ?? null);
            $pack_aliases = ArrayKey::as_map($pattern_config['aliases'] ?? null);

            foreach ($pack_aliases as $from => $to) {
                if (!is_string($to)) {
                    continue;
                }

                $from = mb_strtolower(trim($from));
                $to = sanitize_text_field($to);

                if ('' === $from || '' === $to || !str_contains($to, '/')) {
                    continue;
                }

                $aliases[$from] = $to;
            }
        }

        return $aliases;
    }

    /**
     * Read a bounded file referenced by a validated manifest.
     */
    public function read_pack_file(array $pack, string $relative, int $max_bytes = 65_536): string {
        $root = (string) ($pack['_root'] ?? '');
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ('' === $root || '' === $relative || str_contains($relative, '..')) {
            return '';
        }

        $path = realpath(trailingslashit($root) . $relative);

        if (!is_string($path) || !str_starts_with($path, trailingslashit($root)) || !is_readable($path)) {
            return '';
        }

        $size = filesize($path);

        if (!is_int($size) || $size <= 0 || $size > max(1024, $max_bytes)) {
            return '';
        }

        $content = file_get_contents($path);

        return is_string($content) ? $content : '';
    }

    private function ensure_loaded(): void {
        if (!$this->loaded) {
            $this->load_active_theme_packs();
        }
    }

    /**
     * @return list<string>
     */
    private function disabled_ids(): array {
        $ids = get_option('awpt_disabled_domain_packs', []);

        return is_array($ids) ? array_values(array_filter(array_map('sanitize_key', $ids))) : [];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>|\WP_Error
     */
    private function validate_manifest(array $manifest, string $root): array|\WP_Error {
        $id = sanitize_key((string) ($manifest['id'] ?? ''));
        $label = sanitize_text_field((string) ($manifest['label'] ?? ''));
        $version = sanitize_text_field((string) ($manifest['version'] ?? ''));
        $schema_version = (int) ($manifest['schema_version'] ?? 0);

        if (
            !in_array($schema_version, self::SUPPORTED_SCHEMA_VERSIONS, true)
            || '' === $id
            || '' === $label
            || '' === $version
        ) {
            return new \WP_Error('awpt_domain_manifest_schema', __(
                'The domain manifest requires a supported schema_version, id, label, and version.',
                'agent-wordpress-terminal',
            ));
        }

        $manifest['id'] = $id;
        $manifest['label'] = $label;
        $manifest['version'] = $version;
        $manifest['guidance'] = $this->validate_guidance($manifest['guidance'] ?? [], $root);
        $patterns = ArrayKey::as_map($manifest['patterns'] ?? null);
        $patterns['_root'] = $root;

        if (array_key_exists('catalog', $patterns)) {
            $patterns['catalog'] = $this->safe_relative_path((string) $patterns['catalog'], $root);
        }

        if (array_key_exists('namespace', $patterns)) {
            $patterns['namespace'] = sanitize_key((string) $patterns['namespace']);
        }

        $patterns['aliases'] = $this->validate_aliases($patterns['aliases'] ?? null);

        $manifest['patterns'] = $patterns;
        $rules = ArrayKey::as_map($manifest['rules'] ?? null);
        $rules['_root'] = $root;

        if (array_key_exists('path', $rules)) {
            $rules['path'] = $this->safe_relative_path((string) $rules['path'], $root);
        }

        $manifest['rules'] = $rules;

        foreach (['validators', 'recommenders', 'materializers', 'proposal_operations'] as $key) {
            $values = is_array($manifest[$key] ?? null) ? $manifest[$key] : [];
            $manifest[$key] = array_values(array_filter(array_map(static fn(mixed $value): string => sanitize_key(
                is_scalar($value) ? (string) $value : '',
            ), $values)));
        }

        return $manifest;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validate_guidance(mixed $guidance, string $root): array {
        if (!is_array($guidance)) {
            return [];
        }

        $clean = [];

        foreach ($guidance as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = sanitize_key((string) ($item['id'] ?? ''));
            $path = $this->safe_relative_path((string) ($item['path'] ?? ''), $root);

            if ('' === $id || '' === $path) {
                continue;
            }

            $applies = is_array($item['applies_to'] ?? null) ? $item['applies_to'] : ['compose', 'edit'];
            $clean[] = [
                'id' => $id,
                'label' => sanitize_text_field((string) ($item['label'] ?? $id)),
                'path' => $path,
                'applies_to' => array_values(array_filter(array_map(static fn(mixed $value): string => sanitize_key(
                    is_scalar($value) ? (string) $value : '',
                ), $applies))),
                'priority' => max(0, min(100, (int) ($item['priority'] ?? 50))),
                'hard' => filter_var($item['hard'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'triggers' => array_values(array_filter(array_map(
                    static fn(mixed $value): string => sanitize_text_field(is_scalar($value) ? (string) $value : ''),
                    is_array($item['triggers'] ?? null) ? $item['triggers'] : [],
                ))),
                'audience' => sanitize_key((string) ($item['audience'] ?? 'editor')),
                '_root' => $root,
            ];
        }

        return $clean;
    }

    private function safe_relative_path(string $relative, string $root): string {
        $relative = ltrim(str_replace('\\', '/', trim($relative)), '/');

        if ('' === $relative || str_contains($relative, '..')) {
            return '';
        }

        $real = realpath(trailingslashit($root) . $relative);

        return is_string($real) && str_starts_with($real, trailingslashit($root)) ? $relative : '';
    }

    /**
     * @return array<string, string>
     */
    private function validate_aliases(mixed $aliases): array {
        if (!is_array($aliases)) {
            return [];
        }

        $clean = [];

        foreach ($aliases as $from => $to) {
            if (!is_string($from) || !(is_string($to) || is_scalar($to))) {
                continue;
            }

            $from_key = mb_strtolower(trim($from));
            $to_value = sanitize_text_field((string) $to);

            if ('' === $from_key || '' === $to_value || !str_contains($to_value, '/')) {
                continue;
            }

            if (count($clean) >= 200) {
                break;
            }

            $clean[$from_key] = $to_value;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $child
     * @return array<string, mixed>
     */
    private function merge_pack(array $parent, array $child): array {
        $merged = array_replace_recursive($parent, $child);
        $guidance = [];

        foreach ([...((array) ($parent['guidance'] ?? [])), ...((array) ($child['guidance'] ?? []))] as $item) {
            if (!(is_array($item) && '' !== (string) ($item['id'] ?? ''))) {
                continue;
            }

            $guidance[(string) $item['id']] = $item;
        }

        $merged['guidance'] = array_values($guidance);

        return $merged;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $collection
     * @param array<string, mixed> $args
     */
    private function register_extension(
        array &$collection,
        string $pack_id,
        string $extension_id,
        array $args,
        string $manifest_key,
    ): void {
        $pack_id = sanitize_key($pack_id);
        $extension_id = sanitize_key($extension_id);

        if (
            '' === $pack_id
            || '' === $extension_id
            || !$this->is_declared($pack_id, $manifest_key, $extension_id)
            || !isset($args['callback'])
            || !is_callable($args['callback'])
        ) {
            return;
        }

        $args['pack_id'] = $pack_id;
        $args['id'] = $extension_id;
        $collection[$pack_id][$extension_id] = $args;
    }

    private function is_declared(string $pack_id, string $manifest_key, string $extension_id): bool {
        $pack = $this->packs[$pack_id] ?? null;

        return (
            is_array($pack) && in_array($extension_id, ArrayKey::list_of_strings($pack[$manifest_key] ?? null), true)
        );
    }
}
