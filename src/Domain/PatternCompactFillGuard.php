<?php

/**
 * Blocks bare pattern replaces that would wipe real page copy with stock text.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * When the target section has substantive copy and the pattern still has editable
 * text slots, empty pattern_text_updates would stage unmodified starter filler.
 */
final class PatternCompactFillGuard {
    private const MIN_EXCERPT_LENGTH = 40;

    /**
     * @param array<string, mixed> $receipt
     * @param list<mixed> $pattern_text_updates
     */
    public function should_block_replace(array $receipt, array $pattern_text_updates): bool {
        if (!$this->updates_empty($pattern_text_updates)) {
            return false;
        }

        $content = (string) ($receipt['pattern_content'] ?? '');

        if ('' === trim($content)) {
            return false;
        }

        if ([] === new PatternEditableSlots()->from_content($content)) {
            return false;
        }

        return $this->target_is_substantive(ArrayKey::as_map($receipt['carry_forward'] ?? null));
    }

    /**
     * Whether prepare/propose should treat the target as having real copy to preserve.
     *
     * @param array<string, mixed> $carry_forward
     */
    public function target_is_substantive(array $carry_forward): bool {
        if ('' !== trim((string) ($carry_forward['heading'] ?? ''))) {
            return true;
        }

        $excerpt = trim((string) ($carry_forward['excerpt'] ?? ''));

        if (mb_strlen($excerpt, 'UTF-8') >= self::MIN_EXCERPT_LENGTH) {
            return true;
        }

        if ([] !== ArrayKey::list_of_strings($carry_forward['links'] ?? null)) {
            return true;
        }

        return [] !== ArrayKey::list_of_strings($carry_forward['numeric_tokens'] ?? null);
    }

    /** @param list<mixed> $pattern_text_updates */
    private function updates_empty(array $pattern_text_updates): bool {
        foreach ($pattern_text_updates as $update) {
            if (!is_array($update)) {
                continue;
            }

            $path = trim((string) ($update['block_path'] ?? ''));
            $content = (string) ($update['content'] ?? '');

            if ('' !== $path || '' !== trim($content)) {
                return false;
            }
        }

        return true;
    }
}
