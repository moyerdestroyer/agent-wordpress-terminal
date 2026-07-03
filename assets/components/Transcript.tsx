import { Button } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ActionPayload, Message, ProposedAction, ToolCall } from '../types';

interface TranscriptProps {
	messages: Message[];
	toolCalls: ToolCall[];
	actions: ProposedAction[];
	isThinking?: boolean;
	onActionOperation: (action: ProposedAction, operation: 'approve' | 'reject' | 'apply') => void;
	onActionPreview: (action: ProposedAction) => void;
}

type TranscriptItem = { kind: 'message'; message: Message } | { kind: 'tool'; call: ToolCall };

function ThinkingIndicator(): JSX.Element {
	return (
		<div
			className="awpt-message awpt-message--assistant awpt-message--thinking"
			role="status"
			aria-live="polite"
			aria-busy="true"
		>
			<strong>{__('Agent', 'agent-wordpress-terminal')}:</strong>{' '}
			<span className="awpt-thinking">
				<span className="awpt-thinking__label">{__('Thinking', 'agent-wordpress-terminal')}</span>
				<span className="awpt-thinking__dots" aria-hidden="true">
					<span>.</span>
					<span>.</span>
					<span>.</span>
				</span>
			</span>
		</div>
	);
}

function formatValue(value: unknown): string {
	if (typeof value === 'string') {
		return value;
	}

	if (value === null || value === undefined) {
		return '';
	}

	return JSON.stringify(value);
}

function toolCallKey(call: ToolCall): string {
	return `${call.id ?? call.tool}-${call.created_at ?? JSON.stringify(call.input)}`;
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

function buildTranscriptItems(messages: Message[], toolCalls: ToolCall[]): TranscriptItem[] {
	const items: TranscriptItem[] = [];
	const assignedTools = new Set<string>();
	const liveAssistantCount = messages.filter(
		(message) => message.role === 'assistant' && !message.created_at,
	).length;
	let liveAssistantCursor = 0;

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

		for (const call of turnTools) {
			assignedTools.add(toolCallKey(call));
			items.push({ kind: 'tool', call });
		}
	}

	for (const call of toolCalls) {
		const key = toolCallKey(call);

		if (!assignedTools.has(key)) {
			items.push({ kind: 'tool', call });
			assignedTools.add(key);
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

	if (output && typeof output.error === 'string' && output.error.trim() !== '') {
		return output.error;
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

function InlineToolNote({ call }: { call: ToolCall }): JSX.Element {
	const status = call.status ?? 'success';
	const failure = toolFailureMessage(call);

	return (
		<div className={`awpt-tool-inline awpt-tool-inline--${status}`} role="note">
			{sprintf(
				/* translators: %s: tool name */
				__('Called %s', 'agent-wordpress-terminal'),
				call.tool,
			)}
			{failure ? (
				<span className="awpt-tool-inline__failure">
					{' — '}
					{failure}
				</span>
			) : null}
		</div>
	);
}

function titleCase(value: string): string {
	return value.replace(/[_-]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function actionMetadata(payload?: ActionPayload): Array<{ label: string; value: string }> {
	if (!payload) {
		return [];
	}

	const postTitle = payload.original_post_title || payload.post_title || '';
	const postType = payload.post_type
		? titleCase(payload.post_type)
		: __('Post/Page', 'agent-wordpress-terminal');
	const postReference = [
		postType,
		payload.post_id ? `#${payload.post_id}` : '',
		postTitle ? `- ${postTitle}` : '',
	]
		.filter(Boolean)
		.join(' ');

	return [
		{
			label: __('Target', 'agent-wordpress-terminal'),
			value: postReference,
		},
		{
			label: __('Status', 'agent-wordpress-terminal'),
			value: payload.post_status ? titleCase(payload.post_status) : '',
		},
		{
			label: __('Blocks / area', 'agent-wordpress-terminal'),
			value: payload.affected ?? '',
		},
	].filter((item) => item.value !== '');
}

function ActionCard({
	action,
	onOperation,
	onPreview,
}: {
	action: ProposedAction;
	onOperation: (action: ProposedAction, operation: 'approve' | 'reject' | 'apply') => void;
	onPreview: (action: ProposedAction) => void;
}): JSX.Element {
	const [showDiff, setShowDiff] = useState(false);
	const canApply = action.status === 'proposed' || action.status === 'approved';
	const canReject = action.status === 'proposed' || action.status === 'approved';
	const metadata = actionMetadata(action.payload);

	return (
		<div className="awpt-action-card">
			<h4>
				{__('Proposed Action', 'agent-wordpress-terminal')}{' '}
				<span className={`awpt-action-card__status awpt-action-card__status--${action.status}`}>
					{action.status}
				</span>
			</h4>
			<p>
				<strong>{action.title}</strong>
				<br />
				{action.description}
			</p>
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
			<div className="awpt-action-card__buttons">
				<Button
					variant="secondary"
					onClick={() => onPreview(action)}
					disabled={!action.payload?.preview_url}
				>
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
				<Button
					variant="tertiary"
					onClick={() => onOperation(action, 'reject')}
					disabled={!canReject}
				>
					{__('Reject', 'agent-wordpress-terminal')}
				</Button>
			</div>
			{showDiff ? (
				<pre className="awpt-action-card__diff">
					{[
						`${__('Before', 'agent-wordpress-terminal')}:`,
						formatValue(action.payload?.original_post_content),
						'',
						`${__('After', 'agent-wordpress-terminal')}:`,
						formatValue(action.payload?.post_content),
					].join('\n')}
				</pre>
			) : null}
		</div>
	);
}

export function Transcript({
	messages,
	toolCalls,
	actions,
	isThinking = false,
	onActionOperation,
	onActionPreview,
}: TranscriptProps): JSX.Element {
	const scrollAnchorRef = useRef<HTMLDivElement>(null);
	const transcriptItems = useMemo(
		() => buildTranscriptItems(messages, toolCalls),
		[messages, toolCalls],
	);

	useEffect(() => {
		scrollAnchorRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
	}, [transcriptItems.length, isThinking, actions.length]);

	return (
		<div className="awpt-messages">
			{messages.length === 0 && !isThinking ? (
				<p className="awpt-empty">
					{__(
						'Ask the agent or try /tools, /mcp status, /knowledge search brand voice, /read 291, /preview 291',
						'agent-wordpress-terminal',
					)}
				</p>
			) : (
				transcriptItems.map((item) => {
					if (item.kind === 'tool') {
						return <InlineToolNote call={item.call} key={`tool-${toolCallKey(item.call)}`} />;
					}

					const message = item.message;

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

			{isThinking ? <ThinkingIndicator /> : null}

			{actions.map((action) => (
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
