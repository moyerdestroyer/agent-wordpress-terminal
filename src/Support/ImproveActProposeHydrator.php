<?php

/**
 * Fills missing Improve-act propose fields from the durable workflow unit.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Database\ImproveWorkflowRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Server-side hydration so sparse model propose calls still carry path/intent/pattern_name.
 * Never invents preparation_id.
 */
final class ImproveActProposeHydrator {
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function hydrate(int $session_id, array $input, string $tool_name): array {
        if ($session_id <= 0) {
            return $input;
        }

        if (!in_array(
            $tool_name,
            [
                'awpt/propose-pattern-replace',
                'awpt/propose-pattern-insert',
                'awpt/propose-block-batch-update',
            ],
            true,
        )) {
            return $input;
        }

        $workflow = new ImproveWorkflowRepository()->get($session_id);

        if (!is_array($workflow)) {
            return $input;
        }

        $state = (string) ($workflow['state'] ?? '');

        if (!in_array($state, ['acting', 'plan_ready', 'staged'], true)) {
            return $input;
        }

        $unit = ImprovePagePrompt::current_unit($workflow);

        if (null === $unit) {
            return $input;
        }

        $path = $this->first_path($input);

        if ('' === $path) {
            $unit_paths = is_array($unit['paths'] ?? null) ? $unit['paths'] : [];
            $unit_path = trim((string) ($unit_paths[0] ?? ''));

            if ('' !== $unit_path) {
                $input['path'] = $unit_path;
                $input['target_path'] = $unit_path;

                if ('document' === $unit_path) {
                    $input['replace_entire_document'] = true;
                }
            }
        } else {
            $normalized = $this->normalize_path_alias($path);

            if ($normalized !== $path) {
                $input['path'] = $normalized;
                $input['target_path'] = $normalized;
                $input['replace_entire_document'] = true;
            }
        }

        if ('' === trim((string) ($input['intent'] ?? ''))) {
            $intent = trim((string) ($unit['brief'] ?? ''));

            if ('' === $intent) {
                $intent = trim((string) ($unit['title'] ?? ''));
            }

            if ('' === $intent) {
                $intent = trim((string) ($workflow['plan'] ?? ''));

                if (strlen($intent) > 500) {
                    $intent = rtrim(substr($intent, 0, 497)) . '...';
                }
            }

            if ('' !== $intent) {
                $input['intent'] = $intent;
            }
        }

        if (
            in_array($tool_name, ['awpt/propose-pattern-replace', 'awpt/propose-pattern-insert'], true)
            && '' === trim((string) ($input['pattern_name'] ?? ''))
        ) {
            $pattern_name = trim((string) ($unit['pattern_name'] ?? ''));

            if ('' !== $pattern_name) {
                $input['pattern_name'] = $pattern_name;
            }
        }

        if ('' === trim((string) ($input['expected_fingerprint'] ?? $input['fingerprint'] ?? ''))) {
            $fingerprint = trim((string) ($unit['expected_fingerprint'] ?? ''));

            if ('' !== $fingerprint) {
                $input['expected_fingerprint'] = $fingerprint;
            }
        }

        return $input;
    }

    /** @param array<string, mixed> $input */
    private function first_path(array $input): string {
        foreach (['path', 'target_path', 'block_path'] as $key) {
            $path = trim((string) ($input[$key] ?? ''));

            if ('' !== $path) {
                return $path;
            }
        }

        return '';
    }

    private function normalize_path_alias(string $path): string {
        $alias = strtolower(trim($path));

        if ('document' === $alias) {
            return 'document';
        }

        return $path;
    }
}
