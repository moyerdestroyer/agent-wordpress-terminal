<?php

/**
 * Grounds proposal tool inputs in the current conversation and open actions.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/** Adds request identity and structured composer evidence without choosing an approach for the agent. */
final class ProposalRequestContext {
    /**
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    public function enrich(int $session_id, array $input, array $turn_context = []): array {
        unset($session_id);
        $turn_id = sanitize_key((string) ($turn_context['turn_id'] ?? ''));

        if ('' !== $turn_id) {
            $input['turn_id'] = $turn_id;
        }

        if (!isset($input['proposal_key']) || '' === trim((string) $input['proposal_key'])) {
            $input['proposal_key'] = 'primary';
        }

        $ids = [];

        foreach (is_array($turn_context['attachments'] ?? null) ? $turn_context['attachments'] : [] as $attachment) {
            if (is_array($attachment) && (int) ($attachment['id'] ?? 0) > 0) {
                $ids[] = (int) $attachment['id'];
            }
        }

        if ([] !== $ids) {
            $input['available_attachment_ids'] = array_values(array_unique($ids));
        }

        return $input;
    }
}
