<?php

/**
 * Preserves pattern provenance when patterns become editable block markup.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

if (!defined('ABSPATH')) {
    exit();
}

final class PatternMaterializer {
    private PatternMetadataCatalog $catalog;
    private DomainPackRegistry $registry;

    public function __construct(?PatternMetadataCatalog $catalog = null, ?DomainPackRegistry $registry = null) {
        $this->catalog = $catalog ?? new PatternMetadataCatalog();
        $this->registry = $registry ?? DomainPackRegistry::instance();
    }

    public function materialize(string $pattern_name, string $content, bool $all_roots = true): string {
        $blocks = parse_blocks($content);
        $stamped = false;
        $instance = function_exists('wp_generate_uuid4')
            ? (string) wp_generate_uuid4()
            : hash('sha256', $pattern_name . '|' . microtime(true) . '|' . random_int(0, PHP_INT_MAX));

        foreach ($blocks as &$block) {
            if (null === ($block['blockName'] ?? null)) {
                continue;
            }

            if (!$all_roots && $stamped) {
                continue;
            }

            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $metadata = is_array($attrs['metadata'] ?? null) ? $attrs['metadata'] : [];
            $metadata['patternName'] = sanitize_text_field($pattern_name);
            $metadata['patternInstance'] = sanitize_text_field($instance);
            $attrs['metadata'] = $metadata;
            $block['attrs'] = $attrs;
            $stamped = true;
        }
        unset($block);

        /** @var array<int|string, array{attrs: array<array-key, mixed>, blockName: null|string, innerBlocks: array<array-key, array<array-key, mixed>>, innerContent: array<array-key, mixed>, innerHTML: string}> $serializable */
        $serializable = $blocks;
        $materialized = serialize_blocks($serializable);
        $metadata = $this->catalog->get($pattern_name);
        $pack_id = (string) ($metadata['pack_id'] ?? '');

        if ('' !== $pack_id) {
            foreach ($this->registry->materializers($pack_id) as $materializer) {
                $callback = $materializer['callback'] ?? null;

                if (is_callable($callback)) {
                    $result = $callback($materialized, $pattern_name, $metadata);

                    if (is_string($result) && '' !== trim($result)) {
                        $materialized = $result;
                    }
                }
            }
        }

        return $materialized;
    }

    public function has_provenance(string $pattern_name, string $content): bool {
        $target = sanitize_text_field($pattern_name);
        $walk = static function (array $blocks) use (&$walk, $target): bool {
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }

                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $metadata = is_array($attrs['metadata'] ?? null) ? $attrs['metadata'] : [];

                if ($target === sanitize_text_field((string) ($metadata['patternName'] ?? ''))) {
                    return true;
                }

                if ($walk(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [])) {
                    return true;
                }
            }

            return false;
        };

        return '' !== $target && $walk(parse_blocks($content));
    }

    /**
     * @return array<string, mixed>
     */
    public function provenance(string $pattern_name, string $mode, string $source_content): array {
        $metadata = $this->catalog->get($pattern_name);

        return [
            'patterns' => [
                [
                    'name' => sanitize_text_field($pattern_name),
                    'block_path' => '0',
                    'mode' => sanitize_key($mode),
                    'source_hash' => hash('sha256', $source_content),
                    'pack_id' => sanitize_key((string) ($metadata['pack_id'] ?? '')),
                    'pack_version' => sanitize_text_field((string) ($metadata['pack_version'] ?? '')),
                ],
            ],
        ];
    }
}
