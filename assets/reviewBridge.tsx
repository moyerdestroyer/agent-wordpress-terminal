import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { render, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { createSession, getChatProgress, getSession, sendMessage, updateAction } from './api';
import { AgentTurnStatus } from './components/AgentTurnStatus';
import { improvePageActPrompt, improvePageReviewEvaluatePrompt } from './improvePagePrompt';
import { runImproveWorkflow } from './improveWorkflow';
import { buildReviewChangeSummary } from './reviewChangeSummary';
import type { ActionPayload, ChatProgress, ProposedAction, ToolCall } from './types';
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
		AWPTReviewAssistant?: {
			version: number;
			mount: (element: Element, options: MountOptions) => () => void;
		};
	}
}

/** Must stay aligned with AWPT\Admin\Page::REVIEW_BRIDGE_VERSION. */
const REVIEW_BRIDGE_VERSION = 1;

const REVIEW_SAFE_OPERATIONS = new Set([
	'content_update',
	'block_attrs_update',
	'block_insert',
	'block_remove',
	'pattern_insert',
	'pattern_replace',
]);
type ReviewOutcome = {
	state: 'verified' | 'unverified' | 'no-change' | 'failed';
	message: string;
	errorCode?: string;
};

function ReviewActionSummary({
	payload,
	applied,
}: {
	payload?: ActionPayload;
	applied?: boolean;
}): JSX.Element {
	const summary = useMemo(() => buildReviewChangeSummary(payload), [payload]);

	return (
		<details className="awpt-review-bridge__summary">
			<summary>{__('Details', 'agent-wordpress-terminal')}</summary>
			<div className="awpt-review-bridge__summary-content">
				<p className="awpt-review-bridge__summary-label">{summary.eyebrow}</p>
				{summary.lines.length > 0 ? (
					<ul className="awpt-review-bridge__summary-list">
						{summary.lines.map((line) => (
							<li key={line}>{line}</li>
						))}
					</ul>
				) : null}
				{!applied && summary.hint ? (
					<p className="awpt-review-bridge__summary-hint">{summary.hint}</p>
				) : null}
			</div>
		</details>
	);
}

function isReviewSafeAction(action: ProposedAction, postId: number): boolean {
	return (
		action.payload?.post_id === postId &&
		REVIEW_SAFE_OPERATIONS.has(action.payload?.operation ?? '')
	);
}

function byNewestId(a: ProposedAction, b: ProposedAction): number {
	return (b.id ?? 0) - (a.id ?? 0);
}

