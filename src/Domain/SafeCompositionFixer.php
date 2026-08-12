<?php

/**
 * Closed, invariant-checked repairs for proposed block markup.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

if (!defined('ABSPATH')) {
    exit();
}

final class SafeCompositionFixer {
    /**
     * @return array{content: string, fixes: list<array<string, mixed>>}
     */
    public function fix(string $content): array {
        $current = $content;
        $fixes = [];

        $fixers = [
            'repair-closing-delimiters' => $this->repair_closing_delimiters(...),
            'normalize-class-tokens' => $this->normalize_class_tokens(...),
            'remove-serialization-whitespace' => $this->remove_serialization_whitespace(...),
        ];

        foreach ($fixers as $id => $fixer) {
            $candidate = $fixer($current);

            if ($candidate === $current || !$this->preserves_invariants($current, $candidate)) {
                continue;
            }

            $fixes[] = [
                'id' => $id,
                'description' => $this->description($id),
                'block_path' => '',
                'before_hash' => hash('sha256', $current),
                'after_hash' => hash('sha256', $candidate),
                'applied' => true,
            ];
            $current = $candidate;
        }

        return ['content' => $current, 'fixes' => $fixes];
    }

    private function repair_closing_delimiters(string $content): string {
        /** @var list<string> $stack */
        $stack = [];
        $fixed = preg_replace_callback(
            '/<!--\s+(\/?)wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)(?:\s+\{.*?\})?\s*(\/?)-->/is',
            static function (array $match) use (&$stack): string {
                $closing = '/' === ($match[1] ?? '');
                $self_closing = '/' === ($match[3] ?? '');
                $name = $match[2] ?? '';

                if (!$closing && !$self_closing) {
                    $stack[] = $name;
                    return $match[0];
                }

                if (!$closing || [] === $stack) {
                    return $match[0];
                }

                $expected = array_pop($stack);

                if ($expected === $name) {
                    return $match[0];
                }

                return str_replace('/wp:' . $name, '/wp:' . $expected, $match[0]);
            },
            $content,
        );

        return is_string($fixed) ? $fixed : $content;
    }

    private function normalize_class_tokens(string $content): string {
        $normalized = preg_replace_callback(
            '/\bclass=(["\'])(.*?)\1/is',
            static function (array $match): string {
                $tokens = preg_split('/\s+/', trim($match[2] ?? ''));
                $tokens = false === $tokens ? [] : array_values(array_unique(array_filter($tokens)));

                return 'class=' . $match[1] . implode(' ', $tokens) . $match[1];
            },
            $content,
        );

        return is_string($normalized) ? $normalized : $content;
    }

    private function remove_serialization_whitespace(string $content): string {
        $serialized = '';

        foreach (parse_blocks($content) as $block) {
            if (null === ($block['blockName'] ?? null) && '' === trim((string) ($block['innerHTML'] ?? ''))) {
                continue;
            }

            /** @var array{attrs?: array<array-key, mixed>, blockName?: null|string, innerBlocks?: array<array-key, array<array-key, mixed>>, innerContent?: array<array-key, mixed>, innerHTML?: string} $serializable */
            $serializable = $block;
            $serialized .= serialize_block($serializable);
        }

        return '' !== $serialized ? $serialized : $content;
    }

    private function preserves_invariants(string $before, string $after): bool {
        if ('' === trim($after)) {
            return false;
        }

        return $this->invariants($before) === $this->invariants($after);
    }

    /**
     * @return array<string, mixed>
     */
    private function invariants(string $content): array {
        $blocks = parse_blocks($content);
        $names = [];
        $media = [];
        $attributes = [];

        $walk = static function (array $items) use (&$walk, &$names, &$media, &$attributes): void {
            foreach ($items as $block) {
                if (!is_array($block)) {
                    continue;
                }

                $name = (string) ($block['blockName'] ?? '');

                if ('' !== $name) {
                    $names[] = $name;
                }

                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $attributes[] = $attrs;

                foreach (['id', 'mediaId'] as $key) {
                    if ((int) ($attrs[$key] ?? 0) <= 0) {
                        continue;
                    }

                    $media[] = (int) $attrs[$key];
                }

                $walk(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : []);
            }
        };
        $walk($blocks);
        $links = [];
        $matches = [];
        preg_match_all('/\bhref=(["\'])(.*?)\1/i', $content, $matches);

        foreach ($matches[2] ?? [] as $link) {
            $links[] = html_entity_decode($link);
        }

        sort($links);
        sort($media);

        return [
            'names' => $names,
            'attributes' => $attributes,
            'text' => preg_replace(
                '/\s+/',
                ' ',
                trim(wp_strip_all_tags(preg_replace('/<!--.*?-->/s', '', $content) ?? $content)),
            ),
            'links' => $links,
            'media' => $media,
        ];
    }

    private function description(string $id): string {
        return match ($id) {
            'repair-closing-delimiters' => __(
                'Repaired an unambiguous mismatched closing block delimiter.',
                'agent-wordpress-terminal',
            ),
            'normalize-class-tokens' => __(
                'Removed duplicate CSS class tokens without changing classes.',
                'agent-wordpress-terminal',
            ),
            default => __('Removed serialization-only whitespace blocks.', 'agent-wordpress-terminal'),
        };
    }
}
