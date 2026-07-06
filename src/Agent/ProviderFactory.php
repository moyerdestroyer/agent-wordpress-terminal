<?php

/**
 * Provider factory.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ConnectorSelection;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Creates configured agent providers.
 */
final class ProviderFactory {
    /**
     * Create the configured provider.
     */
    public function make(): ProviderInterface {
        $provider_id = new ConnectorSelection()->normalize_provider_option((string) get_option('awpt_provider', ''));

        return match ($provider_id) {
            'openrouter' => new OpenRouterProvider(),
            'openai' => new OpenAIProvider(),
            default => new WordPressAIClientProvider($provider_id),
        };
    }
}
