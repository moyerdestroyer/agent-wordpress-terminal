import apiFetch from '@wordpress/api-fetch';
import type {
	ChatProgress,
	ChatResponse,
	DomainPacksResponse,
	KnowledgeSettings,
	KnowledgeStatus,
	PreviewDetails,
	ProposedAction,
	ProviderBilling,
	SessionSummary,
	ToolCall,
	ToolsResponse,
} from './types';

declare global {
	interface Window {
		awptSettings: {
			apiNamespace: string;
			pluginUrl: string;
			version: string;
			nonce: string;
			proposalTools?: string[];
			improvePagePrompt?: string;
			improvePageEvaluatePrompt?: string;
			improvePageActPrompt?: string;
			reviewBridgeVersion?: number;
			hasActiveDomainPack?: boolean;
			environment?: import('./types').EnvironmentStatus;
			connection?: {
				id: string;
				label: string;
				ready: boolean;
				status: string;
				status_label: string;
				connectors_url: string;
			};
		};
	}
}

const namespace = window.awptSettings?.apiNamespace ?? 'awpt/v1';

const path = (endpoint: string): string => `/${namespace}${endpoint}`;

export async function listSessions(): Promise<SessionSummary[]> {
	return apiFetch<SessionSummary[]>({ path: path('/sessions') });
}

export async function createSession(
	title = 'New session',
	options: { focusPostId?: number; reuseFocus?: boolean } = {},
): Promise<SessionSummary> {
	return apiFetch<SessionSummary>({
		path: path('/sessions'),
		method: 'POST',
		data: {
			title,
			focus_post_id: options.focusPostId ?? 0,
			reuse_focus: options.reuseFocus ?? false,
		},
	});
}

export async function updateSession(id: number, title: string): Promise<SessionSummary> {
	return apiFetch<SessionSummary>({
		path: path(`/sessions/${id}`),
		method: 'PUT',
		data: { title },
	});
}

export async function deleteSession(id: number): Promise<{ deleted: boolean; id: number }> {
	return apiFetch<{ deleted: boolean; id: number }>({
		path: path(`/sessions/${id}`),
		method: 'DELETE',
	});
}

export async function getSession(
	id: number,
	options: { messagesLimit?: number; includeToolOutputs?: boolean } = {},
): Promise<{
	id: number;
	user_id?: number;
	title: string;
	model?: string;
	provider?: string;
	focus_post_id?: number | null;
	focus?: import('./types').FocusSummary | null;
	created_at: string;
	updated_at: string;
	last_turn_id?: string;
	last_turn_outcome?: import('./types').TurnOutcome;
	messages: Array<{ id: number; role: string; content: string; created_at: string }>;
	tool_calls: ToolCall[];
	actions: ProposedAction[];
}> {
	const query = new URLSearchParams();
	const messagesLimit = options.messagesLimit ?? 50;

	if (messagesLimit > 0) {
		query.set('messages_limit', String(messagesLimit));
	}

	if (options.includeToolOutputs) {
		query.set('include_tool_outputs', '1');
	}

	const suffix = query.size > 0 ? `?${query.toString()}` : '';

	return apiFetch({
		path: path(`/sessions/${id}${suffix}`),
	});
}

export interface ComposerAttachment {
	id: number;
	url: string;
	filename: string;
	mime_type?: string;
	kind?: 'image' | 'document';
}

export async function sendMessage(
	sessionId: number,
	message: string,
	attachments: ComposerAttachment[] = [],
	turnId = '',
): Promise<ChatResponse> {
	return apiFetch<ChatResponse>({
		path: path(`/sessions/${sessionId}/chat`),
		method: 'POST',
		data: { message, attachments, turn_id: turnId },
	});
}

export async function getChatProgress(sessionId: number, turnId: string): Promise<ChatProgress> {
	const query = new URLSearchParams({ turn_id: turnId });
	return apiFetch<ChatProgress>({
		path: path(`/sessions/${sessionId}/chat-progress?${query.toString()}`),
	});
}

export async function uploadAttachment(file: File): Promise<{
	id: number;
	url: string;
	mime_type: string;
	filename: string;
	kind: 'image' | 'document';
}> {
	const body = new FormData();
	body.append('file', file);
	return apiFetch({ path: path('/attachments'), method: 'POST', body } as never);
}

export async function updateAction(
	actionId: number,
	operation: 'approve' | 'reject' | 'apply' | 'rollback',
): Promise<ProposedAction> {
	return apiFetch<ProposedAction>({
		path: path(`/actions/${actionId}`),
		method: 'POST',
		data: { operation },
	});
}

