<?php

/**
 * Grounds proposal tool inputs in the current conversation and open actions.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\ActionRepository;

if (!defined('ABSPATH')) {
    exit();
}

/** Adds request identity, revision targets, and structured composer evidence. */
final class ProposalRequestContext {
    /**
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    public function enrich(int $session_id, array $input, array $turn_context = []): array {
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
            // Composer paste means the admin already chose these assets for this turn.
            // Promote them to required inline evidence so featured-image-only proposals fail closed.
            $existing_required = is_array($input['required_attachment_ids'] ?? null)
                ? $input['required_attachment_ids']
                : [];
            $merged = [];

            foreach ([...$existing_required, ...$ids] as $raw_id) {
                $id = absint(is_scalar($raw_id) ? $raw_id : 0);

                if ($id > 0) {
                    $merged[] = $id;
                }
            }

            $input['required_attachment_ids'] = array_values(array_unique($merged));
        }

        // Surface the auto-bound revise target on the tool input (transcript + ability).
        if ($session_id > 0 && (int) ($input['action_id'] ?? 0) <= 0) {
            $resolved = new ActionRepository()->resolve_revisable_new_post_id(
                $session_id,
                sanitize_key((string) ($input['post_type'] ?? '')),
                (string) ($input['post_title'] ?? ''),
            );

            if ($resolved > 0) {
                $input['action_id'] = $resolved;
            }
        }

        return $input;
    }
}
