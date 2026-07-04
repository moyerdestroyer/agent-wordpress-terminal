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
     * Connector inspection helper, reused to resolve a Core Connector's fallback key.
     */
    private ConnectorInspector $inspector;

    /**
     * @param ConnectorCatalog|null   $catalog Optional catalog for testing.
     * @param ConnectorInspector|null $inspector Optional inspector for testing.
     */
    public function __construct(?ConnectorCatalog $catalog = null, ?ConnectorInspector $inspector = null)
    {
        $this->catalog = $catalog ?? new ConnectorCatalog();
        $this->inspector = $inspector ?? new ConnectorInspector();
    }

    /**
     * Normalize legacy provider option values.
     */
    public function normalize_provider_option(string $provider): string
    {
        if ('' === $provider || 'wordpress_ai' === $provider) {
            return $this->catalog->resolve_default_provider();
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

        if (in_array($provider_id, ConnectorCatalog::DIRECT_PROVIDER_IDS, true)) {
            return $this->direct_provider_summary($provider_id);
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
     * Summarize a built-in direct-key provider connection.
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
    private function direct_provider_summary(string $provider_id): array
    {
        $ready = $this->is_direct_provider_ready($provider_id);

        return [
            'id' => $provider_id,
            'label' => $this->catalog->get_provider_label($provider_id),
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'not_configured',
            'status_label' => $ready
                ? __('Ready', 'agent-wordpress-terminal')
                : $this->direct_provider_not_ready_label(),
            'connectors_url' => $this->catalog->connectors_admin_url(),
        ];
    }

    /**
     * Whether a direct-key provider has the credentials it needs configured.
     *
     * Also treats a provider as ready when a matching WordPress Connector key is
     * already configured elsewhere (env var, PHP constant, or DB option), so users
     * don't need to duplicate a key AWPT can already reuse.
     */
    private function is_direct_provider_ready(string $provider_id): bool
    {
        if ('' !== trim((string) get_option('awpt_' . $provider_id . '_api_key', ''))) {
            return true;
        }

        return '' !== $this->inspector->resolve_default_provider_api_key($provider_id);
    }

    /**
     * Not-ready status label for a direct-key provider.
     */
    private function direct_provider_not_ready_label(): string
    {
        return __('Key not configured', 'agent-wordpress-terminal');
    }
}
