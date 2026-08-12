<?php

/**
 * Grounds proposal tool inputs in the current conversation and open actions.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\ActionRepository;
use AWPT\Database\MessageRepository;
use AWPT\Database\SessionRepository;
use AWPT\Support\ActionOperations;

if (!defined('ABSPATH')) {
    exit();
}

/** Adds request identity, revision targets, and structured composer evidence. */
final class ProposalRequestContext {
    /**
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    public function enrich(int $session_id, array $input, array $turn_context = [], string $tool_name = ''): array {
        $turn_id = sanitize_key((string) ($turn_context['turn_id'] ?? ''));

        if ('' !== $turn_id) {
            $input['turn_id'] = $turn_id;
        }

        if (!isset($input['proposal_key']) || '' === trim((string) $input['proposal_key'])) {
            $input['proposal_key'] = 'primary';
        }

        $image_ids = [];
        $document_ids = [];

        foreach (is_array($turn_context['attachments'] ?? null) ? $turn_context['attachments'] : [] as $attachment) {
            if (!(is_array($attachment) && (int) ($attachment['id'] ?? 0) > 0)) {
                continue;
            }

            $id = (int) $attachment['id'];
            $mime = strtolower((string) ($attachment['mime_type'] ?? get_post_mime_type($id)));
            $url_path = strtolower((string) parse_url((string) ($attachment['url'] ?? ''), PHP_URL_PATH));
            $looks_like_image = (bool) preg_match('/\.(?:avif|gif|jpe?g|png|webp)$/', $url_path);

            if (str_starts_with($mime, 'image/') || wp_attachment_is_image($id) || $looks_like_image) {
                $image_ids[] = $id;
            } else {
                $document_ids[] = $id;
            }
        }

        if ([] !== $image_ids) {
            $input['available_attachment_ids'] = array_values(array_unique($image_ids));
            // Composer paste means the admin already chose these assets for this turn.
            // Promote them to required inline evidence so featured-image-only proposals fail closed.
            $existing_required = $this->positive_ids($input['required_attachment_ids'] ?? null);
            $input['required_attachment_ids'] = array_values(array_unique([...$existing_required, ...$image_ids]));
        }

        if ([] !== $document_ids) {
            $input['available_document_ids'] = array_values(array_unique($document_ids));
            $existing_documents = $this->positive_ids($input['required_document_ids'] ?? null);
            $input['required_document_ids'] = array_values(array_unique([
                ...$existing_documents,
                ...$document_ids,
            ]));
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

        if ($this->is_existing_content_ability($tool_name)) {
            $input = $this->enrich_content_edit_defaults($session_id, $input, $turn_context);

            if (true === ($turn_context['presentation_requires_h1'] ?? false)) {
                $input['presentation_requires_h1'] = true;
            }
        } else {
            $input = $this->enrich_action_card_defaults($session_id, $input, $turn_context);
        }
        $user_message = trim((string) ($turn_context['user_message'] ?? ''));

        if ('' === $user_message && $session_id > 0) {
            $user_message = trim(new MessageRepository()->latest_user_message($session_id));
        }

        return $this->ground_composition_minimums($input, $user_message);
    }

    private function is_existing_content_ability(string $tool_name): bool {
        // Keep direct callers of the context helper backward-compatible. The
        // runtime always supplies a concrete ability name.
        if ('' === $tool_name) {
            return true;
        }

        return in_array(
            $tool_name,
            [
                'awpt/propose-content-update',
                'awpt/propose-block-attrs-update',
                'awpt/propose-block-batch-update',
                'awpt/propose-block-insert',
                'awpt/propose-block-remove',
                'awpt/propose-pattern-insert',
                'awpt/propose-pattern-replace',
            ],
            true,
        );
    }

    /**
     * Creation abilities still benefit from reliable action-card labels, but a
     * focused session must not silently turn their new draft into a page edit.
     *
     * @param array<array-key, mixed> $input
     * @param array<array-key, mixed> $turn_context
     * @return array<array-key, mixed>
     */
    private function enrich_action_card_defaults(int $session_id, array $input, array $turn_context): array {
        $user_message = trim((string) ($turn_context['user_message'] ?? ''));

        if ('' === $user_message && $session_id > 0) {
            $user_message = trim(new MessageRepository()->latest_user_message($session_id));
        }

        $default_label = $this->default_action_label($user_message, 0);

        if ('' === trim((string) ($input['title'] ?? ''))) {
            $input['title'] = $default_label;
        }

        if ('' === trim((string) ($input['description'] ?? ''))) {
            $input['description'] = '' !== $user_message ? $user_message : $default_label;
        }

        return $input;
    }

