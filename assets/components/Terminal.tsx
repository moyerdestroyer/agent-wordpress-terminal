import { Button, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	createSession,
	deleteSession,
	fetchActionPreview,
	getMcpStatus,
	getSession,
	listAwptTools,
	listSessions,
	listTools,
	reportIncident,
	sendMessage,
	updateAction,
	updateSession,
} from '../api';
import { mergeProposalActions, proposalActionsFromToolCalls } from '../proposalActions';
import type {
	ChatResponse,
	McpStatus,
	Message,
	PreviewDetails,
	ProposedAction,
	SessionSummary,
	ToolCall,
	ToolsResponse,
} from '../types';
import { KnowledgePanel } from './KnowledgePanel';
import { PreviewPane } from './PreviewPane';
import { ToolsSidebar } from './ToolsSidebar';
import { Transcript } from './Transcript';

function titleCase(value: string): string {
	return value.replace(/[_-]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function focusLabel(session: SessionSummary | null): string {
	if (!session?.focus_post_id) {
		return __('None', 'agent-wordpress-terminal');
	}

	if (!session.focus) {
		return `#${session.focus_post_id}`;
	}

	return `${session.focus.title} #${session.focus.id}`;
}

function focusMeta(session: SessionSummary): string {
	if (!session.focus) {
		return session.focus_post_id ? `#${session.focus_post_id}` : '';
	}

	return [titleCase(session.focus.type), titleCase(session.focus.status), session.focus.slug]
		.filter(Boolean)
		.join(' · ');
}

function mergeDiagnosisIntoState(
	turnAt: string,
	diagnosis: {
		content?: string;
		tool_calls?: ToolCall[];
		actions?: ProposedAction[];
	},
	setMessages: (updater: (current: Message[]) => Message[]) => void,
	setToolCalls: (updater: (current: ToolCall[]) => ToolCall[]) => void,
	setActions: (updater: (current: ProposedAction[]) => ProposedAction[]) => void,
): void {
	if (diagnosis.content?.trim()) {
		setMessages((current) => [
			...current,
			{
				role: 'incident',
				content: __('Incident auto-diagnosis started.', 'agent-wordpress-terminal'),
				created_at: turnAt,
			},
			{ role: 'assistant', content: diagnosis.content, created_at: turnAt },
		]);
	}

	if (diagnosis.tool_calls?.length) {
		const turnToolCalls = diagnosis.tool_calls.map((call) => ({ ...call, created_at: turnAt }));
		setToolCalls((current) => [...current, ...turnToolCalls]);
		const proposalActions = proposalActionsFromToolCalls(turnToolCalls);

		if (proposalActions.length > 0) {
			setActions((current) => mergeProposalActions(current, proposalActions));
		}
	}

	if (diagnosis.actions?.length) {
		setActions((current) => mergeProposalActions(current, diagnosis.actions ?? []));
	}
}

export function Terminal(): JSX.Element {
	const [sessions, setSessions] = useState<SessionSummary[]>([]);
	const [activeSessionId, setActiveSessionId] = useState<number | null>(null);
	const [messages, setMessages] = useState<Message[]>([]);
	const [toolCalls, setToolCalls] = useState<ToolCall[]>([]);
	const [actions, setActions] = useState<ProposedAction[]>([]);
	const [tools, setTools] = useState<ToolsResponse | null>(null);
	const [mcpStatus, setMcpStatus] = useState<McpStatus | null>(null);
	const [preview, setPreview] = useState<PreviewDetails | null>(null);
	const [previewAction, setPreviewAction] = useState<ProposedAction | null>(null);
	const [isPreviewOpen, setIsPreviewOpen] = useState(false);
	const [input, setInput] = useState('');
	const [commandHistory, setCommandHistory] = useState<string[]>([]);
	const [historyIndex, setHistoryIndex] = useState<number | null>(null);
	const [historyDraft, setHistoryDraft] = useState('');
	const [isLoading, setIsLoading] = useState(true);
	const [isSending, setIsSending] = useState(false);
	const [sidebarTab, setSidebarTab] = useState<'knowledge' | 'tools'>('knowledge');
	const [toolsLoadedFully, setToolsLoadedFully] = useState(false);
	const [editingSessionId, setEditingSessionId] = useState<number | null>(null);
	const [editingSessionTitle, setEditingSessionTitle] = useState('');
	const [confirmDeleteSessionId, setConfirmDeleteSessionId] = useState<number | null>(null);
	const activeSession = sessions.find((session) => session.id === activeSessionId) ?? null;
	const connection = window.awptSettings?.connection;
	const abilitiesStatus = tools?.environment?.abilities;

	useEffect(() => {
		const boot = async (): Promise<void> => {
			try {
				const [sessionList, toolList, mcp] = await Promise.all([
					listSessions(),
					listAwptTools(),
					getMcpStatus(),
				]);

				setSessions(sessionList);
				setTools(toolList);
				setMcpStatus(mcp);

				if (sessionList.length > 0) {
					await loadSession(sessionList[0].id);
				} else {
					const created = await createSession();
					setSessions([created]);
					setActiveSessionId(created.id);
				}
			} finally {
				setIsLoading(false);
			}
		};

		void boot();
	}, []);

	useEffect(() => {
		if (sidebarTab !== 'tools' || toolsLoadedFully) {
			return;
		}

		const loadFullTools = async (): Promise<void> => {
			const fullTools = await listTools();
			setTools(fullTools);
			setToolsLoadedFully(true);
		};

		void loadFullTools();
	}, [sidebarTab, toolsLoadedFully]);

	useEffect(() => {
		if (!activeSessionId) {
			return;
		}

		const reportClientError = (errorText: string, source: string): void => {
			if (!errorText.trim()) {
				return;
			}

			void reportIncident(activeSessionId, {
				kind: 'js',
				source,
				error_text: errorText,
				auto_diagnose: true,
			})
				.then((response) => {
					const diagnosis = response.diagnosis_response;

					if (!diagnosis) {
						return;
					}

					const turnAt = new Date().toISOString();
					mergeDiagnosisIntoState(turnAt, diagnosis, setMessages, setToolCalls, setActions);
				})
				.catch(() => {});
		};

		const onError = (event: ErrorEvent): void => {
			const parts = [event.message, event.filename ? `at ${event.filename}:${event.lineno}` : '']
				.filter(Boolean)
				.join(' ');
			reportClientError(parts, 'awpt-admin');
		};

		const onRejection = (event: PromiseRejectionEvent): void => {
			const reason = event.reason;
			const message =
				reason instanceof Error
					? reason.message
					: typeof reason === 'string'
						? reason
						: 'Unhandled promise rejection';
			reportClientError(message, 'awpt-admin-unhandledrejection');
		};

		window.addEventListener('error', onError);
		window.addEventListener('unhandledrejection', onRejection);

		return () => {
			window.removeEventListener('error', onError);
			window.removeEventListener('unhandledrejection', onRejection);
		};
	}, [activeSessionId]);

	const loadSession = async (sessionId: number): Promise<void> => {
		const session = await getSession(sessionId);
		setActiveSessionId(sessionId);
		setSessions((current) =>
			current.map((item) =>
				item.id === sessionId
					? {
							...item,
							title: session.title,
							user_id: session.user_id,
							model: session.model,
							provider: session.provider,
							focus_post_id: session.focus_post_id,
							focus: session.focus,
							updated_at: session.updated_at,
						}
					: item,
			),
		);
		setMessages(
			session.messages.map((message) => ({
				id: message.id,
				role: message.role as Message['role'],
				content: message.content,
				created_at: message.created_at,
			})),
		);
		const sessionToolCalls = session.tool_calls ?? [];
		setToolCalls(sessionToolCalls);
		setActions(
			mergeProposalActions(
				session.actions.map((action) => ({
					id: action.id,
					session_id: action.session_id,
					title: action.title,
					description: action.description,
					payload: action.payload,
					status: action.status as ProposedAction['status'],
					created_at: action.created_at,
					updated_at: action.updated_at,
				})),
				proposalActionsFromToolCalls(sessionToolCalls),
			),
		);
		setPreview(null);
		setPreviewAction(null);
		setIsPreviewOpen(false);
		setCommandHistory(
			session.messages
				.filter((message) => message.role === 'user' && message.content.trim() !== '')
				.map((message) => message.content),
		);
		setHistoryIndex(null);
		setHistoryDraft('');
	};

	const handleSend = async (): Promise<void> => {
		if (!activeSessionId || !input.trim() || isSending) {
			return;
		}

		const message = input.trim();
		setInput('');
		setIsSending(true);
		setCommandHistory((current) =>
			current[current.length - 1] === message ? current : [...current, message],
		);
		setHistoryIndex(null);
		setHistoryDraft('');

		const turnAt = new Date().toISOString();

		setMessages((current) => [...current, { role: 'user', content: message, created_at: turnAt }]);

		try {
			const response: ChatResponse = await sendMessage(activeSessionId, message);

			if (response.command === 'clear') {
				setMessages([]);
				setToolCalls([]);
				setActions([]);
				setPreview(null);
				setPreviewAction(null);
				setIsPreviewOpen(false);
				return;
			}

			setMessages((current) => [
				...current,
				{ role: 'assistant', content: response.content, created_at: turnAt },
			]);

			if (response.tool_calls?.length) {
				const turnToolCalls = response.tool_calls.map((call) => ({ ...call, created_at: turnAt }));
				setToolCalls((current) => [...current, ...turnToolCalls]);

				const proposalActions = proposalActionsFromToolCalls(turnToolCalls);

				if (proposalActions.length > 0) {
					setActions((current) => mergeProposalActions(current, proposalActions));
				}
			}

			if (response.actions?.length) {
				setActions((current) =>
					mergeProposalActions(
						current,
						response.actions.map((action) => ({
							id: action.id,
							session_id: action.session_id,
							title: action.title,
							description: action.description,
							payload: action.payload,
							status: action.status as ProposedAction['status'],
							created_at: action.created_at,
							updated_at: action.updated_at,
						})),
					),
				);
			}

			if (response.preview?.preview_url) {
				setPreview(response.preview);
				setPreviewAction(null);
				setIsPreviewOpen(true);
			}

			if (
				response.provider ||
				response.model ||
				response.focus_post_id ||
				response.focus ||
				response.session_title
			) {
				setSessions((current) =>
					current.map((session) =>
						session.id === activeSessionId
							? {
									...session,
									title: response.session_title ?? session.title,
									provider: response.provider ?? session.provider,
									model: response.model ?? session.model,
									focus_post_id: response.focus_post_id ?? session.focus_post_id,
									focus: response.focus ?? session.focus,
								}
							: session,
					),
				);
			}
		} catch (error: unknown) {
			let messageText = __('The agent request failed. Try again.', 'agent-wordpress-terminal');

			if (
				error &&
				typeof error === 'object' &&
				'message' in error &&
				typeof error.message === 'string' &&
				error.message.trim() !== ''
			) {
				messageText = error.message;
			}

			setMessages((current) => [...current, { role: 'assistant', content: messageText }]);
		} finally {
			setIsSending(false);
		}
	};

	const handleNewSession = async (): Promise<void> => {
		const created = await createSession();
		setSessions((current) => [created, ...current]);
		setActiveSessionId(created.id);
		setEditingSessionId(null);
		setConfirmDeleteSessionId(null);
		setMessages([]);
		setToolCalls([]);
		setActions([]);
		setPreview(null);
		setPreviewAction(null);
		setIsPreviewOpen(false);
		setCommandHistory([]);
		setHistoryIndex(null);
		setHistoryDraft('');
	};

	const handleStartRename = (session: SessionSummary): void => {
		setEditingSessionId(session.id);
		setEditingSessionTitle(session.title);
		setConfirmDeleteSessionId(null);
	};

	const handleRenameSession = async (): Promise<void> => {
		if (!editingSessionId || !editingSessionTitle.trim()) {
			return;
		}

		const updated = await updateSession(editingSessionId, editingSessionTitle.trim());
		setSessions((current) => current.map((item) => (item.id === updated.id ? updated : item)));
		setEditingSessionId(null);
		setEditingSessionTitle('');
	};

	const clearWorkspace = (): void => {
		setMessages([]);
		setToolCalls([]);
		setActions([]);
		setPreview(null);
		setPreviewAction(null);
		setIsPreviewOpen(false);
		setCommandHistory([]);
		setHistoryIndex(null);
		setHistoryDraft('');
	};

	const handleDeleteSession = async (session: SessionSummary): Promise<void> => {
		if (confirmDeleteSessionId !== session.id) {
			setConfirmDeleteSessionId(session.id);
			setEditingSessionId(null);
			return;
		}

		await deleteSession(session.id);

		const remaining = sessions.filter((item) => item.id !== session.id);
		setSessions(remaining);
		setConfirmDeleteSessionId(null);

		if (session.id !== activeSessionId) {
			return;
		}

		if (remaining.length > 0) {
			await loadSession(remaining[0].id);
			return;
		}

		const created = await createSession();
		setSessions([created]);
		setActiveSessionId(created.id);
		clearWorkspace();
	};

	const reportActionFailure = async (
		action: ProposedAction,
		kind: 'apply_failure' | 'preview_failure',
		messageText: string,
	): Promise<void> => {
		if (!activeSessionId || !action.id) {
			setMessages((current) => [...current, { role: 'assistant', content: messageText }]);
			return;
		}

		try {
			const response = await reportIncident(activeSessionId, {
				kind,
				source: 'actions',
				attempted_action: kind === 'preview_failure' ? 'preview' : 'apply',
				action_id: action.id,
				error_text: messageText,
				auto_diagnose: true,
			});
			const diagnosis = response.diagnosis_response;
			const turnAt = new Date().toISOString();

			if (diagnosis) {
				mergeDiagnosisIntoState(turnAt, diagnosis, setMessages, setToolCalls, setActions);
				return;
			}
		} catch {
			// Fall back to a plain assistant error line.
		}

		setMessages((current) => [...current, { role: 'assistant', content: messageText }]);
	};

	const handleActionOperation = async (
		action: ProposedAction,
		operation: 'approve' | 'reject' | 'apply',
	): Promise<void> => {
		if (!action.id) {
			return;
		}

		try {
			const updated = await updateAction(action.id, operation);
			setActions((current) => current.map((item) => (item.id === updated.id ? updated : item)));
			setPreviewAction((current) => (current?.id === updated.id ? updated : current));
		} catch (error: unknown) {
			let messageText = __('The action request failed. Try again.', 'agent-wordpress-terminal');

			if (
				error &&
				typeof error === 'object' &&
				'message' in error &&
				typeof error.message === 'string' &&
				error.message.trim() !== ''
			) {
				messageText = error.message;
			}

			void reportActionFailure(action, 'apply_failure', messageText);
		}
	};

	const handleActionPreview = async (action: ProposedAction): Promise<void> => {
		if (!action.payload) {
			return;
		}

		setPreviewAction(action);
		setIsPreviewOpen(true);

		if (action.id) {
			try {
				const stagedPreview = await fetchActionPreview(action.id);
				setPreview(stagedPreview);
				return;
			} catch (error: unknown) {
				if (!action.payload.preview_url) {
					let messageText = __(
						'Could not load a preview for this action.',
						'agent-wordpress-terminal',
					);

					if (
						error &&
						typeof error === 'object' &&
						'message' in error &&
						typeof error.message === 'string' &&
						error.message.trim() !== ''
					) {
						messageText = error.message;
					}

					setPreview(null);
					void reportActionFailure(action, 'preview_failure', messageText);
					return;
				}
			}
		}

		setPreview(
			action.payload.preview_url
				? {
						id: action.payload.post_id,
						preview_url: action.payload.preview_url,
						title: action.title,
						iframe: {
							src: action.payload.preview_url,
							title: action.title,
							height: 640,
						},
					}
				: null,
		);
	};

	const canOpenPreview = Boolean(preview || previewAction);

	if (isLoading) {
		return (
			<div className="awpt-terminal">
				<Spinner />
			</div>
		);
	}

	return (
		<div className="awpt-terminal">
			<header className="awpt-header">
				<div className="awpt-header__title">AWPT</div>
				<div className="awpt-header__meta">
					<span>
						{__('Owner', 'agent-wordpress-terminal')}: #
						{activeSession?.user_id ?? __('Unknown', 'agent-wordpress-terminal')}
					</span>
					<span>
						{__('Focus', 'agent-wordpress-terminal')}: {focusLabel(activeSession)}
					</span>
					<span
						className={`awpt-header__status ${
							connection?.ready ? 'awpt-header__status--connected' : 'awpt-header__status--warning'
						}`}
					>
						{__('AI', 'agent-wordpress-terminal')}:{' '}
						{activeSession?.provider ||
							connection?.label ||
							__('Not configured', 'agent-wordpress-terminal')}
						{connection?.status_label ? ` (${connection.status_label})` : ''}
					</span>
					<span
						className={`awpt-header__status ${
							mcpStatus?.connected ? 'awpt-header__status--connected' : ''
						}`}
					>
						MCP: {mcpStatus?.label ?? __('Unknown', 'agent-wordpress-terminal')}
					</span>
					<span
						className={`awpt-header__status ${
							abilitiesStatus?.available
								? 'awpt-header__status--connected'
								: 'awpt-header__status--warning'
						}`}
					>
						{__('Abilities', 'agent-wordpress-terminal')}:{' '}
						{abilitiesStatus?.label ?? __('Unknown', 'agent-wordpress-terminal')}
					</span>
				</div>
			</header>

			<div className="awpt-grid">
				<aside className="awpt-sidebar">
					<div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
						<Button
							variant={sidebarTab === 'knowledge' ? 'primary' : 'secondary'}
							onClick={() => setSidebarTab('knowledge')}
						>
							{__('Knowledge', 'agent-wordpress-terminal')}
						</Button>
						<Button
							variant={sidebarTab === 'tools' ? 'primary' : 'secondary'}
							onClick={() => setSidebarTab('tools')}
						>
							{__('Tools', 'agent-wordpress-terminal')}
						</Button>
					</div>

					{sidebarTab === 'knowledge' ? (
						<KnowledgePanel />
					) : (
						<ToolsSidebar tools={tools} mcpStatus={mcpStatus} />
					)}

					<div style={{ marginTop: 16 }}>
						<h3 className="awpt-section-title">{__('Sessions', 'agent-wordpress-terminal')}</h3>
						<ul className="awpt-list">
							{sessions.map((session) => (
								<li key={session.id}>
									{editingSessionId === session.id ? (
										<div className="awpt-session-edit">
											<input
												type="text"
												value={editingSessionTitle}
												onChange={(event) => setEditingSessionTitle(event.target.value)}
												onKeyDown={(event) => {
													if (event.key === 'Enter') {
														void handleRenameSession();
													}
													if (event.key === 'Escape') {
														setEditingSessionId(null);
													}
												}}
											/>
											<Button
												variant="primary"
												onClick={() => void handleRenameSession()}
												disabled={!editingSessionTitle.trim()}
											>
												{__('Save', 'agent-wordpress-terminal')}
											</Button>
											<Button variant="tertiary" onClick={() => setEditingSessionId(null)}>
												{__('Cancel', 'agent-wordpress-terminal')}
											</Button>
										</div>
									) : (
										<>
											<Button
												variant="link"
												onClick={() => void loadSession(session.id)}
												style={{ padding: 0, height: 'auto' }}
											>
												{session.title} #{session.id}
											</Button>
											<div className="awpt-session-actions">
												<Button variant="link" onClick={() => handleStartRename(session)}>
													{__('Rename', 'agent-wordpress-terminal')}
												</Button>
												<Button
													variant="link"
													onClick={() => void handleDeleteSession(session)}
													isDestructive
												>
													{confirmDeleteSessionId === session.id
														? __('Confirm delete', 'agent-wordpress-terminal')
														: __('Delete', 'agent-wordpress-terminal')}
												</Button>
											</div>
										</>
									)}
									{session.focus_post_id ? (
										<div className="awpt-list-meta">
											{__('Focus', 'agent-wordpress-terminal')}: {focusLabel(session)}
											{focusMeta(session) ? (
												<span className="awpt-list-meta__detail">{focusMeta(session)}</span>
											) : null}
										</div>
									) : null}
									{session.user_id ? (
										<div className="awpt-list-meta">
											{__('Owner', 'agent-wordpress-terminal')}: #{session.user_id}
										</div>
									) : null}
								</li>
							))}
						</ul>
						<Button variant="secondary" onClick={() => void handleNewSession()}>
							{__('New session', 'agent-wordpress-terminal')}
						</Button>
					</div>
				</aside>

				<main className="awpt-transcript">
					<Transcript
						messages={messages}
						toolCalls={toolCalls}
						actions={actions}
						isThinking={isSending}
						onActionOperation={(action, operation) => void handleActionOperation(action, operation)}
						onActionPreview={handleActionPreview}
					/>
					<div className="awpt-command-bar">
						<Button
							variant="secondary"
							onClick={() => setIsPreviewOpen(true)}
							disabled={!canOpenPreview}
						>
							{__('Preview', 'agent-wordpress-terminal')}
						</Button>
						<input
							type="text"
							value={input}
							onChange={(event) => {
								setInput(event.target.value);

								if (historyIndex !== null) {
									setHistoryIndex(null);
								}
							}}
							placeholder={__(
								'Ask about a page, post, or site task...',
								'agent-wordpress-terminal',
							)}
							onKeyDown={(event) => {
								if (event.key === 'Enter') {
									void handleSend();
									return;
								}

								if (event.key === 'ArrowUp') {
									if (commandHistory.length === 0) {
										return;
									}

									event.preventDefault();

									const nextIndex =
										historyIndex === null
											? commandHistory.length - 1
											: Math.max(0, historyIndex - 1);

									if (historyIndex === null) {
										setHistoryDraft(input);
									}

									setHistoryIndex(nextIndex);
									setInput(commandHistory[nextIndex]);
									return;
								}

								if (event.key === 'ArrowDown') {
									if (historyIndex === null) {
										return;
									}

									event.preventDefault();

									const nextIndex = historyIndex + 1;

									if (nextIndex >= commandHistory.length) {
										setHistoryIndex(null);
										setInput(historyDraft);
										return;
									}

									setHistoryIndex(nextIndex);
									setInput(commandHistory[nextIndex]);
								}
							}}
							disabled={isSending}
						/>
						<Button variant="primary" onClick={() => void handleSend()} disabled={isSending}>
							{isSending
								? __('Sending…', 'agent-wordpress-terminal')
								: __('Send', 'agent-wordpress-terminal')}
						</Button>
					</div>
				</main>

				{isPreviewOpen ? (
					<aside
						className="awpt-preview-drawer"
						aria-label={__('Preview', 'agent-wordpress-terminal')}
					>
						<div className="awpt-preview-drawer__bar">
							<div>
								<strong>{__('Preview workspace', 'agent-wordpress-terminal')}</strong>
								<span>
									{previewAction?.title ??
										preview?.title ??
										__('Preview', 'agent-wordpress-terminal')}
								</span>
							</div>
							<Button variant="secondary" onClick={() => setIsPreviewOpen(false)}>
								{__('Close', 'agent-wordpress-terminal')}
							</Button>
						</div>
						<PreviewPane preview={preview} action={previewAction} />
					</aside>
				) : null}
			</div>
		</div>
	);
}
