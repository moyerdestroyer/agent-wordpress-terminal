<?php

/**
 * Typed accessor for the WordPress database abstraction object.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

if (!defined('ABSPATH')) {
    exit();
}

final class WpDb {
    public static function get(): \wpdb {
        global $wpdb;
        assert($wpdb instanceof \wpdb, 'Global $wpdb must be a wpdb instance after WordPress bootstrap.');

        return $wpdb;
    }
}