    /**
     * Minimum-count fields are validation constraints, not creative wishes.
     * Accept them only when the user supplied an exact count; otherwise a model
     * can accidentally turn its own decorative idea into a staging blocker.
     *
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    private function ground_composition_minimums(array $input, string $user_message): array {
        $image_count = $this->explicit_count($user_message, '(?:media\s+library\s+)?(?:images?|photos?|pictures?)');
        $visual_count = $this->explicit_count($user_message, '(?:visuals?|icons?|illustrations?)');

        if (null === $image_count) {
            unset($input['required_minimum_library_images']);
        } else {
            $input['required_minimum_library_images'] = $image_count;
        }

        if (null === $visual_count) {
            unset($input['required_minimum_visuals']);
        } else {
            $input['required_minimum_visuals'] = $visual_count;
        }

        return $input;
    }

    private function explicit_count(string $message, string $noun_pattern): ?int {
        $matches = [];

        if (!preg_match(
            '/\b(\d{1,3}|one|two|three|four|five|six|seven|eight|nine|ten)\s+(?:distinct\s+|different\s+)?'
            . $noun_pattern
            . '\b/i',
            $message,
            $matches,
        )) {
            return null;
        }

        $raw = strtolower($matches[1] ?? '');
        $words = [
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            'six' => 6,
            'seven' => 7,
            'eight' => 8,
            'nine' => 9,
            'ten' => 10,
        ];

        return max(1, min(100, $words[$raw] ?? (int) $raw));
    }

    /**
     * Fill post_id / action-card fields models often omit on short follow-ups.
     *
     * @param array<array-key, mixed> $input
     * @param array<array-key, mixed> $turn_context
     * @return array<array-key, mixed>
     */
    private function enrich_content_edit_defaults(int $session_id, array $input, array $turn_context): array {
        $user_message = trim((string) ($turn_context['user_message'] ?? ''));

        if ('' === $user_message && $session_id > 0) {
            $user_message = trim(new MessageRepository()->latest_user_message($session_id));
        }

        if ((int) ($input['post_id'] ?? 0) <= 0) {
            $resolved_post_id = $this->resolve_post_id($session_id, $user_message);

            if ($resolved_post_id > 0) {
                $input['post_id'] = $resolved_post_id;
            }
        }

        $post_id = (int) ($input['post_id'] ?? 0);
        $default_label = $this->default_action_label($user_message, $post_id);

        if ('' === trim((string) ($input['title'] ?? ''))) {
            $input['title'] = $default_label;
        }

        if ('' === trim((string) ($input['description'] ?? ''))) {
            $input['description'] = '' !== $user_message ? $user_message : $default_label;
        }

        return $input;
    }

    private function resolve_post_id(int $session_id, string $user_message): int {
        if ($session_id > 0) {
            $session = new SessionRepository()->get_summary($session_id);
            $focus = (int) ($session['focus_post_id'] ?? 0);

            if ($focus > 0) {
                return $focus;
            }

            $actions = new ActionRepository();

            foreach ($actions->list_open_for_session($session_id, 15) as $action) {
                $payload = $actions->decode_payload($action);
                $operation = (string) ($payload['operation'] ?? '');

                if (!in_array(
                    $operation,
                    [
                        ActionOperations::CONTENT_UPDATE,
                        ActionOperations::BLOCK_ATTRS_UPDATE,
                        ActionOperations::BLOCK_INSERT,
                        ActionOperations::BLOCK_REMOVE,
                        ActionOperations::PATTERN_INSERT,
                    ],
                    true,
                )) {
                    continue;
                }

                $post_id = (int) ($payload['post_id'] ?? 0);

                if ($post_id > 0) {
                    return $post_id;
                }
            }
        }

        $matches = [];

        if ('' !== $user_message && (bool) preg_match('/\b(?:page|post)\s*#?\s*(\d+)\b/i', $user_message, $matches)) {
            return absint($matches[1] ?? 0);
        }

        return 0;
    }

    private function default_action_label(string $user_message, int $post_id): string {
        $message = trim(preg_replace('/\s+/u', ' ', $user_message) ?? $user_message);

        if ('' !== $message) {
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($message) > 80) {
                    return rtrim(mb_substr($message, 0, 77)) . '…';
                }

                return $message;
            }

            if (strlen($message) > 80) {
                return rtrim(substr($message, 0, 77)) . '…';
            }

            return $message;
        }

        if ($post_id > 0) {
            return sprintf(
                /* translators: %d: post ID. */
                __('Content update for post #%d', 'agent-wordpress-terminal'),
                $post_id,
            );
        }

        return __('Content update', 'agent-wordpress-terminal');
    }

    /**
     * @return list<int>
     */
    private function positive_ids(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach (array_keys($value) as $key) {
            $id = $this->positive_id($value[$key] ?? null);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function positive_id(mixed $value): int {
        return absint(is_scalar($value) ? $value : 0);
    }
}
