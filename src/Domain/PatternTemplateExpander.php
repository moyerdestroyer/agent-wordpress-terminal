<?php

/**
 * Expands registered pattern references into editable Gutenberg markup.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\PatternCatalog;

if (!defined('ABSPATH')) {
    exit();
}

final class PatternTemplateExpander {
    private const MAX_DEPTH = 8;

    public function __construct(
        private readonly PatternCatalog $patterns = new PatternCatalog(),
    ) {}

    public function expand(string $pattern_name): string|\WP_Error {
        $pattern = $this->patterns->find($pattern_name);

        if (null === $pattern) {
            return new \WP_Error(
                'awpt_pattern_not_found',
                __('The requested pattern is not available.', 'agent-wordpress-terminal'),
                ['status' => 404, 'requested_pattern' => $pattern_name],
            );
        }

        return $this->expand_content((string) ($pattern['content'] ?? ''), [$pattern_name], 0);
    }

    /** @param list<string> $stack */
    private function expand_content(string $content, array $stack, int $depth): string|\WP_Error {
        if ($depth >= self::MAX_DEPTH) {
            return new \WP_Error(
                'awpt_pattern_expansion_depth',
                __('Pattern references are nested too deeply to expand safely.', 'agent-wordpress-terminal'),
                ['status' => 409, 'pattern_stack' => $stack],
            );
        }

        /** @var array{code?: string, message?: string, data?: mixed} $failure */
        $failure = [];
        $expanded = preg_replace_callback(
            '/<!--\s+wp:pattern\s+(\{.*?\})\s*\/-->/s',
            function (array $match) use ($stack, $depth, &$failure): string {
                $attrs = json_decode($match[1] ?? '', true);
                $slug = is_array($attrs) ? sanitize_text_field((string) ($attrs['slug'] ?? '')) : '';

                if ('' === $slug || in_array($slug, $stack, true)) {
                    $failure = [
                        'code' => 'awpt_pattern_expansion_cycle',
                        'message' => __('A pattern reference is missing or recursive.', 'agent-wordpress-terminal'),
                        'data' => ['status' => 409, 'pattern_stack' => [...$stack, $slug]],
                    ];
                    return $match[0];
                }

                $pattern = $this->patterns->find($slug);

                if (null === $pattern) {
                    $failure = [
                        'code' => 'awpt_pattern_dependency_missing',
                        'message' => sprintf(
                            __('Pattern dependency %s is unavailable.', 'agent-wordpress-terminal'),
                            $slug,
                        ),
                        'data' => ['status' => 409, 'pattern_stack' => [...$stack, $slug]],
                    ];
                    return $match[0];
                }

                $result = $this->expand_content((string) ($pattern['content'] ?? ''), [...$stack, $slug], $depth + 1);

                if (is_wp_error($result)) {
                    $failure = [
                        'code' => $result->get_error_code(),
                        'message' => $result->get_error_message(),
                        'data' => $result->get_error_data(),
                    ];
                    return $match[0];
                }

                return $result;
            },
            $content,
        );

        if ([] !== $failure) {
            return new \WP_Error(
                $failure['code'] ?? 'awpt_pattern_expansion_failed',
                $failure['message'] ?? __('Pattern expansion failed.', 'agent-wordpress-terminal'),
                $failure['data'] ?? ['status' => 409],
            );
        }

        return is_string($expanded) ? $expanded : $content;
    }
}
