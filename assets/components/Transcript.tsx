import { Button } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { actionMetadata, canPreviewAction } from '../actionDisplay';
import { formatElapsed } from '../lib/turnTiming';
import type { ChatProgress, JsonValue, Message, ProposedAction, ToolCall } from '../types';
import { ActionDiffView } from './ActionDiffView';
import { AgentTurnStatus } from './AgentTurnStatus';

export interface TurnSummary {
	durationMs: number;
	toolCount: number;
}

interface TranscriptProps {
	messages: Message[];
	toolCalls: ToolCall[];
	actions: ProposedAction[];
	isWorking?: boolean;
	progress?: ChatProgress | null;
	turnSummary?: TurnSummary | null;
	onActionOperation: (
		action: ProposedAction,
		operation: 'approve' | 'reject' | 'apply' | 'rollback',
	) => void;
	onActionPreview: (action: ProposedAction) => void;
}

type TranscriptItem =
	| { kind: 'message'; message: Message }
	| { kind: 'tool-group'; calls: ToolCall[]; key: string }
	| { kind: 'action-record'; action: ProposedAction };

function jsonObject(value: JsonValue | undefined): { [key: string]: JsonValue } | null {
	return null !== value && typeof value === 'object' && !Array.isArray(value) ? value : null;
}

function TurnSummaryLine({ summary }: { summary: TurnSummary }): JSX.Element {
	const tools =
		summary.toolCount > 0
			? sprintf(
					/* translators: %d: number of tool calls in the completed turn. */
					__(' · %d tools', 'agent-wordpress-terminal'),
					summary.toolCount,
				)
			: '';

	return (
		<div className="awpt-turn-summary" role="note">
			{sprintf(
				/* translators: %s: formatted elapsed duration (e.g. 3.4s). */
				__('Completed in %s', 'agent-wordpress-terminal'),
				formatElapsed(summary.durationMs),
			)}
			{tools}
		</div>
	);
}

function toolCallKey(call: ToolCall): string {
	return `${call.id ?? call.tool}-${call.created_at ?? JSON.stringify(call.input)}`;
}

function normalizedActionId(value: unknown): number | null {
	if (typeof value === 'number' && Number.isFinite(value)) {
		return value;
	}

	if (typeof value === 'string' && value.trim() !== '') {
		const parsed = Number.parseInt(value, 10);

		return Number.isNaN(parsed) ? null : parsed;
	}

	return null;
}

function toolsForAssistant(
	message: Message,
	toolCalls: ToolCall[],
	assigned: Set<string>,
): ToolCall[] {
	if (!message.created_at) {
		return [];
	}

	return toolCalls.filter((call) => {
		const key = toolCallKey(call);

		return !assigned.has(key) && call.created_at === message.created_at;
	});
}

function actionIdFromToolCall(call: ToolCall): number | null {
	return normalizedActionId(jsonObject(call.output)?.id);
}

function isResolvedAction(action: ProposedAction): boolean {
	return (
		action.status === 'applied' ||
		action.status === 'rejected' ||
		action.status === 'superseded' ||
		action.status === 'rolled_back'
	);
}

function buildTranscriptItems(
	messages: Message[],
	toolCalls: ToolCall[],
	actions: ProposedAction[],
): TranscriptItem[] {
	const items: TranscriptItem[] = [];
	const assignedTools = new Set<string>();
	const assignedActions = new Set<number>();
	const resolvedActionsById = new Map(
		actions
			.filter(isResolvedAction)
			.map((action) => [normalizedActionId(action.id), action] as const)
			.filter((entry): entry is readonly [number, ProposedAction] => entry[0] !== null),
	);
	const liveAssistantCount = messages.filter(
		(message) => message.role === 'assistant' && !message.created_at,
	).length;
	let liveAssistantCursor = 0;

	const appendTools = (calls: ToolCall[], key: string): void => {
		if (calls.length === 0) {
			return;
		}

		items.push({ kind: 'tool-group', calls, key });

		for (const call of calls) {
			assignedTools.add(toolCallKey(call));
			const actionId = actionIdFromToolCall(call);
			const action = actionId ? resolvedActionsById.get(actionId) : null;

			if (action?.id) {
				assignedActions.add(action.id);
				items.push({ kind: 'action-record', action });
			}
		}
	};

	for (const message of messages) {
		items.push({ kind: 'message', message });

		if (message.role !== 'assistant') {
			continue;
		}

		let turnTools = toolsForAssistant(message, toolCalls, assignedTools);

		if (turnTools.length === 0 && !message.created_at && liveAssistantCount > 0) {
			const remaining = toolCalls.filter((call) => !assignedTools.has(toolCallKey(call)));
			const assistantsLeft = liveAssistantCount - liveAssistantCursor;
			const count = Math.ceil(remaining.length / Math.max(assistantsLeft, 1));
			turnTools = remaining.slice(0, count);
			liveAssistantCursor += 1;
		}

		appendTools(turnTools, `message-${message.id ?? message.created_at ?? liveAssistantCursor}`);
	}

	appendTools(
		toolCalls.filter((call) => !assignedTools.has(toolCallKey(call))),
		'unassigned',
	);

	for (const action of actions) {
		if (action.id && isResolvedAction(action) && !assignedActions.has(action.id)) {
			items.push({ kind: 'action-record', action });
			assignedActions.add(action.id);
		}
	}

	return items;
}

