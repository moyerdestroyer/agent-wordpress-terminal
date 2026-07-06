<?php

/**
 * Same-site URL allowlist for diagnostic probes.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Restricts URL probes to the current WordPress site host.
 */
final class SameSiteUrlPolicy {
    public function is_allowed(string $url): bool {
        $url = trim($url);

        if ('' === $url || !wp_http_validate_url($url)) {
            return false;
        }

        $target = wp_parse_url($url);
        $site = wp_parse_url(home_url('/'));

        if (!is_array($target) || !is_array($site)) {
            return false;
        }

        $target_host = strtolower((string) ($target['host'] ?? ''));
        $site_host = strtolower((string) ($site['host'] ?? ''));

        return '' !== $target_host && $target_host === $site_host;
    }
}
