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
	| 'block_insert'
	| 'block_remove'
	| 'pattern_insert'
	| 'new_post'
	| 'template_update'
	| 'global_styles_update'
	| 'global_styles_create'
	| 'site_settings_update'
	| 'theme_switch'
	| 'plugin_deactivate'
	| 'custom_css_update';

export interface ActionPayload {
	operation?: ActionOperation;
	post_id?: number;
	post_type?: string;
	post_name?: string;
	post_parent?: number;
	page_template?: string;
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
	position?: string;
	inserted_path?: string;
	block?: Record<string, unknown>;
	blocks?: Record<string, unknown>[];
	inserted_paths?: string[];
	pattern_name?: string;
	pattern_mode?: 'prepend' | 'adapted';
	pattern_title?: string;
	pattern_source?: string;
	required_attachment_ids?: number[];
	required_minimum_library_images?: number;
	required_minimum_visuals?: number;
	required_links?: string[];
	required_pattern_prefix?: string;
	proposal_manifest?: {
		approach?: string;
		requirements?: Array<Record<string, string>>;
		assumptions?: string[];
	};
	decision_trace?: string[];
	repairs_applied?: Array<{
		kind: string;
		block_path: string;
		block_name: string;
		description: string;
	}>;
	template_type?: string;
	template_area?: string;
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
	css?: string;
	original_css?: string;
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
	revision_kind?: 'created' | 'revised' | string;
	revised_action_id?: number;
	removed_action_ids?: number[];
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
	source?: 'ability' | 'mcp' | string;
	enabled?: boolean;
	never_auto?: boolean;
	requires_trust?: boolean;
	trusted?: boolean;
	policy_reason?: string;
}

export interface ToolsResponse {
	core: ToolInfo[];
	plugin: ToolInfo[];
	other?: ToolInfo[];
	/** Rare non-ability leftovers; folded into Other in the Tools UI. */
	mcp?: ToolInfo[];
	disabled?: string[];
	never_auto?: string[];
	agent_enabled_count?: number;
	environment?: EnvironmentStatus;
}

export interface ToolPreferencesResponse {
	disabled: string[];
	never_auto: string[];
	tools?: ToolsResponse;
}

export interface KnowledgeStatus {
	source_count: number;
	source_kinds: Record<string, number>;
	chunk_count: number;
	stale: boolean;
	needs_rebuild: boolean;
	last_indexed_at: string;
	last_error: string;
	progress: {
		state: 'indexing' | 'idle' | 'failed';
		processed_sources: number;
		total_sources: number;
		indexed_sources: number;
		indexed_chunks: number;
		embedded_chunks: number;
	};
	embedding: {
		available: boolean;
		enabled?: boolean;
		provider: string;
		model: string;
		embedded_chunks?: number;
		last_error?: string;
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
		post_type?: string;
	};
}

export interface KnowledgeSettings {
	roots: string[];
	allowed_roots: string[];
	max_file_size: number;
	embeddings_enabled: boolean;
	embeddings_available: boolean;
	embedding_model: string;
	embedding_provider: string;
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
	removed_action_ids?: number[];
	revised_action_id?: number | null;
	revision_kind?: 'created' | 'revised' | string;
}

export interface ChatProgress {
	state: 'pending' | 'active' | 'complete' | 'failed';
	phase: string;
	label: string;
	detail: string;
	completed: number;
	total: number;
	sequence: number;
	updated_at: string;
}