function toolFailureMessage(call: ToolCall): string | null {
	const status = call.status ?? 'success';

	if ('success' === status) {
		return null;
	}

	const output = call.output;

	const object = jsonObject(output);

	if (object && typeof object.error === 'string' && object.error.trim() !== '') {
		return object.error;
	}

	return statusLabel(status);
}

function statusLabel(status: string): string {
	switch (status) {
		case 'failed':
			return __('Failed', 'agent-wordpress-terminal');
		case 'rejected':
			return __('Rejected', 'agent-wordpress-terminal');
		case 'pending':
			return __('Pending', 'agent-wordpress-terminal');
		default:
			return __('Success', 'agent-wordpress-terminal');
	}
}

function toolNoteLabel(call: ToolCall): string {
	if (call.tool === 'awpt/knowledge-auto-retrieval') {
		const output = jsonObject(call.output);
		const count = typeof output?.count === 'number' ? output.count : null;

		return count
			? sprintf(
					/* translators: %d: retrieved Knowledge result count */
					__('Added %d Knowledge context results', 'agent-wordpress-terminal'),
					count,
				)
			: __('Checked Knowledge context', 'agent-wordpress-terminal');
	}

	const summary = call.output_summary?.trim();

	if (!call.output && summary) {
		return summary;
	}

	return sprintf(
		/* translators: %s: tool name */
		__('Called %s', 'agent-wordpress-terminal'),
		call.tool,
	);
}

function InlineToolNote({ call }: { call: ToolCall }): JSX.Element {
	const status = call.status ?? 'success';
	const failure = toolFailureMessage(call);

	return (
		<div className={`awpt-tool-inline awpt-tool-inline--${status}`} role="note">
			{toolNoteLabel(call)}
			{failure ? (
				<span className="awpt-tool-inline__failure">
					{' — '}
					{failure}
				</span>
			) : null}
		</div>
	);
}

function ToolEvidenceGroup({ calls }: { calls: ToolCall[] }): JSX.Element {
	const failed = calls.filter((call) => (call.status ?? 'success') !== 'success').length;
	const label = sprintf(
		/* translators: %d: number of tool calls represented by this evidence group. */
		__('Evidence · %d tool calls', 'agent-wordpress-terminal'),
		calls.length,
	);

	return (
		<details className="awpt-evidence-group" open={failed > 0}>
			<summary>
				{label}
				{failed > 0
					? sprintf(
							/* translators: %d: number of failed tool calls. */
							__(' · %d failed', 'agent-wordpress-terminal'),
							failed,
						)
					: null}
			</summary>
			<div className="awpt-evidence-group__content">
				{calls.map((call) => (
					<InlineToolNote call={call} key={toolCallKey(call)} />
				))}
			</div>
		</details>
	);
}

