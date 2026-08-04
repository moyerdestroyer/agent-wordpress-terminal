<?php

/**
 * Main plugin bootstrap.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT;

use AWPT\Database\Installer;
use AWPT\Domain\DomainPackRegistry;
use AWPT\Domain\KnowledgeOverrideMeta;
use AWPT\Knowledge\DufresneKnowledgeRefresh;
use AWPT\Knowledge\KnowledgeIndexer;
use AWPT\Support\Diagnostics\RenderedPreviewAuthenticator;
use AWPT\Support\Environment;
use AWPT\Support\ServiceProvider;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Plugin singleton.
 */
final class Plugin {
    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    private ServiceProvider $services;

    /**
     * Get the plugin instance.
     */
    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Boot plugin services.
     */
    public function boot(): void {
        $this->services = new ServiceProvider();
        DomainPackRegistry::instance()->boot();
        RenderedPreviewAuthenticator::register();
        add_action('admin_notices', [Environment::class, 'render_admin_notices']);
        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Initialize admin and abilities.
     */
    public function init(): void {
        Installer::maybe_upgrade();
        KnowledgeOverrideMeta::register();
        KnowledgeIndexer::register_content_hooks();
        DufresneKnowledgeRefresh::register();
        $this->services->page()->init();
        $this->services->register_abilities()->init();
        $this->services->mcp_bridge()->init();
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes(): void {
        foreach ($this->services->rest_controllers() as $controller) {
            $controller->register_routes();
        }
    }
}
