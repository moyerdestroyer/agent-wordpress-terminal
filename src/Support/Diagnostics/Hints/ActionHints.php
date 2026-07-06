<?php

/**
 * Action and theme remediation hints.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics\Hints;

use AWPT\Support\ActionOperations;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * fix_content, retry_action, and switch_theme hint rules.
 */
final class ActionHints {
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public static function fix_content(array $context): ?array {
        $attempted_action = (string) ($context['attempted_action'] ?? '');
        $fix_actions = [
            ActionOperations::CONTENT_UPDATE,
            ActionOperations::BLOCK_ATTRS_UPDATE,
            ActionOperations::NEW_POST,
            ActionOperations::SITE_SETTINGS_UPDATE,
        ];

        if (!in_array($attempted_action, $fix_actions, true)) {
            return null;
        }

        return [
            'type' => 'fix_content',
            'attempted_action' => $attempted_action,
            'reason' => sprintf(
                /* translators: %s: staged action operation */
                __('Revise the staged %s proposal or gather more context before retrying.', 'agent-wordpress-terminal'),
                $attempted_action,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public static function retry_action(array $context): ?array {
        $attempted_action = (string) ($context['attempted_action'] ?? '');

        if ('' === $attempted_action) {
            return null;
        }

        return [
            'type' => 'retry_action',
            'attempted_action' => $attempted_action,
            'reason' => sprintf(
                /* translators: %s: action or tool name */
                __(
                    'Retry or adjust the attempted %s after addressing the underlying cause.',
                    'agent-wordpress-terminal',
                ),
                $attempted_action,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public static function switch_theme(array $context): ?array {
        $primary = self::primary_suspect($context);

        if (null === $primary || 'theme' !== ($primary['kind'] ?? '') || 'high' !== ($primary['confidence'] ?? '')) {
            return null;
        }

        return [
            'type' => 'switch_theme',
            'theme_slug' => (string) ($primary['slug'] ?? ''),
            'reason' => sprintf(
                /* translators: %s: theme slug */
                __(
                    'Error trace points at theme %s; consider staging awpt/propose-theme-switch to a default theme.',
                    'agent-wordpress-terminal',
                ),
                (string) ($primary['slug'] ?? ''),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private static function primary_suspect(array $context): ?array {
        $suspects = is_array($context['suspects'] ?? null) ? $context['suspects'] : [];
        $primary = $suspects[0] ?? null;

        if (!is_array($primary)) {
            return null;
        }

        /** @var array<string, mixed> $primary */
        return $primary;
    }
}
