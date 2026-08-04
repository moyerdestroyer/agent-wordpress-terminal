import { Button, Notice, Spinner, TextareaControl } from '@wordpress/components';
import { render, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	createSession,
	fetchActionPreview,
	getChatProgress,
	getSession,
	sendMessage,
	updateAction,
} from './api';
import { ActionDiffView } from './components/ActionDiffView';
import { AgentTurnStatus } from './components/AgentTurnStatus';
import type { ChatProgress, Message, ProposedAction, ToolCall } from './types';
import './reviewBridge.css';

interface MountOptions {
	postId: number;
	title: string;
	onActionState?: (pending: number) => void;
	onNotice?: (notice: { status: string; message: string }) => void;
	onApplied?: () => void;
}

declare global {
	interface Window {
		AWPTReviewAssistant?: { mount: (element: Element, options: MountOptions) => () => void };
	}
}

const REVIEW_SAFE_OPERATIONS = new Set([
	'content_update',
	'block_attrs_update',
	'block_insert',
	'block_remove',
	'pattern_insert',
]);
const GENERIC_REVIEW_PROMPT = __('Make this page more presentable.', 'agent-wordpress-terminal');

type ReviewRequestKind = 'review' | 'custom';
type ReviewOutcome = {
	state: 'verified' | 'unverified' | 'no-change' | 'failed';
	message: string;
	errorCode?: string;
};

function isReviewSafeAction(action: ProposedAction, postId: number): boolean {
	return (
		action.payload?.post_id === postId &&
		REVIEW_SAFE_OPERATIONS.has(action.payload?.operation ?? '')
	);
}

function mergeActions(current: ProposedAction[], incoming: ProposedAction[]): ProposedAction[] {
	const actions = new Map<number, ProposedAction>();
	for (const action of [...current, ...incoming]) {
		if (action.id) actions.set(action.id, action);
	}
	return Array.from(actions.values());
}

function visualVerification(
	toolCalls: ToolCall[] = [],
	actions: ProposedAction[] = [],
): 'verified' | 'unverified' {
	const actionIds = new Set(
		actions.map((action) => action.id).filter((id): id is number => Boolean(id)),
	);
	const inspection = [...toolCalls].reverse().find((call) => {
		if (call.tool !== 'awpt/inspect-rendered-element') return false;
		const input =
			call.input && typeof call.input === 'object' && !Array.isArray(call.input) ? call.input : {};
		const actionId =
			'action_id' in input && typeof input.action_id === 'number' ? input.action_id : 0;
		return actionId > 0 && actionIds.has(actionId);
	});
	const output =
		inspection?.output && typeof inspection.output === 'object' && !Array.isArray(inspection.output)
			? inspection.output
			: {};
	return inspection?.status === 'success' && 'rendered' in output && output.rendered === true
		? 'verified'
		: 'unverified';
}

