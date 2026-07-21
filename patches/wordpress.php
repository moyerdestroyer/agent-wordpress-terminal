<?php

/**
 * Mago type patches for WordPress core symbols.
 *
 * The WordPress stubs package declares accurate PHPDoc but lacks native PHP
 * type declarations on many methods, so Mago infers `mixed` for their return
 * values. This patch adds concrete return types that Mago honours during
 * analysis, taking precedence over both the stubs and built-in definitions.
 *
 * Prefer honest runtime types over lying narrow types (false-positive
 * impossible-condition noise is worse than residual mixed).
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace {
    class wpdb
    {
        /**
         * @return list<array<string, mixed>>|null
         */
        public function get_results($query = null, $output = OBJECT): ?array {}

        /**
         * @return array<string, mixed>|null
         */
        public function get_row($query = null, $output = OBJECT, $y = 0): ?array {}

        /**
         * @return string|int|null
         */
        public function get_var($query = null, $x = 0, $y = 0): string|int|null {}

        /** @return int|false */
        public function insert($table, $data, $format = null): int|false {}

        /** @return int|false */
        public function update($table, $data, $where, $format = null, $where_format = null): int|false {}

        /** @return int|false */
        public function delete($table, $where, $where_format = null): int|false {}

        /**
         * @param string $query
         * @return string
         */
        public function prepare($query, ...$args): string {}

        /** @return string|null */
        public function db_version(): ?string {}
    }

    class WP_REST_Request
    {
        /**
         * Retrieves a parameter from the request.
         *
         * Returns mixed to match actual runtime behavior — the @phpstan-return
         * template in the stubs is too narrow and causes false-positive
         * impossible-type-comparison errors on defensive runtime checks.
         *
         * @return mixed
         */
        public function get_param($key): mixed {}
    }

    /**
     * Default OBJECT output used throughout AWPT. ARRAY_A/ARRAY_N are unused here;
     * keeping the return as WP_Post|null matches our call sites and runtime default.
     *
     * @param int|WP_Post|null $post
     * @param string           $output
     * @param string           $filter
     */
    function get_post($post = null, $output = OBJECT, $filter = 'raw'): ?WP_Post {}

    /**
     * Default object mode returns posts; fields=ids returns ints. AWPT uses both.
     *
     * @param array<string, mixed>|null $args
     * @return list<WP_Post|int>
     */
    function get_posts($args = null): array {}

    /**
     * @param string $url
     * @param int    $component
     * @return array<string, int|string>|string|int|false|null
     */
    function wp_parse_url($url, $component = -1): array|string|int|false|null {}

    /**
     * JSON decode without the fully-opaque `mixed` return.
     *
     * @param string    $json
     * @param bool|null $associative
     * @param int       $depth
     * @param int       $flags
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    function json_decode($json, $associative = null, $depth = 512, $flags = 0): array|string|int|float|bool|null {}

    /**
     * @param mixed $value
     * @param int   $flags
     * @param int   $depth
     * @return string|false
     */
    function wp_json_encode($value, $flags = 0, $depth = 512): string|false {}

    /**
     * @param string $content
     * @return list<array<string, mixed>>
     */
    function parse_blocks($content): array {}

    /**
     * @param string               $url
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    function wp_remote_get($url, $args = []): array|\WP_Error {}

    /**
     * @param string               $url
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    function wp_remote_post($url, $args = []): array|\WP_Error {}

    /**
     * @param array<string, mixed>|\WP_Error $response
     */
    function wp_remote_retrieve_body($response): string {}

    /**
     * @param array<string, mixed>|\WP_Error $response
     */
    function wp_remote_retrieve_response_code($response): int {}

    /**
     * @param array<string, mixed>|\WP_Error $response
     * @param string                         $header
     * @return string|array<int, string>
     */
    function wp_remote_retrieve_header($response, $header): string|array {}

    /**
     * Options are intentionally mixed at runtime.
     *
     * @param string $option
     * @param mixed  $default_value
     * @return mixed
     */
    function get_option($option, $default_value = false): mixed {}

    /**
     * @param string $transient
     * @return mixed
     */
    function get_transient($transient): mixed {}

    /**
     * @param string $show
     * @param string $filter
     */
    function get_bloginfo($show = '', $filter = 'raw'): string {}

    /**
     * @param string $plugin_folder
     * @return array<string, array<string, string>>
     */
    function get_plugins($plugin_folder = ''): array {}

    function is_plugin_active($plugin): bool {}

    function is_plugin_active_for_network($plugin): bool {}

    function wp_get_theme($stylesheet = null, $theme_root = null): \WP_Theme {}

    /**
     * apply_filters is genuinely mixed; declare it so call sites stay explicit.
     *
     * @param string $hook_name
     * @param mixed  $value
     * @param mixed  ...$args
     * @return mixed
     */
    function apply_filters($hook_name, $value, ...$args): mixed {}

    /**
     * @param string             $hook_name
     * @param array<int, mixed>  $args
     * @return mixed
     */
    function apply_filters_ref_array($hook_name, $args): mixed {}

    /**
     * @param callable|array|string|null $callback
     * @param mixed                      ...$args
     * @return mixed
     */
    function call_user_func($callback, ...$args): mixed {}

    function ini_get($option): string|false {}

    /**
     * AWPT always uses current_time('mysql') for string timestamps.
     *
     * @param string    $type
     * @param int|bool  $gmt
     */
    function current_time($type, $gmt = 0): string {}
}
