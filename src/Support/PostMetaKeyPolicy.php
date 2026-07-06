<?php

/**
 * Rules for which post meta keys are safe to expose.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Determines whether a post meta key may be included in read-content output.
 */
final class PostMetaKeyPolicy
{
    public function is_exposed(string $key): bool
    {
        if (in_array($key, ['_edit_lock', '_edit_last'], true)) {
            return false;
        }

        if (str_starts_with($key, '_')) {
            if ('_thumbnail_id' === $key) {
                return true;
            }

            foreach (['_elementor_', '_wp_page_template', '_edit_', '_oembed_'] as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return false;
                }
            }

            return false;
        }

        return !$this->contains_sensitive_term(strtolower($key));
    }

    private function contains_sensitive_term(string $lower): bool
    {
        foreach ([
            'password',
            'secret',
            'token',
            'api_key',
            'license',
            'auth',
            'credential',
            'private_key',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
