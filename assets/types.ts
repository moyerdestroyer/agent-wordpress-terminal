export interface AwptSettings {
	apiNamespace: string;
	pluginUrl: string;
	version: string;
	nonce: string;
	environment?: EnvironmentStatus;
}

export interface SessionSummary {
	id: number;
	user_id?: number;
	title: string;
	model?: string;
	provider?: string;
	focus_post_id?: number | null;
	created_at: string;
	updated_at: string;
}

export interface Message {
	id?: number;
	role: 'user' | 'assistant' | 'system' | 'tool';
	content: string;
	created_at?: string;
}

export interface ContextItem {
	id?: number;
	item_type: string;
	item_id: number | null;
	label: string;
	payload?: Record<string, unknown>;
	created_at?: string;
}

export interface ActionPayload {
	operation?: string;
	post_id?: number;
	post_type?: string;
	post_status?: string;
	original_post_title?: string;
	original_post_content?: string;
	post_title?: string;
	post_content?: string;
	preview_url?: string;
	affected?: string;
}

export interface PreviewDetails {
	id?: number;
	preview_url: string;
	title: string;
	status?: string;
	iframe?: {
		src: string;
		title: string;
		height: number;
	};
}

export interface ToolCall {
	id?: number;
	tool: string;
	input: Record<string, unknown>;
	output?: Record<string, unknown> | null;
	status?: string;
	created_at?: string;
}

export interface ProposedAction {
	id?: number;
	session_id?: number;
	title: string;
	description: string;
	payload?: ActionPayload;
	status: 'proposed' | 'approved' | 'rejected' | 'applied';
	created_at?: string;
	updated_at?: string;
}

export interface ToolInfo {
	name: string;
	label: string;
	description: string;
	category: string;
	input_schema?: Record<string, unknown> | null;
	output_schema?: Record<string, unknown> | null;
	permission?: string | null;
	readonly?: boolean | null;
	destructive?: boolean | null;
	requires_approval?: boolean | null;
}

export interface ToolsResponse {
	core: ToolInfo[];
	plugin: ToolInfo[];
	mcp: ToolInfo[];
	environment?: EnvironmentStatus;
}

export interface KnowledgeStatus {
	source_count: number;
	chunk_count: number;
	stale: boolean;
	needs_rebuild: boolean;
	last_indexed_at: string;
	last_error: string;
	embedding: {
		available: boolean;
		provider: string;
		model: string;
		label: string;
	};
	filesystem: {
		allowed_roots: string[];
		max_file_size: number;
	};
	repository: {
		mode: string;
		label: string;
		core_available: boolean;
		legacy_guidelines_available: boolean;
	};
}

export interface KnowledgeSearchItem {
	id: number;
	source_kind: string;
	source_id: string;
	source_post_id: number | null;
	label: string;
	uri: string;
	excerpt: string;
	score: number;
	metadata?: Record<string, unknown>;
}

export interface KnowledgeSettings {
	roots: string[];
	allowed_roots: string[];
	max_file_size: number;
}

export interface EnvironmentStatus {
	php: {
		version: string;
		minimum: string;
		supported: boolean;
	};
	wordpress: {
		version: string;
		minimum: string;
		supported: boolean;
	};
	abilities: {
		available: boolean;
		label: string;
	};
	supported: boolean;
	warnings: string[];
}

export interface McpStatus {
	connected: boolean;
	server_url: string;
	tool_count: number;
	last_sync: string;
	label: string;
}

export interface ChatResponse {
	content: string;
	tool_calls?: ToolCall[];
	actions?: ProposedAction[];
	preview?: PreviewDetails;
	command?: string;
	provider?: string;
	model?: string;
	focus_post_id?: number | null;
}
