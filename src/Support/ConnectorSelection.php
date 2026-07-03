<?php

/**
 * Active connector selection helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves the active AWPT connector from saved settings.
 */
final class ConnectorSelection
{
    /**
     * Connector catalog.
     */
    private ConnectorCatalog $catalog;

    /**
     * @param ConnectorCatalog|null $catalog Optional catalog for testing.
     */
    public function __construct(?ConnectorCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? new ConnectorCatalog();
    }

    /**
     * Normalize legacy provider option values.
     */
    public function normalize_provider_option(string $provider): string
    {
        if ('' === $provider || 'wordpress_ai' === $provider) {
            return $this->catalog->resolve_default_provider();
        }

        if (in_array($provider, ['openai', 'local'], true)) {
            return $this->migrate_legacy_provider($provider);
        }

        return $this->catalog->is_valid_provider($provider) ? $provider : $this->catalog->resolve_default_provider();
    }

    /**
     * Summarize the active connection for the terminal UI.
     *
     * @return array{
     *     id: string,
     *     label: string,
     *     ready: bool,
     *     status: string,
     *     status_label: string,
     *     connectors_url: string
     * }
     */
    public function active_connection_summary(): array
    {
        $provider_id = $this->normalize_provider_option((string) get_option('awpt_provider', ''));

        if ('openrouter' === $provider_id) {
            return $this->openrouter_summary();
        }

        foreach ($this->catalog->list_installed_connectors() as $connector) {
            if ($connector['id'] === $provider_id) {
                return [
                    'id' => $connector['id'],
                    'label' => $connector['name'],
                    'ready' => $connector['ready'],
                    'status' => $connector['status'],
                    'status_label' => $connector['status_label'],
                    'connectors_url' => $this->catalog->connectors_admin_url(),
                ];
            }
        }

        return [
            'id' => $provider_id,
            'label' => $this->catalog->get_provider_label($provider_id),
            'ready' => false,
            'status' => 'unavailable',
            'status_label' => __('Unavailable', 'agent-wordpress-terminal'),
            'connectors_url' => $this->catalog->connectors_admin_url(),
        ];
    }

    /**
     * Map legacy provider values to the simplified connector model.
     */
    private function migrate_legacy_provider(string $provider): string
    {
        foreach ($this->catalog->list_installed_connectors() as $connector) {
            if ($connector['id'] === $provider && $connector['ready']) {
                return $provider;
            }
        }

        if ('' !== (string) get_option('awpt_openrouter_api_key', '')) {
            return 'openrouter';
        }

        return $this->catalog->resolve_default_provider();
    }

    /**
     * Summarize the OpenRouter fallback connection.
     *
     * @return array{
     *     id: string,
     *     label: string,
     *     ready: bool,
     *     status: string,
     *     status_label: string,
     *     connectors_url: string
     * }
     */
    private function openrouter_summary(): array
    {
        $authenticated = '' !== trim((string) get_option('awpt_openrouter_api_key', ''));

        return [
            'id' => 'openrouter',
            'label' => $this->catalog->get_provider_label('openrouter'),
            'ready' => $authenticated,
            'status' => $authenticated ? 'ready' : 'not_configured',
            'status_label' => $authenticated
                ? __('Ready', 'agent-wordpress-terminal')
                : __('Key not configured', 'agent-wordpress-terminal'),
            'connectors_url' => $this->catalog->connectors_admin_url(),
        ];
    }
}