function ReviewAssistant({
	postId,
	title,
	onActionState,
	onNotice,
	onApplied,
}: MountOptions): JSX.Element {
	const [sessionId, setSessionId] = useState<number | null>(null);
	const [messages, setMessages] = useState<Message[]>([]);
	const [actions, setActions] = useState<ProposedAction[]>([]);
	const [message, setMessage] = useState('');
	const [isLoading, setIsLoading] = useState(true);
	const [isSending, setIsSending] = useState(false);
	const [isApplying, setIsApplying] = useState(false);
	const [error, setError] = useState('');
	const [progress, setProgress] = useState<ChatProgress | null>(null);
	const [pageUrl, setPageUrl] = useState('');
	const [reviewOutcome, setReviewOutcome] = useState<ReviewOutcome | null>(null);
	const latestTurnId = useRef<string | null>(null);
	const isWorking = isSending || isApplying;

	useEffect(() => {
		let active = true;
		setIsLoading(true);
		setError('');
		setReviewOutcome(null);

		void createSession(`Review: ${title}`, { focusPostId: postId, reuseFocus: true })
			.then(async (session) => {
				const detail = await getSession(session.id);
				if (!active) return;
				setSessionId(session.id);
				setMessages(detail.messages as Message[]);
				setActions(detail.actions);
				setPageUrl(detail.focus?.url ?? '');
				if (detail.last_turn_outcome?.status === 'failed') {
					setReviewOutcome({
						state: 'failed',
						message: detail.last_turn_outcome.message,
						errorCode: detail.last_turn_outcome.error_code,
					});
					return;
				}
				const applied = detail.actions.filter(
					(action) => isReviewSafeAction(action, postId) && action.status === 'applied',
				);
				if (applied.length > 0) {
					const state = visualVerification(detail.tool_calls, applied);
					setReviewOutcome({
						state,
						message:
							state === 'verified'
								? __(
										'The latest page improvement was visually checked and applied.',
										'agent-wordpress-terminal',
									)
								: __(
										'The latest page improvement is applied, but no rendered verification is recorded.',
										'agent-wordpress-terminal',
									),
					});
				}
			})
			.catch(
				(reason: unknown) =>
					active &&
					setError(
						errorText(reason, __('Could not open the review session.', 'agent-wordpress-terminal')),
					),
			)
			.finally(() => active && setIsLoading(false));

		return () => {
			active = false;
		};
	}, [postId, title]);

	useEffect(() => {
		onActionState?.(
			actions.filter((action) => action.status === 'proposed' || action.status === 'approved')
				.length,
		);
	}, [actions, onActionState]);

	const runRequest = async (submitted: string, kind: ReviewRequestKind = 'custom') => {
		if (!sessionId || !submitted.trim() || isWorking) return;

		const request = submitted.trim();
		if (kind === 'custom') setMessage('');
		setIsSending(true);
		setError('');
		setReviewOutcome(null);
		const turnId =
			typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
				? crypto.randomUUID()
				: `review-${Date.now()}-${Math.random().toString(16).slice(2)}`;
		latestTurnId.current = turnId;
		setProgress({
			state: 'pending',
			phase: 'starting',
			label:
				kind === 'review'
					? __('Inspecting the imported page', 'agent-wordpress-terminal')
					: __('Starting request', 'agent-wordpress-terminal'),
			detail:
				kind === 'review'
					? __(
							'Reading its content, blocks, theme guidance, and rendered presentation.',
							'agent-wordpress-terminal',
						)
					: '',
			completed: 0,
			total: 0,
			sequence: 0,
			updated_at: '',
		});
		setMessages((current) => [...current, { role: 'user', content: request }]);
		let polling = false;
		let settled = false;
		let recovering = false;
		let progressTimer: number | undefined;
		const finish = () => {
			if (settled) return false;
			settled = true;
			if (progressTimer) window.clearInterval(progressTimer);
			setIsSending(false);
			return true;
		};
		const syncCompletedTurn = async () => {
			if (recovering || settled || latestTurnId.current !== turnId) return;
			recovering = true;
			try {
				const detail = await getSession(sessionId);
				if (!finish()) return;
				setMessages(detail.messages as Message[]);
				setActions(detail.actions);
				if (detail.last_turn_outcome?.status === 'failed') {
					setProgress(null);
					setReviewOutcome({
						state: 'failed',
						message: detail.last_turn_outcome.message,
						errorCode: detail.last_turn_outcome.error_code,
					});
					return;
				}
				const pending = detail.actions.filter(
					(action) =>
						isReviewSafeAction(action, postId) &&
						(action.status === 'proposed' || action.status === 'approved'),
				);
				const verification = visualVerification(detail.tool_calls, pending);
				await applyReviewActions(pending, verification);
				if (kind === 'review' && pending.length === 0) {
					setProgress(null);
					setReviewOutcome({
						state: 'no-change',
						message: __(
							'AWPT completed its review without proposing a page change.',
							'agent-wordpress-terminal',
						),
					});
				}
			} catch {
				finish();
				setProgress(null);
				setError(
					__(
						'The agent finished, but the response could not be loaded. Refresh this page to continue.',
						'agent-wordpress-terminal',
					),
				);
			}
		};
		const pollProgress = async () => {
			if (polling) return;
			polling = true;
			try {
				const next = await getChatProgress(sessionId, turnId);
				setProgress((current) => (!current || next.sequence >= current.sequence ? next : current));
				if ('complete' === next.state) {
					void syncCompletedTurn();
				} else if ('failed' === next.state && finish()) {
					setProgress(null);
					setError(
						next.detail || __('The agent request failed. Try again.', 'agent-wordpress-terminal'),
					);
				}
			} catch {
				// The chat response remains authoritative if a progress update is unavailable.
			} finally {
				polling = false;
			}
		};
		void pollProgress();
		progressTimer = window.setInterval(() => void pollProgress(), 700);

		try {
			const response = await sendMessage(sessionId, request, [], turnId);
			if (!finish()) return;
			setMessages((current) => [...current, { role: 'assistant', content: response.content }]);
			const incoming = response.actions ?? [];
			if (response.turn_outcome?.status === 'failed') {
				setProgress(null);
				setReviewOutcome({
					state: 'failed',
					message: response.turn_outcome.message,
					errorCode: response.turn_outcome.error_code,
				});
				return;
			}
			if (incoming.length) {
				setActions((current) => mergeActions(current, incoming));
				const verification = visualVerification(response.tool_calls, incoming);
				const pending = incoming.filter(
					(action) =>
						isReviewSafeAction(action, postId) &&
						(action.status === 'proposed' || action.status === 'approved'),
				);
				await applyReviewActions(pending, verification);
			} else {
				setProgress(null);
				if (kind === 'review') {
					setReviewOutcome({
						state: 'no-change',
						message:
							response.content ||
							__(
								'AWPT completed its review without proposing a page change.',
								'agent-wordpress-terminal',
							),
					});
				}
			}
		} catch (reason) {
			if (finish()) {
				setProgress(null);
				setError(
					errorText(reason, __('The agent request failed. Try again.', 'agent-wordpress-terminal')),
				);
			}
		} finally {
			finish();
		}
	};

	const submitCustom = () => void runRequest(message);
	const improvePage = () => void runRequest(GENERIC_REVIEW_PROMPT, 'review');

	const applyReviewAction = async (
		action: ProposedAction,
		verification: 'verified' | 'unverified' = 'unverified',
	) => {
		if (!action.id) return;
		setError('');
		try {
			const updated = await updateAction(action.id, 'apply');
			setActions((current) => current.map((item) => (item.id === updated.id ? updated : item)));
			onApplied?.();
			setReviewOutcome({
				state: verification,
				message:
					verification === 'verified'
						? __(
								'AWPT visually checked the staged result before applying it.',
								'agent-wordpress-terminal',
							)
						: __(
								'The change was applied without a rendered visual check. Review the page and use Undo if needed.',
								'agent-wordpress-terminal',
							),
			});
			onNotice?.({
				status: verification === 'verified' ? 'success' : 'warning',
				message:
					verification === 'verified'
						? 'Visually checked and applied to this imported page. Undo is available below.'
						: 'Applied without visual verification. Review the page; Undo is available below.',
			});
		} catch (reason) {
			setError(
				errorText(
					reason,
					__('The change could not be applied automatically.', 'agent-wordpress-terminal'),
				),
			);
		}
	};

	const applyReviewActions = async (
		pending: ProposedAction[],
		verification: 'verified' | 'unverified',
	) => {
		if (pending.length === 0) {
			setProgress(null);
			return;
		}

		setIsApplying(true);
		try {
			for (const [index, action] of pending.entries()) {
				setProgress({
					state: 'active',
					phase: 'applying',
					label: __('Applying page improvement', 'agent-wordpress-terminal'),
					detail: sprintf(
						/* translators: 1: current change number, 2: total change count. */
						__('Saving change %1$d of %2$d…', 'agent-wordpress-terminal'),
						index + 1,
						pending.length,
					),
					completed: index,
					total: pending.length,
					sequence: Date.now(),
					updated_at: new Date().toISOString(),
				});
				await applyReviewAction(action, verification);
			}
		} finally {
			setIsApplying(false);
			setProgress(null);
		}
	};

	const undoReviewAction = async (action: ProposedAction) => {
		if (!action.id) return;
		setError('');
		try {
			const updated = await updateAction(action.id, 'rollback');
			setActions((current) => current.map((item) => (item.id === updated.id ? updated : item)));
			setReviewOutcome(null);
			onApplied?.();
			onNotice?.({
				status: 'success',
				message: 'The last review change was undone.',
			});
		} catch (reason) {
			setError(
				errorText(reason, __('The change could not be undone.', 'agent-wordpress-terminal')),
			);
		}
	};

	const openPreviewInNewTab = async (action: ProposedAction) => {
		if (!action.id) return;
		setError('');
		try {
			const preview = await fetchActionPreview(action.id);
			window.open(preview.preview_url, '_blank', 'noopener,noreferrer');
		} catch (reason) {
			setError(
				errorText(
					reason,
					__('A staged preview is not available for this action.', 'agent-wordpress-terminal'),
				),
			);
		}
	};

	if (isLoading)
		return (
			<div className="awpt-review-bridge__loading">
				<Spinner /> {__('Opening page session…', 'agent-wordpress-terminal')}
			</div>
		);

	return (
		<div className="awpt-review-bridge">
			<section className="awpt-review-bridge__primary" aria-labelledby={`awpt-improve-${postId}`}>
				<div>
					<h2 id={`awpt-improve-${postId}`}>
						{__('Improve this page', 'agent-wordpress-terminal')}
					</h2>
					<p>
						{__(
							'AWPT will inspect the page, choose theme-appropriate improvements, check the staged result, and apply it with Undo available.',
							'agent-wordpress-terminal',
						)}
					</p>
				</div>
				<Button variant="primary" onClick={improvePage} isBusy={isWorking} disabled={isWorking}>
					{__('Improve this page', 'agent-wordpress-terminal')}
				</Button>
			</section>
			{error ? (
				<Notice status="error" isDismissible onRemove={() => setError('')}>
					{error}
				</Notice>
			) : null}
			{isWorking ? (
				<AgentTurnStatus progress={progress} className="awpt-review-bridge__progress" />
			) : null}
			{reviewOutcome ? (
				<Notice
					status={
						reviewOutcome.state === 'failed'
							? 'error'
							: reviewOutcome.state === 'unverified'
								? 'warning'
								: reviewOutcome.state === 'no-change'
									? 'info'
									: 'success'
					}
					isDismissible={false}
				>
					<strong>
						{reviewOutcome.state === 'failed'
							? __('Page improvement was not staged', 'agent-wordpress-terminal')
							: reviewOutcome.state === 'verified'
								? __('Visually checked and applied', 'agent-wordpress-terminal')
								: reviewOutcome.state === 'unverified'
									? __('Applied without visual verification', 'agent-wordpress-terminal')
									: __('Review complete', 'agent-wordpress-terminal')}
					</strong>
					<p>{reviewOutcome.message}</p>
					{reviewOutcome.state === 'failed' && reviewOutcome.errorCode ? (
						<code className="awpt-review-bridge__error-code">{reviewOutcome.errorCode}</code>
					) : null}
					{reviewOutcome.state === 'failed' ? (
						<div className="awpt-review-bridge__recovery-actions">
							<Button variant="secondary" onClick={improvePage} disabled={isWorking}>
								{__('Retry page improvement', 'agent-wordpress-terminal')}
							</Button>
						</div>
					) : null}
					{pageUrl ? (
						<Button variant="link" href={pageUrl} target="_blank" rel="noreferrer">
							{__('Open page', 'agent-wordpress-terminal')}
						</Button>
					) : null}
				</Notice>
			) : null}
			{actions
				.filter(
					(action) =>
						action.status === 'proposed' ||
						action.status === 'approved' ||
						(isReviewSafeAction(action, postId) && action.status === 'applied'),
				)
				.map((action) => (
					<div className="awpt-review-bridge__action" key={action.id ?? action.title}>
						<div>
							<strong>{action.title}</strong>
							<p>{action.description}</p>
							<ActionDiffView payload={action.payload} compact />
						</div>
						<div>
							{action.status === 'applied' && isReviewSafeAction(action, postId) ? (
								<Button variant="secondary" onClick={() => undoReviewAction(action)}>
									{__('Undo', 'agent-wordpress-terminal')}
								</Button>
							) : isReviewSafeAction(action, postId) ? (
								<>
									<Button variant="secondary" onClick={() => applyReviewAction(action)}>
										{__('Retry auto-apply', 'agent-wordpress-terminal')}
									</Button>
									<Button variant="tertiary" onClick={() => openPreviewInNewTab(action)}>
										{__('Open staged preview', 'agent-wordpress-terminal')}
									</Button>
								</>
							) : (
								<span className="awpt-review-bridge__requires-terminal">
									{__('Requires approval in Agent Terminal', 'agent-wordpress-terminal')}
								</span>
							)}
						</div>
					</div>
				))}
			<details className="awpt-review-bridge__disclosure">
				<summary>{__('Custom request', 'agent-wordpress-terminal')}</summary>
				<div className="awpt-review-bridge__composer">
					<TextareaControl
						__nextHasNoMarginBottom
						label={__('Tell AWPT what this page needs', 'agent-wordpress-terminal')}
						value={message}
						onChange={setMessage}
						disabled={isWorking}
					/>
					<Button
						variant="secondary"
						onClick={submitCustom}
						isBusy={isWorking}
						disabled={!message.trim() || isWorking}
					>
						{__('Ask AWPT', 'agent-wordpress-terminal')}
					</Button>
				</div>
			</details>
			{messages.length > 0 ? (
				<details className="awpt-review-bridge__disclosure">
					<summary>{__('Agent activity', 'agent-wordpress-terminal')}</summary>
					<div className="awpt-review-bridge__transcript" aria-live="polite">
						{messages.map((item) => (
							<div
								className={`awpt-review-bridge__message is-${item.role}`}
								key={`${item.created_at ?? ''}:${item.role}:${item.content}`}
							>
								<strong>
									{item.role === 'user'
										? __('You', 'agent-wordpress-terminal')
										: __('Agent', 'agent-wordpress-terminal')}
								</strong>
								<p>{item.content}</p>
							</div>
						))}
					</div>
				</details>
			) : null}
		</div>
	);
}

function errorText(reason: unknown, fallback: string): string {
	return reason &&
		typeof reason === 'object' &&
		'message' in reason &&
		typeof reason.message === 'string'
		? reason.message
		: fallback;
}

window.AWPTReviewAssistant = {
	mount(element, options) {
		const configured = window.awptSettings?.connection?.ready ?? false;
		render(
			configured ? (
				<ReviewAssistant {...options} />
			) : (
				<div className="awpt-review-bridge__unconfigured">
					<strong>{__('Agent assistance is not configured.', 'agent-wordpress-terminal')}</strong>
					<p>
						{__(
							'Choose and configure a provider in Settings → Agent Terminal to request staged page fixes.',
							'agent-wordpress-terminal',
						)}
					</p>
				</div>
			),
			element,
		);
		return () => render(null, element);
	},
};
window.dispatchEvent(new Event('awpt-review-assistant-ready'));
