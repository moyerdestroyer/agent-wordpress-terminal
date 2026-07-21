<?php

/**
 * Server-side same-site HTTP GET with loopback fallback for Docker hosts.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Fetches same-site URLs even when the public host is not resolvable from PHP
 * (common with reverse-proxy hostnames inside containers).
 */
final class SameSiteHttpClient {
    private SameSiteUrlPolicy $policy;

    public function __construct(?SameSiteUrlPolicy $policy = null) {
        $this->policy = $policy ?? new SameSiteUrlPolicy();
    }

    /**
     * @param array<string, string> $headers
     * @return array{response: array<string, mixed>|\WP_Error, fetch_url: string, used_loopback: bool}
     */
    public function get(string $url, array $headers = [], int $timeout = 20): array {
        if (!$this->policy->is_allowed($url)) {
            return [
                'response' => new \WP_Error(
                    'awpt_url_not_allowed',
                    __('URL must belong to this WordPress site.', 'agent-wordpress-terminal'),
                    ['status' => 400],
                ),
                'fetch_url' => $url,
                'used_loopback' => false,
            ];
        }

        $timeout = max(5, min(45, $timeout));
        $args = [
            'timeout' => $timeout,
            'redirection' => 3,
            'reject_unsafe_urls' => true,
            'headers' => array_merge([
                'Accept' => 'text/html,application/xhtml+xml,*/*',
            ], $headers),
        ];

        $response = wp_remote_get($url, $args);

        if (!is_wp_error($response)) {
            return [
                'response' => $response,
                'fetch_url' => $url,
                'used_loopback' => false,
            ];
        }

        $loopback = $this->loopback_url($url);

        if (null === $loopback) {
            return [
                'response' => $response,
                'fetch_url' => $url,
                'used_loopback' => false,
            ];
        }

        $parsed = wp_parse_url($url);
        $host = is_array($parsed) ? (string) ($parsed['host'] ?? '') : '';
        $loop_args = $args;
        $loop_args['headers']['Host'] = $host;
        // SSL verification is irrelevant for loopback http.
        $loop_args['sslverify'] = false;

        $loop_response = wp_remote_get($loopback['url'], $loop_args);

        if (is_wp_error($loop_response)) {
            return [
                'response' => $response,
                'fetch_url' => $url,
                'used_loopback' => false,
            ];
        }

        return [
            'response' => $loop_response,
            'fetch_url' => $loopback['url'],
            'used_loopback' => true,
        ];
    }

    /**
     * @return array{url: string}|null
     */
    private function loopback_url(string $url): ?array {
        $parsed = wp_parse_url($url);

        if (!is_array($parsed)) {
            return null;
        }

        $path = (string) ($parsed['path'] ?? '/');
        $query = isset($parsed['query']) ? '?' . (string) $parsed['query'] : '';
        // WordPress in Docker typically serves HTTP on port 80 inside the container.
        $loop = 'http://127.0.0.1' . $path . $query;

        return ['url' => $loop];
    }
}
