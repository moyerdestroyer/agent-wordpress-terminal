import type { JsonValue, ProposedAction, ToolCall } from './types';

const DEFAULT_PROPOSAL_TOOLS = [
	'awpt/propose-content-update',
	'awpt/propose-block-attrs-update',
	'awpt/propose-new-post',
	'awpt/propose-site-settings-update',
	'awpt/propose-theme-switch',
] as const;

function proposalTools(): Set<string> {
	const configured = window.awptSettings?.proposalTools;

	if (Array.isArray(configured) && configured.length > 0) {
		return new Set(configured.filter((tool): tool is string => typeof tool === 'string'));
	}

	return new Set(DEFAULT_PROPOSAL_TOOLS);
}

const ACTION_STATUS_RANK: Record<ProposedAction['status'], number> = {
	proposed: 1,
	approved: 2,
	rejected: 3,
	superseded: 3,
	applied: 4,
	rolled_back: 5,
};

function actionStatusRank(status: ProposedAction['status']): number {
	return ACTION_STATUS_RANK[status] ?? 0;
}

function mergeActionRecord(existing: ProposedAction, incoming: ProposedAction): ProposedAction {
	const incomingUpdated = Date.parse(incoming.updated_at ?? incoming.created_at ?? '');
	const existingUpdated = Date.parse(existing.updated_at ?? existing.created_at ?? '');

	if (Number.isFinite(incomingUpdated) && Number.isFinite(existingUpdated)) {
		return incomingUpdated >= existingUpdated ? { ...existing, ...incoming } : existing;
	}

	const incomingRank = actionStatusRank(incoming.status);
	const existingRank = actionStatusRank(existing.status);

	return incomingRank >= existingRank ? { ...existing, ...incoming } : existing;
}

function isProposedActionStatus(value: unknown): value is ProposedAction['status'] {
	return (
		value === 'proposed' ||
		value === 'approved' ||
		value === 'rejected' ||
		value === 'applied' ||
		value === 'superseded' ||
		value === 'rolled_back'
	);
}

function isJsonObject(value: JsonValue | undefined): value is { [key: string]: JsonValue } {
	return null !== value && typeof value === 'object' && !Array.isArray(value);
}

export function proposalActionFromToolCall(call: ToolCall): ProposedAction | null {
	if ((call.status ?? 'success') !== 'success' || !proposalTools().has(call.tool)) {
		return null;
	}

	const output = call.output;

	if (!isJsonObject(output)) {
		return null;
	}

	const id =
		typeof output.id === 'number' ? output.id : Number.parseInt(String(output.id ?? ''), 10);

	if (!Number.isFinite(id)) {
		return null;
	}

	return {
		id,
		session_id: typeof output.session_id === 'number' ? output.session_id : undefined,
		title: typeof output.title === 'string' ? output.title : '',
		description: typeof output.description === 'string' ? output.description : '',
		payload: isJsonObject(output.payload)
			? (output.payload as ProposedAction['payload'])
			: undefined,
		status: isProposedActionStatus(output.status) ? output.status : 'proposed',
		created_at: typeof output.created_at === 'string' ? output.created_at : undefined,
		updated_at: typeof output.updated_at === 'string' ? output.updated_at : undefined,
		revision_kind: typeof output.revision_kind === 'string' ? output.revision_kind : undefined,
		revised_action_id:
			typeof output.revised_action_id === 'number' ? output.revised_action_id : undefined,
		removed_action_ids: Array.isArray(output.removed_action_ids)
			? output.removed_action_ids.filter((id): id is number => typeof id === 'number')
			: undefined,
	};
}

export function proposalActionsFromToolCalls(toolCalls: ToolCall[]): ProposedAction[] {
	const actions: ProposedAction[] = [];
	const removed = new Set<number>();

	for (const call of toolCalls) {
		const action = proposalActionFromToolCall(call);

		if (!action) {
			continue;
		}

		for (const id of action.removed_action_ids ?? []) {
			if (typeof id === 'number' && id > 0) {
				removed.add(id);
			}
		}

		actions.push(action);
	}

	if (removed.size > 0) {
		return actions.filter((action) => !action.id || !removed.has(action.id));
	}

	return actions;
}

export function mergeProposalActions(
	current: ProposedAction[],
	incoming: ProposedAction[],
): ProposedAction[] {
	const removed = new Set<number>();

	for (const action of incoming) {
		for (const id of action.removed_action_ids ?? []) {
			if (typeof id === 'number' && id > 0) {
				removed.add(id);
			}
		}
	}

	const merged = new Map<number, ProposedAction>();

	for (const action of current) {
		if (action.id && !removed.has(action.id)) {
			merged.set(action.id, action);
		}
	}

	for (const action of incoming) {
		if (!action.id || removed.has(action.id)) {
			continue;
		}

		const existing = merged.get(action.id);

		if (existing) {
			merged.set(action.id, mergeActionRecord(existing, action));
			continue;
		}

		merged.set(action.id, action);
	}

	return [...merged.values()];
}