function ActionCard({
	action,
	onOperation,
	onPreview,
}: {
	action: ProposedAction;
	onOperation: (
		action: ProposedAction,
		operation: 'approve' | 'reject' | 'apply' | 'rollback',
	) => void;
	onPreview: (action: ProposedAction) => void;
}): JSX.Element {
	const [showDiff, setShowDiff] = useState(false);
	const canApply = action.status === 'proposed' || action.status === 'approved';
	const canReject = action.status === 'proposed' || action.status === 'approved';
	const canPreview = canPreviewAction(action.payload);
	const metadata = actionMetadata(action.payload);
	const manifest = action.payload?.proposal_manifest;
	const decisionTrace = action.payload?.decision_trace ?? [];
	const repairsApplied = action.payload?.repairs_applied ?? [];
	const validationFindings = action.payload?.validation_findings ?? [];
	const safeFixes = action.payload?.safe_fixes ?? [];
	const feedback = action.payload?.agent_feedback;
	const canRollback =
		action.status === 'applied' &&
		Boolean(action.payload?.domain_pack_id) &&
		!action.payload?.domain_irreversible;

	return (
		<div className="awpt-action-card">
			<h4>
				{action.revision_kind === 'revised'
					? __('Current revision', 'agent-wordpress-terminal')
					: __('Proposed Action', 'agent-wordpress-terminal')}{' '}
				<span className={`awpt-action-card__status awpt-action-card__status--${action.status}`}>
					{action.status}
				</span>
			</h4>
			{action.id ? (
				<p className="awpt-action-card__context">
					{`${__('Action', 'agent-wordpress-terminal')} #${action.id}`}
					{action.updated_at ? ` · ${action.updated_at}` : ''}
				</p>
			) : null}
			<p>
				<strong>{action.title}</strong>
				<br />
				{action.description}
			</p>
			{feedback?.summary ? <p className="awpt-action-card__context">{feedback.summary}</p> : null}
			{metadata.length > 0 ? (
				<dl className="awpt-action-card__meta">
					{metadata.map((item) => (
						<div key={item.label}>
							<dt>{item.label}</dt>
							<dd>{item.value}</dd>
						</div>
					))}
				</dl>
			) : null}
			{manifest?.approach ? (
				<div className="awpt-action-card__rationale">
					<strong>{__('Agent approach', 'agent-wordpress-terminal')}</strong>
					<p>{manifest.approach}</p>
					{(manifest.requirements?.length ?? 0) > 0 ? (
						<ul>
							{manifest.requirements?.map((requirement) => (
								<li key={JSON.stringify(requirement)}>
									{Object.values(requirement).filter(Boolean).join(' — ')}
								</li>
							))}
						</ul>
					) : null}
				</div>
			) : null}
			{decisionTrace.length > 0 || (manifest?.assumptions?.length ?? 0) > 0 ? (
				<details className="awpt-action-card__rationale-details">
					<summary>{__('Details', 'agent-wordpress-terminal')}</summary>
					{decisionTrace.length > 0 ? (
						<ol>
							{decisionTrace.map((item) => (
								<li key={item}>{item}</li>
							))}
						</ol>
					) : null}
					{(manifest?.assumptions?.length ?? 0) > 0 ? (
						<p>{`${__('Assumptions', 'agent-wordpress-terminal')}: ${manifest?.assumptions?.join('; ')}`}</p>
					) : null}
				</details>
			) : null}
			{repairsApplied.length > 0 ? (
				<details className="awpt-action-card__rationale-details">
					<summary>{__('Markup repairs applied', 'agent-wordpress-terminal')}</summary>
					<ul>
						{repairsApplied.map((repair) => (
							<li key={`${repair.kind}-${repair.block_path}-${repair.description}`}>
								{`${repair.block_name} ${repair.block_path}: ${repair.description}`}
							</li>
						))}
					</ul>
				</details>
			) : null}
			{safeFixes.length > 0 ? (
				<details className="awpt-action-card__rationale-details">
					<summary>{__('Safe fixes applied', 'agent-wordpress-terminal')}</summary>
					<ul>
						{safeFixes.map((fix) => (
							<li key={`${fix.id}-${fix.after_hash ?? ''}`}>{fix.description}</li>
						))}
					</ul>
				</details>
			) : null}
			{validationFindings.length > 0 ? (
				<div className="awpt-action-card__rationale">
					<strong>{__('Domain validation', 'agent-wordpress-terminal')}</strong>
					<ul>
						{validationFindings.map((finding) => (
							<li key={`${finding.pack_id}-${finding.code}-${finding.block_path}`}>
								{`${finding.severity.toUpperCase()}: ${finding.message}`}
								{finding.suggestion ? ` — ${finding.suggestion}` : ''}
							</li>
						))}
					</ul>
				</div>
			) : null}
			<div className="awpt-action-card__buttons">
				<Button variant="secondary" onClick={() => onPreview(action)} disabled={!canPreview}>
					{__('Preview', 'agent-wordpress-terminal')}
				</Button>
				<Button variant="secondary" onClick={() => setShowDiff((current) => !current)}>
					{showDiff
						? __('Hide diff', 'agent-wordpress-terminal')
						: __('Diff', 'agent-wordpress-terminal')}
				</Button>
				<Button variant="primary" onClick={() => onOperation(action, 'apply')} disabled={!canApply}>
					{__('Apply', 'agent-wordpress-terminal')}
				</Button>
				{canRollback ? (
					<Button variant="secondary" onClick={() => onOperation(action, 'rollback')}>
						{__('Roll back', 'agent-wordpress-terminal')}
					</Button>
				) : null}
				<Button
					variant="tertiary"
					onClick={() => onOperation(action, 'reject')}
					disabled={!canReject}
				>
					{__('Reject', 'agent-wordpress-terminal')}
				</Button>
			</div>
			{showDiff ? <ActionDiffView payload={action.payload} compact /> : null}
		</div>
	);
}

