<?php

/**
 * Server-side URL probe for rendered PHP/JS error snippets.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Fetches a same-site URL and extracts error signals from the response body.
 */
final class UrlProbe {
    private SameSiteUrlPolicy $policy;
    private ErrorPathAttributor $attributor;

    public function __construct(?SameSiteUrlPolicy $policy = null, ?ErrorPathAttributor $attributor = null) {
        $this->policy = $policy ?? new SameSiteUrlPolicy();
        $this->attributor = $attributor ?? new ErrorPathAttributor();
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function probe(string $url): array|\WP_Error {
        if (!$this->policy->is_allowed($url)) {
            return new \WP_Error(
                'awpt_url_not_allowed',
                __('URL must belong to this WordPress site.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 3,
            'reject_unsafe_urls' => true,
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('awpt_probe_failed', $response->get_error_message(), ['status' => 502]);
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        $snippets = $this->extract_error_snippets($body);
        $attribution = $this->attributor->from_text(implode("\n", $snippets));
        $primary_suspect = $attribution['suspects'][0] ?? null;

        return [
            'url' => $url,
            'status_code' => $status_code,
            'content_type' => $content_type,
            'error_snippets' => $snippets,
            'suspected_plugin' => 'plugin' === ($primary_suspect['kind'] ?? '') ? $primary_suspect['slug'] : null,
            'suspected_theme' => 'theme' === ($primary_suspect['kind'] ?? '') ? $primary_suspect['slug'] : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function extract_error_snippets(string $body): array {
        $snippets = [];

        if (preg_match_all(
            '/(?:PHP (?:Fatal error|Warning|Notice)[^<]{0,400}|There has been a critical error[^<]{0,200})/i',
            $body,
            $matches,
        )) {
            foreach ($matches[0] as $match) {
                $snippet = trim(wp_strip_all_tags((string) $match));

                if ('' !== $snippet) {
                    $snippets[] = mb_substr($snippet, 0, 500);
                }
            }
        }

        if ([] === $snippets && preg_match('/critical error/i', $body)) {
            $snippets[] = __('WordPress critical error page detected.', 'agent-wordpress-terminal');
        }

        return array_slice($snippets, 0, 5);
    }
}
