<?php

/**
 * awpt/read-theme-file ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Knowledge\FilesystemAccessPolicy;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads a safe text file under the active theme (or parent) for design/CSS diagnosis.
 */
final class ReadThemeFile implements AbilityInterface {
    private const MAX_CHARS = 8_000;
    private const MAX_MATCH_WINDOW = 2_400;

    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-theme-file',
            'label' => __('Read Theme File', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads a path under the active/parent theme. Prefer SCSS/docs over huge minified CSS. For large CSS files always pass query (e.g. layout-docs, sticky, simpletoc) to extract matching slices instead of dumping the whole stylesheet.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => __(
                            'Relative path within the active stylesheet theme, or parent theme if not found in the child.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional search terms (space-separated). For large/minified CSS, matching windows are returned instead of the file head.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'stylesheet' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional theme stylesheet directory name. Defaults to the active theme.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['path'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function can_read(array $input): bool {
        unset($input);

        return current_user_can('edit_theme_options') || current_user_can('switch_themes');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $relative = ltrim(str_replace('\\', '/', sanitize_text_field((string) ($input['path'] ?? ''))), '/');

        if ('' === $relative || str_contains($relative, '..')) {
            return new \WP_Error(
                'awpt_invalid_theme_path',
                __('Provide a relative theme path without "..".', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $resolved = $this->resolve_path($relative, sanitize_text_field((string) ($input['stylesheet'] ?? '')));

        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $raw = file_get_contents($resolved['path']);

        if (!is_string($raw)) {
            return new \WP_Error(
                'awpt_theme_file_unreadable',
                __('Could not read the theme file.', 'agent-wordpress-terminal'),
                ['status' => 500],
            );
        }

        $extension = strtolower(pathinfo($resolved['path'], PATHINFO_EXTENSION));
        $bytes = strlen($raw);
        $query = trim((string) ($input['query'] ?? ''));
        $is_minified_css =
            in_array($extension, ['css', 'scss'], true)
            && ($bytes > 20_000 || substr_count($raw, "\n") < max(5, (int) ($bytes / 2_000)));

        $matches = [];
        $content = $raw;
        $mode = 'full';
        $truncated = false;
        $note = '';

        if ('' !== $query) {
            $matches = $this->extract_matches($raw, $query);
            $mode = 'query';
            $content = [] !== $matches ? implode("\n\n---\n\n", array_column($matches, 'excerpt')) : '';
            $note = [] === $matches ? __('No matches for query in this file.', 'agent-wordpress-terminal') : '';
        } elseif ($is_minified_css || $bytes > self::MAX_CHARS) {
            // Refuse to dump the head of a 200KB minified bundle into the chat.
            $mode = 'summary';
            $content = '';
            $truncated = true;
            $note = __(
                'File is large or minified. Pass query (e.g. "layout-docs sticky sidenav") to extract relevant slices, or prefer source SCSS under assets/sass/.',
                'agent-wordpress-terminal',
            );
            $matches = $this->extract_matches($raw, 'layout sticky toc sidenav docs simpletoc');
            if ([] !== $matches) {
                $mode = 'auto_query';
                $content = implode("\n\n---\n\n", array_column($matches, 'excerpt'));
                $note = __(
                    'Returned auto-matched layout/TOC-related slices. Pass an explicit query for a tighter search.',
                    'agent-wordpress-terminal',
                );
            }
        }

        if ('' !== $content && strlen($content) > self::MAX_CHARS) {
            $content = mb_substr($content, 0, self::MAX_CHARS, 'UTF-8') . "\n…";
            $truncated = true;
        }

        return [
            'path' => $resolved['relative'],
            'theme_root' => $resolved['root'],
            'root_kind' => $resolved['root_kind'],
            'extension' => $extension,
            'bytes' => $bytes,
            'mode' => $mode,
            'query' => $query,
            'truncated' => $truncated,
            'match_count' => count($matches),
            'matches' => array_map(
                static function (array $match): array {
                    return [
                        'offset' => $match['offset'],
                        'term' => $match['term'],
                        'excerpt' => $match['excerpt'],
                    ];
                },
                array_slice($matches, 0, 8),
            ),
            'content' => $content,
            'note' => $note,
            'recommended_next_tools' => $this->next_tools($resolved['relative'], $is_minified_css, $query),
        ];
    }

    /**
     * @return list<array{offset: int, term: string, excerpt: string}>
     */
    private function extract_matches(string $content, string $query): array {
        $terms = array_values(array_filter(preg_split('/\s+/', mb_strtolower($query)) ?: []));
        $terms = array_slice(array_unique($terms), 0, 8);
        $matches = [];
        $seen = [];

        foreach ($terms as $term) {
            if (strlen($term) < 3) {
                continue;
            }

            $offset = 0;
            $found_for_term = 0;

            while ($found_for_term < 3) {
                $pos = stripos($content, $term, $offset);

                if (false === $pos) {
                    break;
                }

                $bucket = (int) floor($pos / 800);

                if (isset($seen[$bucket])) {
                    $offset = $pos + strlen($term);
                    continue;
                }

                $seen[$bucket] = true;
                $start = max(0, $pos - 200);
                $excerpt = substr($content, $start, self::MAX_MATCH_WINDOW);
                $matches[] = [
                    'offset' => $pos,
                    'term' => $term,
                    'excerpt' =>
                        (0 < $start ? '…' : '')
                            . $excerpt
                            . (strlen($content) > ($start + self::MAX_MATCH_WINDOW) ? '…' : ''),
                ];
                ++$found_for_term;
                $offset = $pos + strlen($term);
            }
        }

        return array_slice($matches, 0, 8);
    }

    /**
     * @return list<array{tool: string, input: array<string, mixed>}>
     */
    private function next_tools(string $relative, bool $is_minified_css, string $query): array {
        $tools = [];

        if ($is_minified_css && str_contains($relative, 'assets/css/')) {
            $tools[] = [
                'tool' => 'awpt/search-knowledge',
                'input' => ['query' => 'theme:assets/sass layout sticky toc', 'limit' => 8],
            ];
        }

        if ('' === $query && $is_minified_css) {
            $tools[] = [
                'tool' => 'awpt/read-theme-file',
                'input' => ['path' => $relative, 'query' => 'layout sticky toc sidenav'],
            ];
        }

        return $tools;
    }

    /**
     * @return array{path: string, root: string, relative: string, root_kind: string}|\WP_Error
     */
    private function resolve_path(string $relative, string $stylesheet): array|\WP_Error {
        $policy = new FilesystemAccessPolicy();
        $candidates = [];

        if ('' !== $stylesheet) {
            $theme = wp_get_theme($stylesheet);

            if ($theme->exists()) {
                $candidates[] = [
                    'root' => $theme->get_stylesheet_directory(),
                    'kind' => 'stylesheet',
                ];
            }
        } else {
            $candidates[] = [
                'root' => get_stylesheet_directory(),
                'kind' => 'stylesheet',
            ];
            $template = get_template_directory();

            if ($template !== get_stylesheet_directory()) {
                $candidates[] = [
                    'root' => $template,
                    'kind' => 'template',
                ];
            }
        }

        foreach ($candidates as $candidate) {
            $root = $candidate['root'];
            $full = trailingslashit($root) . $relative;
            $real_root = realpath($root);
            $real_file = realpath($full);

            if (!is_string($real_root) || !is_string($real_file) || !is_file($real_file)) {
                continue;
            }

            if (!$policy->can_read_file($real_file, $real_root, FilesystemAccessPolicy::ROOT_THEME)) {
                continue;
            }

            return [
                'path' => $real_file,
                'root' => $real_root,
                'relative' => $relative,
                'root_kind' => $candidate['kind'],
            ];
        }

        return new \WP_Error(
            'awpt_theme_file_not_found',
            __('Theme file not found or not allowed for Knowledge-style reads.', 'agent-wordpress-terminal'),
            [
                'status' => 404,
                'path' => $relative,
                'recovery' => __(
                    'Call awpt/list-knowledge-sources or awpt/search-knowledge for theme: labels, then retry with a relative path from a hit (e.g. docs/... or assets/sass/...).',
                    'agent-wordpress-terminal',
                ),
                'recommended_next_tools' => [
                    ['tool' => 'awpt/list-knowledge-sources', 'input' => ['sample' => 16, 'kind' => 'filesystem']],
                    ['tool' => 'awpt/search-knowledge', 'input' => ['query' => $relative, 'limit' => 8]],
                ],
            ],
        );
    }
}
