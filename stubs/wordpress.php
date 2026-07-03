<?php

/**
 * WordPress runtime constants for Mago static analysis.
 *
 * These constants are defined at runtime via define() in wp-load.php /
 * wp-settings.php, so the php-stubs/wordpress-stubs package cannot declare
 * them (it only documents them in PHPDoc). Mago needs actual const/define
 * statements to resolve them, hence this minimal file.
 *
 * All WordPress functions and classes are provided by the
 * php-stubs/wordpress-stubs Composer package via [source].includes.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace {
    define('ABSPATH', '/wordpress/');

    // wp-includes/wp-db.php result-object modes.
    define('OBJECT', 'OBJECT');
    define('OBJECT_K', 'OBJECT_K');
    define('ARRAY_A', 'ARRAY_A');
    define('ARRAY_N', 'ARRAY_N');

    // Core path constants.
    define('WP_CONTENT_DIR', '/wordpress/wp-content');
    define('WP_PLUGIN_DIR', '/wordpress/wp-content/plugins');
}
