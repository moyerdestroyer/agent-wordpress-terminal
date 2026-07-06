<?php

/**
 * Database installer.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Database;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Creates and maintains AWPT database tables.
 */
final class Installer {
    /**
     * Current custom database schema version.
     */
    private const SCHEMA_VERSION = '3';

    /**
     * Plugin activation hook.
     */
    public static function activate(): void {
        self::create_tables();
        update_option('awpt_schema_version', self::SCHEMA_VERSION, false);
        flush_rewrite_rules();
    }

    /**
     * Ensure newly added tables exist after plugin updates.
     */
    public static function maybe_upgrade(): void {
        if (self::SCHEMA_VERSION === (string) get_option('awpt_schema_version', '')) {
            return;
        }

        self::create_tables();
        update_option('awpt_schema_version', self::SCHEMA_VERSION, false);
    }

    /**
     * Plugin deactivation hook.
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Create custom tables.
     */
    public static function create_tables(): void {
        $wpdb = WpDb::get();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'awpt_';

        $sessions = "CREATE TABLE {$prefix}sessions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			title varchar(191) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			provider varchar(100) NOT NULL DEFAULT '',
			focus_post_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) {$charset_collate};";

        $messages = "CREATE TABLE {$prefix}messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			role varchar(20) NOT NULL,
			content longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id)
		) {$charset_collate};";

        $tool_calls = "CREATE TABLE {$prefix}tool_calls (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			tool_name varchar(191) NOT NULL,
			input_json longtext NOT NULL,
			output_json longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id)
		) {$charset_collate};";

        $context_items = "CREATE TABLE {$prefix}context_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			item_type varchar(50) NOT NULL,
			item_id bigint(20) unsigned NULL,
			label varchar(191) NOT NULL DEFAULT '',
			payload_json longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id)
		) {$charset_collate};";

        $actions = "CREATE TABLE {$prefix}actions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			title varchar(191) NOT NULL,
			description longtext NOT NULL,
			payload_json longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'proposed',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id)
			) {$charset_collate};";

        $knowledge_index = "CREATE TABLE {$prefix}knowledge_index (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_kind varchar(50) NOT NULL,
			source_id varchar(191) NOT NULL,
			source_post_id bigint(20) unsigned NULL,
			source_path_hash varchar(64) NOT NULL DEFAULT '',
			label varchar(191) NOT NULL DEFAULT '',
			uri text NULL,
			content_hash varchar(64) NOT NULL DEFAULT '',
			modified_at datetime NULL,
			indexed_at datetime NOT NULL,
			metadata_json longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source (source_kind, source_id),
			KEY source_post_id (source_post_id),
			KEY source_path_hash (source_path_hash)
		) {$charset_collate};";

        $knowledge_chunks = "CREATE TABLE {$prefix}knowledge_chunks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			index_id bigint(20) unsigned NOT NULL,
			chunk_index int unsigned NOT NULL DEFAULT 0,
			chunk_text longtext NOT NULL,
			embedding_json longtext NULL,
			metadata_json longtext NULL,
			char_count int unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY index_id (index_id),
			FULLTEXT KEY chunk_text (chunk_text)
		) {$charset_collate};";

        $incidents = "CREATE TABLE {$prefix}incidents (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			kind varchar(30) NOT NULL DEFAULT 'php',
			source varchar(100) NOT NULL DEFAULT '',
			attempted_action varchar(100) NOT NULL DEFAULT '',
			action_id bigint(20) unsigned NULL,
			error_text longtext NOT NULL,
			diagnosis_json longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			created_at datetime NOT NULL,
			resolved_at datetime NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY status (status)
		) {$charset_collate};";

        dbDelta($sessions);
        dbDelta($messages);
        dbDelta($tool_calls);
        dbDelta($context_items);
        dbDelta($actions);
        dbDelta($knowledge_index);
        dbDelta($knowledge_chunks);
        dbDelta($incidents);
    }
}
