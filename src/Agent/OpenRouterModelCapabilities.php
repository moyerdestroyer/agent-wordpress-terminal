<?php

/**
 * OpenRouter model capability discovery.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolves image-input support from OpenRouter metadata with conservative fallbacks.
 */
final class OpenRouterModelCapabilities {
    private const CACHE_SECONDS = 43_200;

    public function accepts_images(string $model): bool {
        $model = strtolower(trim($model));

        if ('' === $model) {
            return false;
        }

        if (in_array($model, ['openrouter/auto', 'openrouter/auto-beta'], true)) {
            return true;
        }

        $cache_key = 'awpt_or_image_cap_' . md5($model);
        $cached = get_transient($cache_key);

        if ('yes' === $cached || 'no' === $cached) {
            return 'yes' === $cached;
        }

        $discovered = $this->discover($model);

        if (null !== $discovered) {
            set_transient($cache_key, $discovered ? 'yes' : 'no', self::CACHE_SECONDS);

            return $discovered;
        }

        $fallback = ProviderImageCapability::model_accepts_images($model);
        set_transient($cache_key, $fallback ? 'yes' : 'no', 3_600);

        return $fallback;
    }

    private function discover(string $model): ?bool {
        $parts = explode('/', $model, 2);

        if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
            return null;
        }

        $url = sprintf('https://openrouter.ai/api/v1/model/%s/%s', rawurlencode($parts[0]), rawurlencode($parts[1]));
        $response = wp_remote_get($url, ['timeout' => 5]);

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($data)) {
            return null;
        }

        $model_data = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $architecture = is_array($model_data['architecture'] ?? null) ? $model_data['architecture'] : [];
        $modalities = is_array($architecture['input_modalities'] ?? null) ? $architecture['input_modalities'] : [];

        if ([] === $modalities) {
            return null;
        }

        return in_array('image', array_map('strval', $modalities), true);
    }
}
