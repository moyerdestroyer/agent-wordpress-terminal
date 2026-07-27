<?php

/**
 * Reusable session evidence for immediate task retries.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Database\ActionRepository;
use AWPT\Database\MessageRepository;
use AWPT\Support\PatternCatalog;

if (!defined('ABSPATH')) {
    exit();
}

final class SessionDiscoveryEvidence {
    public function for_prompt(int $session_id, string $message): string {
        if (!$this->is_retry_or_revision($session_id, $message)) {
            return 'Reusable discovery from prior session turns: none needed for this request.';
        }

        $calls = array_reverse(new MessageRepository()->recent_tool_calls($session_id));
        $pattern = null;
        $media = [];

        foreach ($calls as $call) {
            if ('success' !== $call['status']) {
                continue;
            }

            if (null === $pattern && 'awpt/read-pattern' === $call['tool']) {
                $pattern = $this->validated_pattern($call['output']);
            }

            if ([] === $media && 'awpt/list-content' === $call['tool']) {
                if ('attachment' !== (string) ($call['input']['post_type'] ?? '')) {
                    continue;
                }

                $items = is_array($call['output']['items'] ?? null) ? $call['output']['items'] : [];
                $media = array_slice($items, 0, 8);
            }

            if (null !== $pattern && [] !== $media) {
                break;
            }
        }

        if (null === $pattern && [] === $media) {
            return 'Reusable discovery from prior session turns: none available.';
        }

        $encoded = wp_json_encode(array_filter([
            'selected_pattern' => $pattern,
            'media_candidates' => $media,
        ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return (
            "Reusable verified discovery from the current session task chain. Reuse it instead of rediscovering:\n"
            . (is_string($encoded) ? $encoded : '{}')
        );
    }

    private function is_retry_or_revision(int $session_id, string $message): bool {
        if ((bool) preg_match('/\b(try again|retry|once more|keep going|finish it|continue)\b/i', $message)) {
            return true;
        }

        return (
            (bool) preg_match('/\b(revise|update|change|improve|fix)\b/i', $message)
            && null !== new ActionRepository()->latest_open_new_post_for_session($session_id)
        );
    }

    /** @param array<array-key, mixed> $stored @return array<string, mixed>|null */
    private function validated_pattern(array $stored): ?array {
        $name = (string) ($stored['name'] ?? '');

        if ('' === $name) {
            return null;
        }

        $catalog = new PatternCatalog();
        $current = $catalog->find($name);

        if (null === $current) {
            return null;
        }

        $content = (string) ($current['content'] ?? '');
        $stored_hash = (string) ($stored['content_hash'] ?? '');

        if ('' !== $stored_hash && !hash_equals($stored_hash, hash('sha256', $content))) {
            return null;
        }

        return [
            'name' => $name,
            'title' => (string) ($stored['title'] ?? ''),
            'composition_scope' => (string) ($stored['composition_scope'] ?? ''),
            'content_hash' => hash('sha256', $content),
            'content' => mb_substr($content, 0, 24_000),
        ];
    }
}
