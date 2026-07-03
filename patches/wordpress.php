<?php

/**
 * Mago type patches for WordPress core symbols.
 *
 * The WordPress stubs package declares accurate PHPDoc but lacks native PHP
 * type declarations on many methods, so Mago infers `mixed` for their return
 * values. This patch adds concrete return types that Mago honours during
 * analysis, taking precedence over both the stubs and built-in definitions.
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
        public function get_var($query = null, $x = 0, $y = 0): mixed {}

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
}