export async function fetchActionPreview(actionId: number): Promise<PreviewDetails> {
	return apiFetch<PreviewDetails>({
		path: path(`/actions/${actionId}/preview`),
		method: 'POST',
	});
}

export async function createPreviewCapture(
	sessionId: number,
	payload: {
		action_id?: number;
		post_id?: number;
		url: string;
		viewport: { width: number; height: number };
		dom_snapshot: string;
		image_data?: string;
	},
): Promise<{ id: number; has_image: boolean }> {
	return apiFetch({
		path: path(`/sessions/${sessionId}/captures`),
		method: 'POST',
		data: payload,
	});
}

export async function getKnowledgeStatus(): Promise<KnowledgeStatus> {
	return apiFetch<KnowledgeStatus>({ path: path('/knowledge/status') });
}

export async function getDomainPacks(): Promise<DomainPacksResponse> {
	return apiFetch<DomainPacksResponse>({ path: path('/domain-packs') });
}

export async function updateDomainPacks(disabled: string[]): Promise<DomainPacksResponse> {
	return apiFetch<DomainPacksResponse>({
		path: path('/domain-packs'),
		method: 'POST',
		data: { disabled },
	});
}

export async function rebuildKnowledge(): Promise<{
	status: KnowledgeStatus;
	in_progress?: boolean;
	run_id?: number;
}> {
	return apiFetch<{ status: KnowledgeStatus; in_progress?: boolean; run_id?: number }>({
		path: path('/knowledge/rebuild'),
		method: 'POST',
	});
}

export async function processKnowledge(runId: number): Promise<{
	status: KnowledgeStatus;
	in_progress?: boolean;
	run_id?: number;
}> {
	return apiFetch({
		path: path('/knowledge/process'),
		method: 'POST',
		data: { run_id: runId },
	});
}

export async function getKnowledgeSettings(): Promise<KnowledgeSettings> {
	return apiFetch<KnowledgeSettings>({ path: path('/knowledge/settings') });
}

export async function updateKnowledgeSettings(
	settings: Partial<KnowledgeSettings>,
): Promise<KnowledgeSettings> {
	return apiFetch<KnowledgeSettings>({
		path: path('/knowledge/settings'),
		method: 'PUT',
		data: settings,
	});
}

export async function listAwptTools(): Promise<ToolsResponse> {
	return apiFetch<ToolsResponse>({ path: path('/tools/awpt') });
}

export async function listTools(): Promise<ToolsResponse> {
	return apiFetch<ToolsResponse>({ path: path('/tools') });
}

export async function updateToolEnabled(
	name: string,
	enabled: boolean,
): Promise<import('./types').ToolPreferencesResponse> {
	return apiFetch<import('./types').ToolPreferencesResponse>({
		path: path('/tools/preferences'),
		method: 'POST',
		data: { name, enabled },
	});
}

/** Replace the full deny-list of tools hidden from the agent. */
export async function updateToolsDisabled(
	disabled: string[],
): Promise<import('./types').ToolPreferencesResponse> {
	return apiFetch<import('./types').ToolPreferencesResponse>({
		path: path('/tools/preferences'),
		method: 'POST',
		data: { disabled },
	});
}

export interface IncidentReportPayload {
	kind?: string;
	source?: string;
	attempted_action?: string;
	action_id?: number;
	error_text: string;
	auto_diagnose?: boolean;
}

export interface DiagnosisResponse {
	incident_id: number;
	content?: string;
	tool_calls?: ToolCall[];
	actions?: ProposedAction[];
	diagnosis_response?: ChatResponse;
}

export async function reportIncident(
	sessionId: number,
	payload: IncidentReportPayload,
): Promise<DiagnosisResponse> {
	return apiFetch<DiagnosisResponse>({
		path: path(`/sessions/${sessionId}/incidents`),
		method: 'POST',
		data: payload,
	});
}

export async function diagnoseSession(
	sessionId: number,
	incidentId?: number,
): Promise<ChatResponse & { incident_id: number }> {
	return apiFetch({
		path: path(`/sessions/${sessionId}/diagnose`),
		method: 'POST',
		data: incidentId ? { incident_id: incidentId } : {},
	});
}

/** OpenRouter usage/billing for the active provider (no-op payload when not OpenRouter). */
export async function getProviderBilling(refresh = false): Promise<ProviderBilling> {
	const query = refresh ? '?refresh=1' : '';
	return apiFetch<ProviderBilling>({ path: path(`/provider/billing${query}`) });
}
