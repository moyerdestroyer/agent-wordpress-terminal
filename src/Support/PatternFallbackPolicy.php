<?php

/**
 * Pattern-first preference for substantial page composition (advisory only).
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Kept as a stable extension point. Pattern-first is preferred in prompts;
 * this policy never blocks staging.
 */
final class PatternFallbackPolicy {
    public function validate(
        PatternCatalog $patterns,
        string $post_type,
        string $pattern_owner,
        string $reason,
        string $unfit_code = '',
    ): ?\WP_Error {
        unset($patterns, $post_type, $pattern_owner, $reason, $unfit_code);

        return null;
    }
}
