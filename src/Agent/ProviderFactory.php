<?php

/**
 * Provider factory.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ConnectorSelection;

defined('ABSPATH') || exit();

/**
 * Creates configured agent providers.
 */
final class ProviderFactory
{
    /**
     * Create the configured provider.
     */
    public function make(): ProviderInterface
    {
        $provider_id = (new ConnectorSelection())->normalize_provider_option((string) get_option('awpt_provider', ''));

        if ('openrouter' === $provider_id) {
            return new OpenRouterProvider();
        }

        return new WordPressAIClientProvider($provider_id);
    }
}
