<?php

/**
 * Pattern-first helpers: session recommendation evidence and redesign detection.
 *
 * Staging and redesign discovery enforce structure evidence via PatternStructureEvidence.
 * This class remains the shared consult/read scan used by those gates.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Agent\TurnProfile;
use AWPT\Database\MessageRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Session-level pattern consultation helpers. Does not block composition.
 */
final class PatternFirstPolicy {
    /** Optional telemetry codes (not enforced). */
    public const CODE_NO_RECOMMENDATIONS = 'no_recommendations';
    public const CODE_EXPLICIT_BESPOKE = 'explicit_bespoke';
    public const CODE_PRESERVATION_CONFLICT = 'preservation_conflict';
    public const CODE_MEDIA_UNAVAILABLE = 'media_unavailable';
    public const CODE_SCOPE_MISMATCH = 'scope_mismatch';

    /**
     * @param list<array<string, mixed>> $tool_calls
     * @return list<array<string, mixed>>
     */
    public function nonempty_recommendations_from_calls(array $tool_calls): array {
        $recommendations = [];

        foreach ($tool_calls as $call) {
            if (
                'awpt/recommend-patterns' !== (string) ($call['tool'] ?? '')
                || 'success' !== (string) $call['status']
            ) {
                continue;
            }

            $output = is_array($call['output'] ?? null) ? $call['output'] : [];
            foreach (ArrayKey::list_of_maps($output['recommendations'] ?? null) as $item) {
                $recommendations[] = $item;
            }
        }

        return $recommendations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function nonempty_recommendations(int $session_id): array {
        if ($session_id <= 0) {
            return [];
        }

        return $this->nonempty_recommendations_from_calls($this->session_tool_calls($session_id));
    }

    public function session_consulted_empty(int $session_id): bool {
        if ($session_id <= 0) {
            return false;
        }

        $saw_success = false;
        $any_nonempty = false;

        foreach ($this->session_tool_calls($session_id) as $call) {
            if ('awpt/recommend-patterns' !== $call['tool'] || 'success' !== $call['status']) {
                continue;
            }

            $saw_success = true;
            $output = is_array($call['output'] ?? null) ? $call['output'] : [];
            $items = is_array($output['recommendations'] ?? null) ? $output['recommendations'] : [];
            if ([] !== $items) {
                $any_nonempty = true;
            }
        }

        return $saw_success && !$any_nonempty;
    }

    public function session_has_pattern_structure(int $session_id): bool {
        if ($session_id <= 0) {
            return false;
        }

        foreach ($this->session_tool_calls($session_id) as $call) {
            if ('awpt/read-pattern' === $call['tool'] && 'success' === $call['status']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Persisted session tools plus in-request turn evidence (mid-turn proposes).
     *
     * @return list<array{tool: string, input: mixed, output: mixed, status: string}>
     */
    public function session_tool_calls(int $session_id): array {
        if ($session_id <= 0) {
            return [];
        }

        return [
            ...new MessageRepository()->recent_tool_calls($session_id),
            ...TurnToolEvidence::for_session($session_id),
        ];
    }

    public function is_presentation_edit_message(string $message, bool $has_focus = true): bool {
        $message = trim($message);
        if ('' === $message || !$has_focus) {
            return false;
        }

        return TurnProfile::from_message($message, [], ['has_focus' => true])->is_redesign();
    }

    public function is_presentation_edit_session(int $session_id): bool {
        if ($session_id <= 0) {
            return false;
        }

        return $this->is_presentation_edit_message(new MessageRepository()->latest_user_message($session_id));
    }
}
