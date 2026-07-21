<?php

/**
 * Individual remediation hint rules.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Builds optional remediation hints from diagnosis context.
 */
final class RemediationHintRules {
    private PluginInventory $inventory;

    private RemediationBasicHints $basic;

    public function __construct(?PluginInventory $inventory = null) {
        $this->inventory = $inventory ?? new PluginInventory();
        $this->basic = new RemediationBasicHints();
    }

    /**
     * @param array<string, mixed> $context
     * @return list<?array<string, mixed>>
     */
    public function candidates(array $context): array {
        /** @var list<?array<string, mixed>> $candidates */
        $candidates = [
            $this->basic->probe_url($context),
            $this->basic->check_site_health($context),
            $this->basic->fix_content($context),
            $this->basic->retry_action($context),
            $this->basic->increase_memory($context),
            $this->basic->switch_theme($context),
            $this->deactivate_plugin($context),
        ];

        return $candidates;
    }

    private function deactivate_plugin(array $context): ?array {
        $primary = $this->basic->primary_suspect(\AWPT\Support\ArrayKey::string_map($context));

        if (null === $primary || 'plugin' !== ($primary['kind'] ?? '') || 'high' !== ($primary['confidence'] ?? '')) {
            return null;
        }

        $error_type = $context['error_type'] ?? null;
        $error_type = is_string($error_type) ? $error_type : null;

        if (!in_array($error_type, ['php_fatal', 'php_exception'], true)) {
            return null;
        }

        $slug = (string) ($primary['slug'] ?? '');
        $evidence_lines = is_array($context['evidence'] ?? null) ? $context['evidence'] : [];
        $evidence = array_values(array_map(static fn(mixed $line): string => (string) $line, $evidence_lines));

        if (!$this->evidence_mentions_plugin($evidence, $slug)) {
            return null;
        }

        $file = $this->inventory->file_for_slug($slug);

        if (null === $file || str_contains($file, 'agent-wordpress-terminal/')) {
            return null;
        }

        return [
            'type' => 'deactivate_plugin',
            'plugin_file' => $file,
            'plugin_slug' => $slug,
            'confidence' => 'unambiguous',
            'reason' => sprintf(
                /* translators: %s: plugin slug */
                __(
                    'Fatal PHP error trace unambiguously points to plugin %s; stage deactivation only if other fixes are ruled out.',
                    'agent-wordpress-terminal',
                ),
                $slug,
            ),
        ];
    }

    /**
     * @param list<string> $evidence
     */
    private function evidence_mentions_plugin(array $evidence, string $slug): bool {
        if ('' === $slug) {
            return false;
        }

        $needle = 'wp-content/plugins/' . $slug . '/';

        foreach ($evidence as $line) {
            if (str_contains(strtolower($line), strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
