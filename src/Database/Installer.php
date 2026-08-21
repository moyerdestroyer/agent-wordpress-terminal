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
    private const UPGRADE_LOCK_OPTION = 'awpt_schema_upgrade_lock';
    private const UPGRADE_LOCK_TTL = 300;
    /**
     * Current custom database schema version.
     */
    private const SCHEMA_VERSION = '15';

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
        $installed_version = (string) get_option('awpt_schema_version', '');

        if (self::SCHEMA_VERSION === $installed_version) {
            return;
        }

        if (!self::acquire_upgrade_lock()) {
            return;
        }

        try {
            // Additive schema: dbDelta creates/alters tables for the current definitions.
            self::create_tables();

            // Per-version data steps (backfills, renames, option migrations).
            // Add a new block when SCHEMA_VERSION requires more than additive tables.
            self::run_version_steps($installed_version);

            update_option('awpt_schema_version', self::SCHEMA_VERSION, false);
        } finally {
            delete_option(self::UPGRADE_LOCK_OPTION);
        }
    }

    /**
     * Run explicit data migrations for upgrades from $installed_version toward SCHEMA_VERSION.
     *
     * Prefer additive dbDelta-only bumps when possible. When a bump needs data
     * transforms, add a version_compare block here and document it in AGENTS.md.
     */
    private static function run_version_steps(string $installed_version): void {
        if ('' === $installed_version) {
            return;
        }

        // v9+: knowledge tables reshaped; force a rebuild of the index.
        if (version_compare($installed_version, '9', '<')) {
            update_option('awpt_knowledge_stale', '1', false);
        }
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
			last_turn_id varchar(64) NULL,
			last_outcome_json longtext NULL,
			improve_workflow_json longtext NULL,
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

        $session_events = "CREATE TABLE {$prefix}session_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			turn_id varchar(64) NOT NULL DEFAULT '',
			ordinal int unsigned NOT NULL DEFAULT 0,
			event_type varchar(40) NOT NULL,
			call_id varchar(191) NULL,
			payload_json longtext NOT NULL,
			token_estimate int unsigned NOT NULL DEFAULT 0,
			covers_through_event_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY session_turn (session_id, turn_id),
			KEY checkpoint_coverage (session_id, covers_through_event_id)
		) {$charset_collate};";

        $provider_calls = "CREATE TABLE {$prefix}provider_calls (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			provider varchar(100) NOT NULL DEFAULT '',
			model varchar(191) NOT NULL DEFAULT '',
			turn_id varchar(64) NULL,
			tool_round int unsigned NOT NULL DEFAULT 0,
			outcome varchar(30) NOT NULL DEFAULT 'success',
			error_code varchar(100) NOT NULL DEFAULT '',
			completion_budget int unsigned NOT NULL DEFAULT 0,
			prompt_tokens int unsigned NOT NULL DEFAULT 0,
			completion_tokens int unsigned NOT NULL DEFAULT 0,
			total_tokens int unsigned NOT NULL DEFAULT 0,
			cached_tokens int unsigned NOT NULL DEFAULT 0,
			cache_write_tokens int unsigned NOT NULL DEFAULT 0,
			context_tokens_estimate int unsigned NOT NULL DEFAULT 0,
			checkpoint_event_id bigint(20) unsigned NULL,
			cache_mode varchar(20) NOT NULL DEFAULT 'native',
			cache_status varchar(20) NOT NULL DEFAULT 'unreported',
			duration_ms int unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY turn_id (turn_id)
		) {$charset_collate};";

        $ai_logs = "CREATE TABLE {$prefix}ai_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event varchar(64) NOT NULL DEFAULT '',
			provider varchar(100) NOT NULL DEFAULT '',
			model varchar(191) NOT NULL DEFAULT '',
			turn_id varchar(64) NULL,
			tool_round int unsigned NOT NULL DEFAULT 0,
			outcome varchar(30) NOT NULL DEFAULT 'success',
			error_code varchar(100) NOT NULL DEFAULT '',
			duration_ms int unsigned NOT NULL DEFAULT 0,
			request_json longtext NULL,
			response_json longtext NULL,
			meta_json longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY turn_id (turn_id),
			KEY created_at (created_at)
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
			turn_id varchar(64) NULL,
			proposal_key varchar(100) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			UNIQUE KEY turn_proposal (session_id, turn_id, proposal_key)
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
			discovery_fingerprint varchar(64) NOT NULL DEFAULT '',
			index_profile varchar(191) NOT NULL DEFAULT '',
			content_type varchar(50) NOT NULL DEFAULT 'prose',
			semantic_eligible tinyint(1) unsigned NOT NULL DEFAULT 1,
			last_seen_run_id bigint(20) unsigned NULL,
			modified_at datetime NULL,
			indexed_at datetime NOT NULL,
			metadata_json longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source (source_kind, source_id),
			KEY source_post_id (source_post_id),
			KEY source_path_hash (source_path_hash),
			KEY last_seen_run_id (last_seen_run_id)
		) {$charset_collate};";

        $knowledge_chunks = "CREATE TABLE {$prefix}knowledge_chunks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			index_id bigint(20) unsigned NOT NULL,
			chunk_index int unsigned NOT NULL DEFAULT 0,
			chunk_id varchar(64) NOT NULL DEFAULT '',
			chunk_hash varchar(64) NOT NULL DEFAULT '',
			chunk_text longtext NOT NULL,
			embedding_json longtext NULL,
			embedding_profile varchar(191) NOT NULL DEFAULT '',
			embedding_dimensions int unsigned NOT NULL DEFAULT 0,
			embedding_state varchar(20) NOT NULL DEFAULT 'none',
			metadata_json longtext NULL,
			char_count int unsigned NOT NULL DEFAULT 0,
			word_count int unsigned NOT NULL DEFAULT 0,
			token_estimate int unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY index_id (index_id),
			KEY chunk_id (chunk_id),
			KEY embedding_state (embedding_state),
			FULLTEXT KEY chunk_text (chunk_text)
		) {$charset_collate};";

        $knowledge_runs = "CREATE TABLE {$prefix}knowledge_runs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'queued',
			phase varchar(20) NOT NULL DEFAULT 'discovering',
			index_profile varchar(191) NOT NULL DEFAULT '',
			discovered_sources int unsigned NOT NULL DEFAULT 0,
			processed_sources int unsigned NOT NULL DEFAULT 0,
			updated_sources int unsigned NOT NULL DEFAULT 0,
			unchanged_sources int unsigned NOT NULL DEFAULT 0,
			failed_sources int unsigned NOT NULL DEFAULT 0,
			indexed_chunks int unsigned NOT NULL DEFAULT 0,
			embedded_chunks int unsigned NOT NULL DEFAULT 0,
			error_text longtext NULL,
			started_at datetime NULL,
			heartbeat_at datetime NULL,
			finished_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

        $knowledge_jobs = "CREATE TABLE {$prefix}knowledge_jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			source_kind varchar(50) NOT NULL,
			source_id varchar(191) NOT NULL,
			discovery_fingerprint varchar(64) NOT NULL DEFAULT '',
			payload_json longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			error_text text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY run_source (run_id, source_kind, source_id),
			KEY run_status (run_id, status)
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

        $captures = "CREATE TABLE {$prefix}captures (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			action_id bigint(20) unsigned NULL,
			post_id bigint(20) unsigned NULL,
			url text NOT NULL,
			viewport_json text NULL,
			dom_snapshot longtext NULL,
			image_data longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY action_id (action_id)
		) {$charset_collate};";

        dbDelta($sessions);
        dbDelta($messages);
        dbDelta($tool_calls);
        dbDelta($session_events);
        dbDelta($provider_calls);
        dbDelta($ai_logs);
        dbDelta($context_items);
        dbDelta($actions);
        dbDelta($knowledge_index);
        dbDelta($knowledge_chunks);
        dbDelta($knowledge_runs);
        dbDelta($knowledge_jobs);
        dbDelta($incidents);
        dbDelta($captures);
    }

    private static function acquire_upgrade_lock(): bool {
        $now = time();

        if (add_option(self::UPGRADE_LOCK_OPTION, $now, '', false)) {
            return true;
        }

        $started_at = (int) get_option(self::UPGRADE_LOCK_OPTION, 0);

        if ($started_at > 0 && ($started_at + self::UPGRADE_LOCK_TTL) >= $now) {
            return false;
        }

        delete_option(self::UPGRADE_LOCK_OPTION);

        return add_option(self::UPGRADE_LOCK_OPTION, $now, '', false);
    }
}