function ActionRecord({
	action,
	onPreview,
	onOperation,
}: {
	action: ProposedAction;
	onPreview: (action: ProposedAction) => void;
	onOperation: (
		action: ProposedAction,
		operation: 'approve' | 'reject' | 'apply' | 'rollback',
	) => void;
}): JSX.Element {
	const canRollback =
		action.status === 'applied' &&
		Boolean(action.payload?.domain_pack_id) &&
		!action.payload?.domain_irreversible;

	return (
		<div className="awpt-action-record">
			<span className={`awpt-action-record__status awpt-action-card__status--${action.status}`}>
				{action.status}
			</span>
			<span className="awpt-action-record__title">{action.title}</span>
			{action.payload && canPreviewAction(action.payload) ? (
				<Button variant="link" onClick={() => onPreview(action)}>
					{__('Preview', 'agent-wordpress-terminal')}
				</Button>
			) : null}
			{canRollback ? (
				<Button variant="link" onClick={() => onOperation(action, 'rollback')}>
					{__('Roll back', 'agent-wordpress-terminal')}
				</Button>
			) : null}
		</div>
	);
}

export function Transcript({
	messages,
	toolCalls,
	actions,
	isWorking = false,
	progress = null,
	turnSummary = null,
	onActionOperation,
	onActionPreview,
}: TranscriptProps): JSX.Element {
	const scrollAnchorRef = useRef<HTMLDivElement>(null);
	const transcriptItems = useMemo(
		() => buildTranscriptItems(messages, toolCalls, actions),
		[messages, toolCalls, actions],
	);
	const pendingActions = actions.filter((action) => !isResolvedAction(action));

	useEffect(() => {
		scrollAnchorRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
	}, [transcriptItems.length, isWorking, pendingActions.length, turnSummary?.durationMs]);

	return (
		<div className="awpt-messages">
			{messages.length === 0 && !isWorking ? (
				<p className="awpt-empty">
					{__(
						'Ask the agent what you want changed, or try “Focus the About page”, “Preview the homepage”, or “Find brand voice guidance”.',
						'agent-wordpress-terminal',
					)}
				</p>
			) : (
				transcriptItems.map((item) => {
					if (item.kind === 'tool-group') {
						return <ToolEvidenceGroup calls={item.calls} key={`tools-${item.key}`} />;
					}

					if (item.kind === 'action-record') {
						return (
							<ActionRecord
								action={item.action}
								key={`action-record-${item.action.id ?? item.action.title}`}
								onPreview={onActionPreview}
								onOperation={onActionOperation}
							/>
						);
					}

					const message = item.message;

					if (message.role === 'incident') {
						return (
							<div
								key={message.id ?? `incident-${message.created_at ?? message.content}`}
								className="awpt-message awpt-message--incident"
								role="note"
							>
								{message.content}
							</div>
						);
					}

					return (
						<div
							key={message.id ?? `${message.role}-${message.created_at ?? message.content}`}
							className={`awpt-message awpt-message--${message.role}`}
						>
							<strong>
								{message.role === 'user'
									? __('User', 'agent-wordpress-terminal')
									: __('Agent', 'agent-wordpress-terminal')}
								:
							</strong>{' '}
							{message.content}
						</div>
					);
				})
			)}

			{isWorking ? (
				<AgentTurnStatus
					progress={progress}
					className="awpt-message awpt-message--assistant awpt-message--working"
				/>
			) : null}
			{!isWorking && turnSummary ? <TurnSummaryLine summary={turnSummary} /> : null}

			{pendingActions.map((action) => (
				<ActionCard
					key={action.id ?? `${action.title}-${action.status}-${action.description}`}
					action={action}
					onOperation={onActionOperation}
					onPreview={onActionPreview}
				/>
			))}

			<div ref={scrollAnchorRef} className="awpt-messages__anchor" aria-hidden="true" />
		</div>
	);
}
