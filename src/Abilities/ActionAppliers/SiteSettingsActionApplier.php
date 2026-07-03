<?php

/**
 * Applies staged site settings updates.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities\ActionAppliers;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Applies staged WordPress option changes.
 */
final class SiteSettingsActionApplier
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    public function apply(array $payload): array|\WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new \WP_Error('awpt_cannot_manage_options', __(
                'You do not have permission to update site settings.',
                'agent-wordpress-terminal',
            ));
        }

        $changes = is_array($payload['settings_changes'] ?? null) ? $payload['settings_changes'] : [];

        if ([] === $changes) {
            return new \WP_Error('awpt_empty_action', __(
                'Action has no site setting changes to apply.',
                'agent-wordpress-terminal',
            ));
        }

        $updated = [];

        foreach (array_keys($changes) as $option) {
            if (!is_string($option)) {
                continue;
            }

            $this->update_option($option, $changes[$option]);
            $updated[] = $option;
        }

        if (array_intersect($updated, ['permalink_structure', 'category_base', 'tag_base']) !== []) {
            flush_rewrite_rules(false);
        }

        return ['settings' => $updated];
    }

    private function update_option(string $option, mixed $value): void
    {
        if ('permalink_structure' !== $option) {
            update_option($option, $value);
            return;
        }

        global $wp_rewrite;

        if ($wp_rewrite instanceof \WP_Rewrite) {
            $wp_rewrite->set_permalink_structure((string) $value);
            return;
        }

        update_option($option, (string) $value);
    }
}
