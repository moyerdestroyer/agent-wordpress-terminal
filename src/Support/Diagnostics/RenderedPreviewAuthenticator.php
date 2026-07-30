<?php

/**
 * One-use authentication for server-launched staged-preview renders.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lets AWPT's local headless browser render the current admin's private preview
 * without publishing it or copying long-lived WordPress authentication cookies.
 */
final class RenderedPreviewAuthenticator {
    private const QUERY_ARG = 'awpt_render_token';
    private const TRANSIENT_PREFIX = 'awpt_render_';
    private const TTL_SECONDS = 45;

    public static function register(): void {
        add_filter('determine_current_user', [self::class, 'authenticate'], 5);
    }

    /**
     * @return array{url: string, key: string}|\WP_Error
     */
    public function issue(string $url): array|\WP_Error {
        $user_id = get_current_user_id();

        if ($user_id <= 0 || !new SameSiteUrlPolicy()->is_allowed($url)) {
            return new \WP_Error('awpt_render_auth_unavailable', __(
                'A signed-in same-site preview is required for rendered verification.',
                'agent-wordpress-terminal',
            ));
        }

        $token = wp_generate_password(48, false, false);
        $key = self::TRANSIENT_PREFIX . substr(hash('sha256', $token), 0, 40);
        $record = [
            'user_id' => $user_id,
            'target' => $this->canonical_target($url),
        ];

        if (!set_transient($key, $record, self::TTL_SECONDS)) {
            return new \WP_Error('awpt_render_auth_store_failed', __(
                'Could not prepare private preview authentication.',
                'agent-wordpress-terminal',
            ));
        }

        return [
            'url' => add_query_arg(self::QUERY_ARG, $token, $url),
            'key' => $key,
        ];
    }

    public function revoke(string $key): void {
        if (str_starts_with($key, self::TRANSIENT_PREFIX)) {
            delete_transient($key);
        }
    }

    public static function authenticate(mixed $user_id): int {
        $resolved_user_id = (int) $user_id;

        if ($resolved_user_id > 0) {
            return $resolved_user_id;
        }

        $token_value = $_GET[self::QUERY_ARG] ?? '';
        $token = is_string($token_value) ? sanitize_text_field(wp_unslash($token_value)) : '';
        $user_agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        if ('' === $token || !str_contains($user_agent, 'HeadlessChrome')) {
            return 0;
        }

        $key = self::TRANSIENT_PREFIX . substr(hash('sha256', $token), 0, 40);
        $record = get_transient($key);
        delete_transient($key);

        if (!is_array($record)) {
            return 0;
        }

        $expected_target = (string) ($record['target'] ?? '');
        $request_uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $actual_target = new self()->canonical_target(home_url($request_uri));

        if ('' === $expected_target || !hash_equals($expected_target, $actual_target)) {
            return 0;
        }

        return max(0, (int) ($record['user_id'] ?? 0));
    }

    private function canonical_target(string $url): string {
        $parts = wp_parse_url($url);

        if (!is_array($parts)) {
            return '';
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query[self::QUERY_ARG]);
        ksort($query);
        $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
        $query_string = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $path . ('' !== $query_string ? '?' . $query_string : '');
    }
}
