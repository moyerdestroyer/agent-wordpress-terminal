<?php

/**
 * Whether the active chat model accepts multimodal image input.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Guards image_url evidence so text-only models (DeepSeek on OpenRouter) never
 * trigger "No endpoints found that support image input" routing failures.
 */
final class ProviderImageCapability {
    /**
     * Whether a concrete model ID is known to accept image_url chat parts.
     */
    public static function model_accepts_images(string $model): bool {
        $model = strtolower(trim($model));

        if ('' === $model) {
            return true;
        }

        // DeepSeek chat models on OpenRouter do not expose multimodal endpoints.
        // Sending image_url with require_parameters forces a 404 routing miss.
        if (str_starts_with($model, 'deepseek/') || str_contains($model, 'deepseek')) {
            return false;
        }

        return true;
    }
}