/** Only the latest review-safe change for this page — no action history. */
function pickLatestReviewAction(actions: ProposedAction[], postId: number): ProposedAction | null {
	const safe = actions.filter((action) => isReviewSafeAction(action, postId));
	const pending = safe
		.filter((action) => action.status === 'proposed' || action.status === 'approved')
		.sort(byNewestId);
	if (pending[0]) {
		return pending[0];
	}
	const applied = safe.filter((action) => action.status === 'applied').sort(byNewestId);
	return applied[0] ?? null;
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

function ReviewAssistant({ postId, title, onActionState, onApplied }: MountOptions): JSX.Element {
	const [sessionId, setSessionId] = useState<number | null>(null);
	const [actions, setActions] = useState<ProposedAction[]>([]);
	const [isLoading, setIsLoading] = useState(true);
	const [isSending, setIsSending] = useState(false);
	const [isApplying, setIsApplying] = useState(false);
	const [isResetting, setIsResetting] = useState(false);
	const [error, setError] = useState('');
	const [progress, setProgress] = useState<ChatProgress | null>(null);
	const [reviewOutcome, setReviewOutcome] = useState<ReviewOutcome | null>(null);
	const [contextTurnCount, setContextTurnCount] = useState(0);
	const [reviewerNotes, setReviewerNotes] = useState('');
	const latestTurnId = useRef<string | null>(null);
	const isWorking = isSending || isApplying || isResetting;
	const latestAction = useMemo(() => pickLatestReviewAction(actions, postId), [actions, postId]);

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
				setActions(detail.actions);
				setContextTurnCount(
					detail.messages.filter((message) => message.role === 'assistant').length,
				);
				const latest = pickLatestReviewAction(detail.actions, postId);
				if (latest && (latest.status === 'proposed' || latest.status === 'approved')) {
					// Keep recovered proposals staged until the admin explicitly applies them.
					return;
				}
				if (latest?.status === 'applied') {
					const state = visualVerification(detail.tool_calls, [latest]);
					setReviewOutcome({
						state,
						message:
							state === 'verified'
								? __(
										'The latest page improvement was visually checked and applied.',
										'agent-wordpress-terminal',
									)
								: __(
										'The latest page improvement is applied. Use Undo if you want the previous version.',
										'agent-wordpress-terminal',
									),
					});
					return;
				}
				if (detail.last_turn_outcome?.status === 'failed') {
					setReviewOutcome({
						state: 'failed',
						message: detail.last_turn_outcome.message,
						errorCode: detail.last_turn_outcome.error_code,
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
		// eslint-disable-next-line react-hooks/exhaustive-deps -- mount per focused post
	}, [postId, title]);

	useEffect(() => {
		onActionState?.(
			actions.filter(
				(action) =>
					isReviewSafeAction(action, postId) &&
					(action.status === 'proposed' || action.status === 'approved'),
			).length,
		);
	}, [actions, onActionState, postId]);

	const applyReviewAction = async (
		action: ProposedAction,
		verification: 'verified' | 'unverified' = 'unverified',
	): Promise<boolean> => {
		if (!action.id) return false;
		setError('');
		setIsApplying(true);
		setProgress({
			state: 'active',
			phase: 'applying',
			label: __('Applying page improvement', 'agent-wordpress-terminal'),
			detail: __('Saving the approved improvement to this page…', 'agent-wordpress-terminal'),
			completed: 0,
			total: 1,
			sequence: Date.now(),
			updated_at: new Date().toISOString(),
		});
		try {
			const updated = await updateAction(action.id, 'apply');
			setActions((current) => current.map((item) => (item.id === updated.id ? updated : item)));
			onApplied?.();
			setReviewOutcome({
				state: verification,
				message:
					verification === 'verified'
						? __(
								'AWPT visually checked the result and applied it to this page.',
								'agent-wordpress-terminal',
							)
						: __(
								'The change was applied. Review the page and use Undo if needed.',
								'agent-wordpress-terminal',
							),
			});
			return true;
		} catch (reason) {
			setError(
				errorText(
					reason,
					__(
						'The staged change could not be applied. Review it and try again.',
						'agent-wordpress-terminal',
					),
				),
			);
			return false;
		} finally {
			setIsApplying(false);
			setProgress(null);
		}
	};

	const startFreshContext = async () => {
		if (isWorking) return;
		setIsResetting(true);
		setError('');
		try {
			const session = await createSession(`Review: ${title}`, {
				focusPostId: postId,
				reuseFocus: false,
			});
			const detail = await getSession(session.id);
			setSessionId(session.id);
			setActions(detail.actions);
			setContextTurnCount(0);
			setReviewOutcome(null);
		} catch (reason) {
			setError(
				errorText(
					reason,
					__('Could not start a fresh page session. Try again.', 'agent-wordpress-terminal'),
				),
			);
		} finally {
			setIsResetting(false);
		}
	};

	const newTurnId = () =>
		typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
			? crypto.randomUUID()
			: `review-${Date.now()}-${Math.random().toString(16).slice(2)}`;

	const runImprove = async () => {
		if (!sessionId || isWorking) return;

		setIsSending(true);
		setError('');
		setReviewOutcome(null);

		let activeTurnId = newTurnId();
		latestTurnId.current = activeTurnId;
		setProgress({
			state: 'pending',
			phase: 'starting',
			label: __('Evaluating the page', 'agent-wordpress-terminal'),
			detail: __(
				'Reading structure and drafting a short plan (no changes yet).',
				'agent-wordpress-terminal',
			),
			completed: 0,
			total: 0,
			sequence: 0,
			updated_at: '',
		});

		let polling = false;
		let settled = false;
		let progressTimer: number | undefined;
		const stopPolling = () => {
			if (progressTimer) {
				window.clearInterval(progressTimer);
				progressTimer = undefined;
			}
		};
		const finish = () => {
			if (settled) return false;
			settled = true;
			stopPolling();
			setIsSending(false);
			return true;
		};
		const startPolling = (turnId: string) => {
			stopPolling();
			activeTurnId = turnId;
			latestTurnId.current = turnId;
			const pollProgress = async () => {
				if (polling || settled || latestTurnId.current !== turnId) return;
				polling = true;
				try {
					const next = await getChatProgress(sessionId, turnId);
					setProgress((current) =>
						!current || next.sequence >= current.sequence ? next : current,
					);
					if ('failed' === next.state && finish()) {
						setProgress(null);
						setError(
							next.detail || __('The agent request failed. Try again.', 'agent-wordpress-terminal'),
						);
					}
				} catch {
					// Chat response remains authoritative if progress is unavailable.
				} finally {
					polling = false;
				}
			};
			void pollProgress();
			progressTimer = window.setInterval(() => void pollProgress(), 700);
		};

		startPolling(activeTurnId);

		try {
			let evalResponse: import('./types').ChatResponse | null = null;
			const workflowResult = await runImproveWorkflow({
				evaluate: async () => {
					evalResponse = await sendMessage(
						sessionId,
						improvePageReviewEvaluatePrompt(postId, title, reviewerNotes),
						[],
						activeTurnId,
						{
							type: 'improve',
							phase: 'evaluate',
						},
					);
					return evalResponse;
				},
				act: async (workflow) => {
					const actTurnId = newTurnId();
					setProgress({
						state: 'pending',
						phase: 'starting',
						label: __('Staging the plan', 'agent-wordpress-terminal'),
						detail: __(
							'Staging pattern-native or surgical improvements from the evaluation plan.',
							'agent-wordpress-terminal',
						),
						completed: 0,
						total: 0,
						sequence: Date.now(),
						updated_at: new Date().toISOString(),
					});
					startPolling(actTurnId);
					return sendMessage(sessionId, improvePageActPrompt(), [], actTurnId, {
						id: workflow.id,
						type: 'improve',
						phase: 'act',
					});
				},
			});
			if (settled) return;
			if (!workflowResult?.act) {
				if (evalResponse) setContextTurnCount((count) => count + 1);
				if (finish()) {
					setProgress(null);
					setReviewOutcome({
						state: 'failed',
						message:
							evalResponse?.turn_outcome?.message ||
							__(
								'The page evaluation did not produce an executable plan.',
								'agent-wordpress-terminal',
							),
						errorCode: evalResponse?.turn_outcome?.error_code || 'awpt_improve_plan_missing',
					});
				}
				return;
			}

			const plan = workflowResult.evaluate.content.trim();
			const response = workflowResult.act;
			setContextTurnCount((count) => count + 2);
			if (!finish()) return;

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
				const actionable = incoming.filter(
					(action) => action.status === 'proposed' || action.status === 'approved',
				);
				const pending = incoming
					.filter(
						(action) =>
							isReviewSafeAction(action, postId) &&
							(action.status === 'proposed' || action.status === 'approved'),
					)
					.sort(byNewestId);
				if (actionable.length !== 1 || pending.length !== 1) {
					setProgress(null);
					setReviewOutcome({
						state: 'failed',
						message: __(
							'AWPT proposed a change outside this page’s safe review scope, so it was not applied.',
							'agent-wordpress-terminal',
						),
						errorCode: 'awpt_review_action_not_safe',
					});
					return;
				}
				const applied = await applyReviewAction(
					pending[0],
					visualVerification(response.tool_calls, incoming),
				);
				if (applied) setReviewerNotes('');
			} else {
				setProgress(null);
				setReviewOutcome({
					state: 'no-change',
					message:
						response.content ||
						plan ||
						__(
							'AWPT completed its review without proposing a page change.',
							'agent-wordpress-terminal',
						),
				});
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

	const improvePage = () => void runImprove();
	const contextLabel =
		contextTurnCount > 0
			? sprintf(
					/* translators: %d: number of prior assistant turns in this page session. */
					_n('%d prior turn', '%d prior turns', contextTurnCount, 'agent-wordpress-terminal'),
					contextTurnCount,
				)
			: __('Fresh context', 'agent-wordpress-terminal');

	const undoLatest = async () => {
		if (!latestAction?.id || latestAction.status !== 'applied') return;
		setError('');
		try {
			const updated = await updateAction(latestAction.id, 'rollback');
			setActions((current) => current.map((item) => (item.id === updated.id ? updated : item)));
			setReviewOutcome(null);
			onApplied?.();
		} catch (reason) {
			setError(
				errorText(reason, __('The change could not be undone.', 'agent-wordpress-terminal')),
			);
		}
	};

	if (isLoading)
		return (
			<div className="awpt-review-bridge__loading">
				<Spinner /> {__('Opening page session…', 'agent-wordpress-terminal')}
			</div>
		);

	const showLatestCard =
		latestAction &&
		(latestAction.status === 'applied' ||
			latestAction.status === 'proposed' ||
			latestAction.status === 'approved');

	const outcomeLabel =
		reviewOutcome?.state === 'failed'
			? __('Page improvement was not applied', 'agent-wordpress-terminal')
			: reviewOutcome?.state === 'verified'
				? __('Visually checked and applied', 'agent-wordpress-terminal')
				: reviewOutcome?.state === 'unverified'
					? __('Applied', 'agent-wordpress-terminal')
					: reviewOutcome?.state === 'no-change'
						? __('No change needed', 'agent-wordpress-terminal')
						: null;
	const actionStatusLabel =
		latestAction?.status === 'applied'
			? outcomeLabel || __('Applied', 'agent-wordpress-terminal')
			: __('Staged', 'agent-wordpress-terminal');

	// Success outcome is summarized on the action card — keep only failures/no-change as banners.
	const showOutcomeBanner =
		reviewOutcome &&
		(reviewOutcome.state === 'failed' || reviewOutcome.state === 'no-change' || !showLatestCard);

	return (
		<div className="awpt-review-bridge">
			<form
				className="awpt-review-bridge__command"
				aria-labelledby={`awpt-improve-${postId}`}
				onSubmit={(event) => {
					event.preventDefault();
					improvePage();
				}}
			>
				<div className="awpt-review-bridge__identity">
					<h2 id={`awpt-improve-${postId}`}>{__('AI improve', 'agent-wordpress-terminal')}</h2>
					<span>{contextLabel}</span>
				</div>
				{contextTurnCount > 0 ? (
					<Button
						className="awpt-review-bridge__reset"
						variant="tertiary"
						type="button"
						onClick={() => void startFreshContext()}
						isBusy={isResetting}
						disabled={isWorking}
					>
						{__('Start fresh', 'agent-wordpress-terminal')}
					</Button>
				) : null}
				<TextControl
					className="awpt-review-bridge__request"
					label={__('Improvement request', 'agent-wordpress-terminal')}
					hideLabelFromVision
					placeholder={__('What should AWPT focus on? (optional)', 'agent-wordpress-terminal')}
					value={reviewerNotes}
					onChange={setReviewerNotes}
					disabled={isWorking}
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<Button
					className="awpt-review-bridge__submit"
					variant="primary"
					type="submit"
					isBusy={isSending || isApplying}
					disabled={isWorking}
				>
					{isSending || isApplying
						? __('Working…', 'agent-wordpress-terminal')
						: __('Improve', 'agent-wordpress-terminal')}
				</Button>
			</form>
			{error ? (
				<Notice status="error" isDismissible onRemove={() => setError('')}>
					{error}
				</Notice>
			) : null}
			{isWorking ? (
				<AgentTurnStatus progress={progress} className="awpt-review-bridge__progress" />
			) : null}
			{showOutcomeBanner && reviewOutcome && outcomeLabel ? (
				<Notice
					className="awpt-review-bridge__notice"
					status={
						reviewOutcome.state === 'failed'
							? 'error'
							: reviewOutcome.state === 'no-change'
								? 'info'
								: reviewOutcome.state === 'unverified'
									? 'warning'
									: 'success'
					}
					isDismissible={false}
				>
					<strong>{outcomeLabel}</strong>
					{reviewOutcome.state === 'failed' || reviewOutcome.state === 'no-change' ? (
						<p>{reviewOutcome.message}</p>
					) : null}
					{reviewOutcome.state === 'failed' && reviewOutcome.errorCode ? (
						<details className="awpt-review-bridge__error-details">
							<summary>{__('Technical details', 'agent-wordpress-terminal')}</summary>
							<code className="awpt-review-bridge__error-code">{reviewOutcome.errorCode}</code>
						</details>
					) : null}
					{reviewOutcome.state === 'failed' ? (
						<div className="awpt-review-bridge__recovery-actions">
							<Button variant="secondary" onClick={improvePage} disabled={isWorking}>
								{__('Retry', 'agent-wordpress-terminal')}
							</Button>
						</div>
					) : null}
				</Notice>
			) : null}
			{showLatestCard && latestAction ? (
				<div
					className={`awpt-review-bridge__action${
						latestAction.status === 'applied' ? ' is-applied' : ''
					}`}
				>
					<div className="awpt-review-bridge__action-body">
						<div className="awpt-review-bridge__action-head">
							<span
								className="awpt-review-bridge__status-pill"
								data-state={
									latestAction.status === 'applied' ? reviewOutcome?.state || 'applied' : 'staged'
								}
							>
								{actionStatusLabel}
							</span>
							<strong className="awpt-review-bridge__action-title">{latestAction.title}</strong>
						</div>
						<ReviewActionSummary
							payload={latestAction.payload}
							applied={latestAction.status === 'applied'}
						/>
					</div>
					<div className="awpt-review-bridge__action-actions">
						{latestAction.status === 'applied' ? (
							<Button variant="secondary" onClick={() => void undoLatest()} disabled={isWorking}>
								{__('Undo', 'agent-wordpress-terminal')}
							</Button>
						) : (
							<Button
								variant="secondary"
								onClick={() => void applyReviewAction(latestAction)}
								disabled={isWorking}
							>
								{__('Apply now', 'agent-wordpress-terminal')}
							</Button>
						)}
					</div>
				</div>
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
	version: REVIEW_BRIDGE_VERSION,
	mount(element, options) {
		const configured = window.awptSettings?.connection?.ready ?? false;
		const hasDomainPack = window.awptSettings?.hasActiveDomainPack !== false;
		render(
			configured ? (
				<>
					{!hasDomainPack ? (
						<div className="awpt-review-bridge__unconfigured">
							<strong>{__('No active theme Domain Pack.', 'agent-wordpress-terminal')}</strong>
							<p>
								{__(
									'Redesign quality is best when the active theme ships an awpt-domain.json Domain Pack. Improve still runs with generic patterns.',
									'agent-wordpress-terminal',
								)}
							</p>
						</div>
					) : null}
					<ReviewAssistant {...options} />
				</>
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
