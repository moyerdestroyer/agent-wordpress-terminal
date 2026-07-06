export interface AwptSettings {
	apiNamespace: string;
	pluginUrl: string;
	version: string;
	nonce: string;
	environment?: EnvironmentStatus;
}

export interface FocusSummary {
	id: number;
	title: string;
	type: string;
	status: string;
	slug?: string;
	url?: string;
	edit_url?: string;
}

export interface SessionSummary {
	id: number;
	user_id?: number;
	title: string;
	model?: string;
	provider?: string;
	focus_post_id?: number | null;
	focus?: FocusSummary | null;
	created_at: string;
	updated_at: string;
}

export interface Message {
	id?: number;
	role: 'user' | 'assistant' | 'system' | 'tool' | 'incident';
	content: string;
	created_at?: string;
}

export type ActionOperation =
	| 'content_update'
	| 'block_attrs_update'
	| 'new_post'
	| 'site_settings_update'
	| 'theme_switch'
	| 'plugin_deactivate';

export interface ActionPayload {
	operation?: ActionOperation;
	post_id?: number;
	post_type?: string;
	post_status?: string;
	original_post_status?: string;
	original_post_title?: string;
	original_post_content?: string;
	post_title?: string;
	post_content?: string;
	post_meta?: Record<string, string | number | boolean>;
	original_post_meta?: Record<string, string | number | boolean>;
	preview_url?: string;
	preview_autosave_id?: number;
	affected?: string;
	block_path?: string;
	block_name?: string;
	expected_fingerprint?: string;
	attrs?: Record<string, unknown>;
	settings_changes?: Record<string, string | number | boolean>;
	original_settings?: Record<string, string | number | boolean>;
	stylesheet?: string;
	theme_name?: string;
	current_stylesheet?: string;
	current_theme?: string;
	plugin_file?: string;
	plugin_slug?: string;
	plugin_name?: string;
	was_active?: boolean;
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
	output_summary?: string;
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
	source_kinds: Record<string, number>;
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
	session_title?: string;
	focus_post_id?: number | null;
	focus?: FocusSummary | null;
}
