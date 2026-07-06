<?php

/**
 * WordPress AI connector catalog.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lists installed AI connectors and reports configuration status.
 */
final class ConnectorCatalog {
    /**
     * Direct-key providers that AWPT supports without any WordPress connector plugin.
     *
     * These are always-available baseline providers: every WordPress version can use
     * them, independent of whether Core Connectors or an AI Client companion plugin
     * is installed.
     *
     * @var list<string>
     */
    public const DIRECT_PROVIDER_IDS = ['openrouter', 'openai'];

    /**
     * Connector inspection helper.
     */
    private ConnectorInspector $inspector;

    /**
     * @param ConnectorInspector|null $inspector Optional inspector for testing.
     */
    public function __construct(?ConnectorInspector $inspector = null) {
        $this->inspector = $inspector ?? new ConnectorInspector();
    }

    /**
     * Whether the WordPress Connectors API is available.
     */
    public function is_available(): bool {
        return function_exists('wp_get_connectors');
    }

    /**
     * URL for the core Connectors settings screen.
     */
    public function connectors_admin_url(): string {
        return admin_url('options-connectors.php');
    }

    /**
     * Return installed AI connectors with status metadata.
     *
     * @return list<array{
     *     id: string,
     *     name: string,
     *     description: string,
     *     active: bool,
     *     authenticated: bool,
     *     ready: bool,
     *     status: string,
     *     status_label: string
     * }>
     */
    public function list_installed_connectors(): array {
        if (!$this->is_available()) {
            return [];
        }

        $connectors = [];

        foreach (wp_get_connectors() as $connector_id => $data) {
            if ('ai_provider' !== ($data['type'] ?? '')) {
                continue;
            }

            if (!$this->inspector->is_installed($data)) {
                continue;
            }

            $status = $this->inspector->build_status($connector_id, $data);

            $connectors[] = [
                'id' => $connector_id,
                'name' => $this->inspector->connector_name_from_data($connector_id, $data),
                'description' => is_string($data['description'] ?? null) ? $data['description'] : '',
                'active' => $status['active'],
                'authenticated' => $status['authenticated'],
                'ready' => $status['ready'],
                'status' => $status['status'],
                'status_label' => $status['status_label'],
            ];
        }

        usort($connectors, static fn(array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $connectors;
    }

    /**
     * Resolve a human-readable provider label.
     */
    public function get_provider_label(string $provider_id): string {
        $direct_label = $this->direct_provider_label($provider_id);

        if (null !== $direct_label) {
            return $direct_label;
        }

        if ($this->is_available() && function_exists('wp_get_connector')) {
            $connector = wp_get_connector($provider_id);

            if (is_array($connector)) {
                return $this->inspector->connector_name_from_data($provider_id, $connector);
            }
        }

        return $provider_id;
    }

    /**
     * Whether the saved provider value is allowed.
     */
    public function is_valid_provider(string $provider_id): bool {
        if (in_array($provider_id, self::DIRECT_PROVIDER_IDS, true)) {
            return true;
        }

        if (
            !$this->is_available()
            || !function_exists('wp_is_connector_registered')
            || !wp_is_connector_registered($provider_id)
        ) {
            return false;
        }

        $connector = wp_get_connector($provider_id);

        return (
            is_array($connector)
            && 'ai_provider' === ($connector['type'] ?? '')
            && $this->inspector->is_installed($connector)
        );
    }

    /**
     * Resolve a display label for a built-in direct-key provider.
     */
    private function direct_provider_label(string $provider_id): ?string {
        return match ($provider_id) {
            'openrouter' => __('OpenRouter', 'agent-wordpress-terminal'),
            'openai' => __('OpenAI', 'agent-wordpress-terminal'),
            default => null,
        };
    }

    /**
     * Pick the best default connector when none is saved.
     */
    public function resolve_default_provider(): string {
        $installed = $this->list_installed_connectors();

        foreach ($installed as $connector) {
            if ($connector['ready']) {
                return $connector['id'];
            }
        }

        if ([] !== $installed) {
            return $installed[0]['id'];
        }

        return 'openrouter';
    }
}
