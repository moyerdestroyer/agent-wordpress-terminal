<?php

/**
 * Declarative validation rules supplied by active Domain Packs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

final class DomainRuleRepository {
    /** @var list<string> */
    public const SUPPORTED_TYPES = [
        'blocks.disallow',
        'blocks.require',
        'blocks.count',
        'headings.single_h1',
        'headings.no_skips',
        'anchors.unique',
        'attributes.require',
        'patterns.max',
        'patterns.require_blocks',
        'tokens.require_presets',
    ];

    private DomainPackRegistry $registry;

    /** @var list<array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(?DomainPackRegistry $registry = null) {
        $this->registry = $registry ?? DomainPackRegistry::instance();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $rules = [];

        foreach ($this->registry->active() as $pack) {
            $config = ArrayKey::as_map($pack['rules'] ?? null);
            $path = (string) ($config['path'] ?? '');

            if ('' === $path) {
                continue;
            }

            $rule_pack = $pack;
            $rule_pack['_root'] = (string) ($config['_root'] ?? $pack['_root'] ?? '');
            $decoded = json_decode($this->registry->read_pack_file($rule_pack, $path, 262_144), true);
            $document = ArrayKey::as_map($decoded);

            foreach (ArrayKey::list_of_maps($document['rules'] ?? null) as $rule) {
                $normalized = $this->normalize($rule, $pack);

                if (null !== $normalized) {
                    $rules[] = $normalized;
                }
            }
        }

        $this->cache = $rules;

        return $rules;
    }

    /**
     * @return array{count: int, types: list<string>, ruleset_hash: string}
     */
    public function summary(): array {
        $rules = $this->all();
        $types = array_values(array_unique(array_map(
            static fn(array $rule): string => (string) $rule['type'],
            $rules,
        )));
        sort($types);

        return [
            'count' => count($rules),
            'types' => $types,
            'ruleset_hash' => $this->hash(),
        ];
    }

    public function hash(): string {
        $encoded = wp_json_encode($this->all());

        return hash('sha256', is_string($encoded) ? $encoded : '[]');
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $pack
     * @return array<string, mixed>|null
     */
    private function normalize(array $rule, array $pack): ?array {
        $id = sanitize_key((string) ($rule['id'] ?? ''));
        $type = sanitize_text_field((string) ($rule['type'] ?? ''));
        $severity = sanitize_key((string) ($rule['severity'] ?? 'error'));
        $message = sanitize_textarea_field((string) ($rule['message'] ?? ''));

        if (
            '' === $id
            || !in_array($type, self::SUPPORTED_TYPES, true)
            || !in_array($severity, ['error', 'warning', 'info'], true)
            || '' === $message
        ) {
            return null;
        }

        return [
            'id' => $id,
            'type' => $type,
            'severity' => $severity,
            'scope' => array_values(array_filter(array_map(
                static fn(mixed $value): string => sanitize_key(is_scalar($value) ? (string) $value : ''),
                is_array($rule['scope'] ?? null) ? $rule['scope'] : ['all'],
            ))),
            'config' => ArrayKey::as_map($rule['config'] ?? null),
            'message' => $message,
            'suggestion' => sanitize_textarea_field((string) ($rule['suggestion'] ?? '')),
            'docs' => sanitize_text_field((string) ($rule['docs'] ?? '')),
            'pack_id' => sanitize_key((string) ($pack['id'] ?? '')),
            'source' => sanitize_text_field((string) ($pack['label'] ?? $pack['id'] ?? 'Domain Pack')),
        ];
    }
}
