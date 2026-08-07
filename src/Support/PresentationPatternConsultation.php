<?php

/**
 * Redesign/create pattern consultation helpers (advisory; no hard gates).
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Session helpers for pattern recommendations. Does not block surgical or
 * custom composition — pattern-first is preferred in prompts only.
 */
final class PresentationPatternConsultation {
    private PatternFirstPolicy $policy;

    public function __construct(?PatternFirstPolicy $policy = null) {
        $this->policy = $policy ?? new PatternFirstPolicy();
    }

    /**
     * @param list<array<string, mixed>> $tool_calls
     * @return list<array<string, mixed>>
     */
    public function nonempty_recommendations_from_calls(array $tool_calls): array {
        return $this->policy->nonempty_recommendations_from_calls($tool_calls);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function nonempty_recommendations(int $session_id): array {
        return $this->policy->nonempty_recommendations($session_id);
    }

    public function session_has_nonempty_recommendations(int $session_id): bool {
        return [] !== $this->policy->nonempty_recommendations($session_id);
    }

    public function is_presentation_edit_message(string $message, bool $has_focus = true): bool {
        return $this->policy->is_presentation_edit_message($message, $has_focus);
    }

    public function is_presentation_edit_session(int $session_id): bool {
        return $this->policy->is_presentation_edit_session($session_id);
    }
}
