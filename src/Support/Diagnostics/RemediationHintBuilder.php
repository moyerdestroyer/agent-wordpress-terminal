<?php

/**
 * Builds prose-only remediation hints from diagnosis context.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

use AWPT\Support\Diagnostics\Hints\ActionHints;
use AWPT\Support\Diagnostics\Hints\PluginDeactivateHint;
use AWPT\Support\Diagnostics\Hints\UrlHealthHints;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Emits ordered suggested_remediations hints for awpt/diagnose-error.
 */
final class RemediationHintBuilder {
    /**
     * @var list<string>
     */
    private const HINT_ORDER = [
        'probe_url',
        'check_site_health',
        'fix_content',
        'retry_action',
        'increase_memory',
        'switch_theme',
        'deactivate_plugin',
    ];

    private PluginInventory $inventory;

    public function __construct(?PluginInventory $inventory = null) {
        $this->inventory = $inventory ?? new PluginInventory();
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public function build(array $context): array {
        $hints = array_values(array_filter(
            [
                UrlHealthHints::probe_url($context),
                UrlHealthHints::check_site_health($context),
                ActionHints::fix_content($context),
                ActionHints::retry_action($context),
                UrlHealthHints::increase_memory($context),
                ActionHints::switch_theme($context),
                PluginDeactivateHint::build($context, $this->inventory),
            ],
            static fn(?array $hint): bool => null !== $hint,
        ));

        return $this->order_hints($hints);
    }

    /**
     * @param list<array<string, mixed>> $hints
     * @return list<array<string, mixed>>
     */
    private function order_hints(array $hints): array {
        $by_type = [];

        foreach ($hints as $hint) {
            $type = (string) ($hint['type'] ?? '');

            if ('' !== $type) {
                $by_type[$type] = $hint;
            }
        }

        $ordered = [];

        foreach (self::HINT_ORDER as $type) {
            if (!array_key_exists($type, $by_type)) {
                continue;
            }

            $ordered[] = $by_type[$type];
        }

        return $ordered;
    }
}
