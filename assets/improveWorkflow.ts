import type { ChatResponse, ImproveWorkflow } from './types';

type ImproveWorkflowRunner = {
	evaluate: () => Promise<ChatResponse | null>;
	act: (workflow: ImproveWorkflow) => Promise<ChatResponse | null>;
	/**
	 * After each successful act, return the next executable workflow to continue
	 * (typically after applying a staged unit), or null to stop.
	 */
	continueAfterAct?: (actResponse: ChatResponse) => Promise<ImproveWorkflow | null>;
	/** Cap auto-continued act turns for one Improve click. */
	maxActTurns?: number;
};

const DEFAULT_MAX_ACT_TURNS = 8;

/** Shared evaluate→act protocol used by the terminal and review bridge. */
export async function runImproveWorkflow({
	evaluate,
	act,
	continueAfterAct,
	maxActTurns = DEFAULT_MAX_ACT_TURNS,
}: ImproveWorkflowRunner): Promise<{ evaluate: ChatResponse; acts: ChatResponse[] } | null> {
	const evaluation = await evaluate();
	if (!evaluation || evaluation.turn_outcome?.status === 'failed') {
		return null;
	}
	let workflow = evaluation.improve_workflow;
	if (workflow?.state === 'no_change') {
		return { evaluate: evaluation, acts: [] };
	}
	if (workflow?.state !== 'plan_ready' || workflow.plan.trim() === '') {
		return null;
	}

	const acts: ChatResponse[] = [];
	const limit = Math.max(1, maxActTurns);

	for (let turn = 0; turn < limit; turn += 1) {
		const response = await act(workflow);
		if (!response) {
			return { evaluate: evaluation, acts };
		}
		acts.push(response);

		if (!continueAfterAct || response.turn_outcome?.status === 'failed') {
			break;
		}

		const next = await continueAfterAct(response);
		if (next?.state !== 'plan_ready' || !improveWorkflowHasRemainingUnits(next)) {
			break;
		}
		workflow = next;
	}

	return { evaluate: evaluation, acts };
}

export function improveWorkflowHasRemainingUnits(
	workflow: ImproveWorkflow | null | undefined,
): boolean {
	if (workflow?.state !== 'plan_ready') {
		return false;
	}
	const units = Array.isArray(workflow.units) ? workflow.units : [];
	const cursor = Math.max(0, workflow.cursor ?? 0);
	return cursor < units.length;
}
