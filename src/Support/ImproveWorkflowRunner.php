<?php

/**
 * Shared evaluate→act runner for CLI and scenario harnesses.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Abilities\ApplyAction;
use AWPT\Agent\AgentRuntime;
use AWPT\Database\ImproveWorkflowRepository;

if (!defined('ABSPATH')) {
    exit();
}

final class ImproveWorkflowRunner {
    public const MAX_ACT_TURNS = 8;

    /**
     * Run evaluate then one or more act turns.
     *
     * When options.auto_continue is true (CLI / matrix default), each successful act is
     * applied and the next unit runs until the plan is exhausted or MAX_ACT_TURNS.
     * Review UI uses the same unit cursor via apply → plan_ready → continue.
     *
     * @return array{
     *     evaluate: array<string, mixed>|\WP_Error,
     *     act: array<string, mixed>|\WP_Error|null,
     *     acts: list<array<string, mixed>|\WP_Error>,
     *     plan: string
     * }
     * @param array{evaluation_context?: string, auto_continue?: bool} $options
     */
    public function run(
        int $session_id,
        int $post_id,
        string $base_turn,
        ?AgentRuntime $runtime = null,
        array $options = [],
    ): array {
        $runtime ??= new AgentRuntime();
        $evaluation_context = $options['evaluation_context'] ?? '';
        $auto_continue = true === ($options['auto_continue'] ?? false);
        $post = get_post($post_id);
        $title = $post instanceof \WP_Post ? $post->post_title : '';
        // evaluation_context is the operator/reviewer request (same surface as Review UI notes).
        $evaluate_message = ImprovePagePrompt::review_evaluate_message($post_id, $title, $evaluation_context);
        $evaluate = $runtime->handle_message($session_id, $evaluate_message, [
            'turn_id' => $base_turn . '-eval',
            'focus_post_id' => $post_id,
            'workflow' => ['type' => 'improve', 'phase' => 'evaluate'],
        ]);
        if (is_wp_error($evaluate)) {
            return ['evaluate' => $evaluate, 'act' => null, 'acts' => [], 'plan' => ''];
        }
        $workflow = is_array($evaluate['improve_workflow'] ?? null) ? $evaluate['improve_workflow'] : [];
        if ('no_change' === (string) ($workflow['state'] ?? '')) {
            return [
                'evaluate' => $evaluate,
                'act' => null,
                'acts' => [],
                'plan' => (string) ($workflow['plan'] ?? $evaluate['content'] ?? ''),
            ];
        }
        if ('plan_ready' !== (string) ($workflow['state'] ?? '') || '' === (string) ($workflow['id'] ?? '')) {
            return [
                'evaluate' => $evaluate,
                'act' => new \WP_Error(
                    'awpt_improve_plan_missing',
                    __('The evaluation did not produce an executable plan.', 'agent-wordpress-terminal'),
                    ['status' => 409],
                ),
                'acts' => [],
                'plan' => (string) ($evaluate['content'] ?? ''),
            ];
        }

        $acts = [];
        $workflow_id = (string) $workflow['id'];
        $limit = $auto_continue ? self::MAX_ACT_TURNS : 1;

        for ($index = 0; $index < $limit; ++$index) {
            $act = $runtime->handle_message($session_id, ImprovePagePrompt::act_text(), [
                'turn_id' => $base_turn . '-act' . ($index > 0 ? '-' . $index : ''),
                'focus_post_id' => $post_id,
                'workflow' => ['id' => $workflow_id, 'type' => 'improve', 'phase' => 'act'],
            ]);
            $acts[] = $act;

            if (is_wp_error($act) || !$auto_continue) {
                break;
            }

            if (!$this->apply_act_actions($act) || !$this->workflow_has_remaining($session_id)) {
                break;
            }
        }

        $last = [] !== $acts ? $acts[array_key_last($acts)] : null;

        return [
            'evaluate' => $evaluate,
            'act' => $last,
            'acts' => $acts,
            'plan' => (string) ($workflow['plan'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $act */
    private function apply_act_actions(array $act): bool {
        $actions = is_array($act['actions'] ?? null) ? $act['actions'] : [];

        if ([] === $actions) {
            return false;
        }

        $applier = new ApplyAction();

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $action_id = (int) ($action['id'] ?? 0);

            if ($action_id <= 0) {
                continue;
            }

            $applied = $applier->execute(['action_id' => $action_id]);

            if (is_wp_error($applied)) {
                return false;
            }
        }

        return true;
    }

    private function workflow_has_remaining(int $session_id): bool {
        $workflow = new ImproveWorkflowRepository()->get($session_id);

        return is_array($workflow) && ImproveWorkflowRepository::has_remaining_units($workflow);
    }
}
