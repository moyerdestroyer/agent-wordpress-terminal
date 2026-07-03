<?php

/**
 * Main plugin bootstrap.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT;

use AWPT\Abilities\RegisterAbilities;
use AWPT\Admin\Page;
use AWPT\Database\Installer;
use AWPT\Knowledge\KnowledgeIndexer;
use AWPT\REST\ActionsController;
use AWPT\REST\ChatController;
use AWPT\REST\KnowledgeController;
use AWPT\REST\SessionsController;
use AWPT\REST\ToolsController;
use AWPT\Support\Environment;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Plugin singleton.
 */
final class Plugin
{
    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Get the plugin instance.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Boot plugin services.
     */
    public function boot(): void
    {
        add_action('admin_notices', [Environment::class, 'render_admin_notices']);
        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Initialize admin and abilities.
     */
    public function init(): void
    {
        Installer::maybe_upgrade();
        KnowledgeIndexer::register_content_hooks();
        new Page()->init();
        new RegisterAbilities()->init();
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes(): void
    {
        new SessionsController()->register_routes();
        new ChatController()->register_routes();
        new KnowledgeController()->register_routes();
        new ActionsController()->register_routes();
        new ToolsController()->register_routes();
    }
}
