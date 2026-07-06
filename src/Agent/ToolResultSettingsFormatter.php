<?php

/**
 * Formats settings tool outputs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds readable summaries for site settings output.
 */
final class ToolResultSettingsFormatter {
    /**
     * @param array<array-key, mixed> $output Tool output.
     */
    public function format(array $output): string {
        $site = is_array($output['site'] ?? null) ? $output['site'] : [];
        $theme = is_array($output['theme'] ?? null) ? $output['theme'] : [];
        $discussion = is_array($output['discussion'] ?? null) ? $output['discussion'] : [];
        $comments_open = 'open' === (string) ($discussion['default_comment_status'] ?? '');

        $lines = [
            __('Here are the non-secret site settings I found:', 'agent-wordpress-terminal'),
            sprintf(
                /* translators: %s: site URL */
                __('- Site URL: %s', 'agent-wordpress-terminal'),
                (string) ($site['url'] ?? ''),
            ),
            sprintf(
                /* translators: %s: site name */
                __('- Site name: %s', 'agent-wordpress-terminal'),
                (string) ($site['name'] ?? ''),
            ),
        ];

        if ([] !== $theme) {
            $lines = array_merge($lines, $this->theme_lines($theme));
        }

        if ([] !== $discussion) {
            $lines[] = sprintf(
                /* translators: %s: open or closed */
                __('- New posts allow comments: %s', 'agent-wordpress-terminal'),
                $comments_open
                    ? __('yes (open)', 'agent-wordpress-terminal')
                    : __('no (closed)', 'agent-wordpress-terminal'),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $theme
     * @return list<string>
     */
    private function theme_lines(array $theme): array {
        $lines = [sprintf(
            /* translators: %s: theme name */
            __('- Active theme: %s', 'agent-wordpress-terminal'),
            (string) ($theme['name'] ?? ''),
        )];

        if ('' !== (string) ($theme['version'] ?? '')) {
            $lines[] = sprintf(
                /* translators: %s: theme version */
                __('- Theme version: %s', 'agent-wordpress-terminal'),
                (string) $theme['version'],
            );
        }

        if ('' !== (string) ($theme['stylesheet'] ?? '')) {
            $lines[] = sprintf(
                /* translators: %s: theme stylesheet slug */
                __('- Theme stylesheet: %s', 'agent-wordpress-terminal'),
                (string) $theme['stylesheet'],
            );
        }

        return $lines;
    }
}
