<?php

/**
 * Parallel-safety classification for agent tool batches.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ProposalAbilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Decides which tools may share a concurrent batch and which must stay serial.
 */
final class ToolParallelism {
    /**
     * Read tools that do not mutate staged actions or depend on in-batch
     * knowledge novelty state. Safe to run side-by-side when independent.
     *
     * @var list<string>
     */
    private const PARALLEL_SAFE = [
        'core/get-site-info',
        'core/get-user-info',
        'core/get-environment-info',
        'core/read-settings',
        'awpt/list-content',
        'awpt/search-content',
        'awpt/read-content',
        'core/read-content',
        'awpt/find-abilities',
        'awpt/read-proposal',
        'awpt/list-knowledge-sources',
        'awpt/read-knowledge',
        'awpt/read-themes',
        'awpt/read-theme-json',
        'awpt/read-theme-file',
        'awpt/list-patterns',
        'awpt/read-pattern',
        'awpt/list-templates',
        'awpt/read-template',
        'awpt/read-global-styles',
        'awpt/read-block-tree',
        'awpt/list-blocks',
        'awpt/get-block',
        'awpt/render-block',
        'awpt/inspect-frontend',
        'awpt/analyze-page',
        'awpt/preview-post',
        'awpt/read-error-log',
        'awpt/read-plugins',
        'awpt/read-site-health',
        'awpt/probe-url',
    ];

    public function is_serial_only(?string $tool_name): bool {
        if (null === $tool_name || '' === $tool_name) {
            return true;
        }

        if (ProposalAbilities::is_proposal($tool_name)) {
            return true;
        }

        // Knowledge novelty state is session-scoped on the executor; keep ordered.
        if ('awpt/search-knowledge' === $tool_name) {
            return true;
        }

        return 'awpt/diagnose-error' === $tool_name;
    }

    public function is_parallel_safe(?string $tool_name): bool {
        if (null === $tool_name || $this->is_serial_only($tool_name)) {
            return false;
        }

        return in_array($tool_name, self::PARALLEL_SAFE, true);
    }

    /**
     * Max concurrent workers for a parallel-safe wave.
     */
    public function max_concurrency(): int {
        $cap = (int) apply_filters('awpt_tool_batch_concurrency', 4);

        return max(1, min(8, $cap));
    }
}
