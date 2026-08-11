import type { ChatResponse, ImproveWorkflow } from './types';

type ImproveWorkflowRunner = {
	evaluate: () => Promise<ChatResponse | null>;
	act: (workflow: ImproveWorkflow) => Promise<ChatResponse | null>;
};

/** Shared two-turn protocol used by the terminal and review bridge. */
export async function runImproveWorkflow({
	evaluate,
	act,
}: ImproveWorkflowRunner): Promise<{ evaluate: ChatResponse; act: ChatResponse | null } | null> {
	const evaluation = await evaluate();
	if (!evaluation || evaluation.turn_outcome?.status === 'failed') {
		return null;
	}
	const workflow = evaluation.improve_workflow;
	if (workflow?.state !== 'plan_ready' || workflow.plan.trim() === '') {
		return null;
	}

	return { evaluate: evaluation, act: await act(workflow) };
}
