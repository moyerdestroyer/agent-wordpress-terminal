<?php

/**
 * Shared evaluate→act runner for CLI and scenario harnesses.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Agent\AgentRuntime;

if (!defined('ABSPATH')) {
    exit();
}

final class ImproveWorkflowRunner {
    /**
     * @return array{evaluate: array<string, mixed>|\WP_Error, act: array<string, mixed>|\WP_Error|null, plan: string}
     */
    public function run(
        int $session_id,
        int $post_id,
        string $base_turn,
        ?AgentRuntime $runtime = null,
        string $evaluation_context = '',
    ): array {
        $runtime ??= new AgentRuntime();
        $evaluate_message = ImprovePagePrompt::evaluate_text();
        if ('' !== trim($evaluation_context)) {
            $evaluate_message .= "\n\n## Task constraint\n" . trim($evaluation_context);
        }
        $evaluate = $runtime->handle_message($session_id, $evaluate_message, [
            'turn_id' => $base_turn . '-eval',
            'focus_post_id' => $post_id,
            'workflow' => ['type' => 'improve', 'phase' => 'evaluate'],
        ]);
        if (is_wp_error($evaluate)) {
            return ['evaluate' => $evaluate, 'act' => null, 'plan' => ''];
        }
        $workflow = is_array($evaluate['improve_workflow'] ?? null) ? $evaluate['improve_workflow'] : [];
        if ('plan_ready' !== (string) ($workflow['state'] ?? '') || '' === (string) ($workflow['id'] ?? '')) {
            return [
                'evaluate' => $evaluate,
                'act' => new \WP_Error(
                    'awpt_improve_plan_missing',
                    __('The evaluation did not produce an executable plan.', 'agent-wordpress-terminal'),
                    ['status' => 409],
                ),
                'plan' => (string) ($evaluate['content'] ?? ''),
            ];
        }
        $act = $runtime->handle_message($session_id, ImprovePagePrompt::act_text(), [
            'turn_id' => $base_turn . '-act',
            'focus_post_id' => $post_id,
            'workflow' => ['id' => (string) $workflow['id'], 'type' => 'improve', 'phase' => 'act'],
        ]);

        return ['evaluate' => $evaluate, 'act' => $act, 'plan' => (string) ($workflow['plan'] ?? '')];
    }
}
