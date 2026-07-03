import apiFetch from '@wordpress/api-fetch';
import type {
	ChatResponse,
	ContextItem,
	KnowledgeSearchItem,
	KnowledgeSettings,
	KnowledgeStatus,
	McpStatus,
	ProposedAction,
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

export async function createSession(title = 'New session'): Promise<SessionSummary> {
	return apiFetch<SessionSummary>({
		path: path('/sessions'),
		method: 'POST',
		data: { title },
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

export async function getSession(id: number): Promise<{
	id: number;
	title: string;
	model?: string;
	provider?: string;
	focus_post_id?: number | null;
	created_at: string;
	updated_at: string;
	messages: Array<{ id: number; role: string; content: string; created_at: string }>;
	tool_calls: ToolCall[];
	context: ContextItem[];
	actions: ProposedAction[];
}> {
	return apiFetch({
		path: path(`/sessions/${id}`),
	});
}

export async function sendMessage(sessionId: number, message: string): Promise<ChatResponse> {
	return apiFetch<ChatResponse>({
		path: path(`/sessions/${sessionId}/chat`),
		method: 'POST',
		data: { message },
	});
}

export async function updateAction(
	actionId: number,
	operation: 'approve' | 'reject' | 'apply',
): Promise<ProposedAction> {
	return apiFetch<ProposedAction>({
		path: path(`/actions/${actionId}`),
		method: 'POST',
		data: { operation },
	});
}

export async function getKnowledgeStatus(): Promise<KnowledgeStatus> {
	return apiFetch<KnowledgeStatus>({ path: path('/knowledge/status') });
}

export async function rebuildKnowledge(): Promise<{ status: KnowledgeStatus }> {
	return apiFetch<{ status: KnowledgeStatus }>({
		path: path('/knowledge/rebuild'),
		method: 'POST',
	});
}

export async function searchKnowledge(query: string): Promise<{ items: KnowledgeSearchItem[] }> {
	return apiFetch<{ items: KnowledgeSearchItem[] }>({
		path: path(`/knowledge/search?query=${encodeURIComponent(query)}`),
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

export async function listTools(): Promise<ToolsResponse> {
	return apiFetch<ToolsResponse>({ path: path('/tools') });
}

export async function getMcpStatus(): Promise<McpStatus> {
	return apiFetch<McpStatus>({ path: path('/mcp/status') });
}
