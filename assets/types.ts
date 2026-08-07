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

export type ActionOperation = string;

export interface ValidationFinding {
	severity: 'error' | 'warning' | 'info';
	code: string;
	message: string;
	rule_id?: string;
	block_path?: string;
	source?: string;
	suggestion?: string;
	pack_id?: string;
	expected?: string | number | boolean;
	actual?: string | number | boolean;
	docs?: string;
}

export interface SafeFix {
	id: string;
	description: string;
	block_path?: string;
	before_hash?: string;
	after_hash?: string;
	applied: boolean;
}

export interface AgentFeedback {
	outcome: 'ready' | 'needs_evidence' | 'needs_correction' | 'staged' | 'blocked';
	summary: string;
	findings: ValidationFinding[];
	fixes: SafeFix[];
	next_actions: Array<{
		ability: string;
		reason: string;
		input?: Record<string, unknown>;
	}>;
	retry: {
		allowed: boolean;
		attempts_remaining: number;
		preserve_evidence: boolean;
	};
}

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
	pattern_owner?: string;
	pattern_fallback_reason?: string;
	composition_context?: {
		policy?: string;
		theme_name?: string;
		stylesheet?: string;
		template?: string;
		pattern_name?: string;
		pattern_owner?: string;
		fallback_used?: boolean;
		fallback_reason?: string;
	};
	required_attachment_ids?: number[];
	required_document_ids?: number[];
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
	batch_changes?: Array<{
		kind: 'update_attrs' | 'replace_text' | 'remove';
		block_path: string;
		expected_fingerprint?: string;
		block_name?: string;
		attrs?: Record<string, unknown>;
		content?: string;
	}>;
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
	resource_type?: string;
	resource_operation?: string;
	resource_id?: string;
	resource_data?: Record<string, unknown>;
	resource_original?: Record<string, unknown>;
	resource_fingerprint?: string;
	domain_pack_id?: string;
	domain_ability_name?: string;
	domain_payload?: Record<string, unknown>;
	domain_snapshot?: Record<string, unknown>;
	domain_result?: Record<string, unknown>;
	domain_rollback_token?: Record<string, unknown>;
	domain_rollback_result?: Record<string, unknown>;
	domain_applied_fingerprint?: string;
	domain_irreversible?: boolean;
	domain_previewable?: boolean;
	validation_findings?: ValidationFinding[];
	safe_fixes?: SafeFix[];
	ruleset_hash?: string;
	agent_feedback?: AgentFeedback;
	composition_manifest?: {
		patterns: Array<{
			name: string;
			block_path: string;
			mode: string;
			source_hash: string;
			pack_id?: string;
			pack_version?: string;
		}>;
	};
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

export type JsonPrimitive = string | number | boolean | null;
export type JsonValue = JsonPrimitive | JsonValue[] | { [key: string]: JsonValue };

export interface ToolCall {
	id?: number;
	tool: string;
	input: JsonValue;
	output?: JsonValue;
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
	status: 'proposed' | 'approved' | 'rejected' | 'applied' | 'superseded' | 'rolled_back';
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
	replaces?: string;
}

export interface AbilityReplacement {
	fallback: string;
	replacement: string;
	status: 'active' | string;
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
	exposure_policy?: 'contextual' | string;
	replacements?: AbilityReplacement[];
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
		run_id: number;
		state: 'discovering' | 'indexing' | 'idle' | 'failed';
		phase: string;
		processed_sources: number;
		total_sources: number;
		indexed_sources: number;
		indexed_chunks: number;
		embedded_chunks: number;
		unchanged_sources: number;
		failed_sources: number;
	};
	embedding: {
		available: boolean;
		enabled?: boolean;
		provider: string;
		model: string;
		embedded_chunks?: number;
		backlog_chunks?: number;
		last_error?: string;
		label: string;
	};
	profiles: {
		index: string;
		chunker: string;
		embedding: string;
	};
	vector_backend: {
		backend: string;
		available: boolean;
		detail: string;
	};
	recent_failures: Array<{
		source_kind: string;
		source_id: string;
		error_text: string;
	}>;
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

export interface DomainPackStatus {
	id: string;
	label: string;
	version: string;
	source: string;
	enabled: boolean;
	guidance_count: number;
	pattern_catalog: string;
	schema_version?: number;
	rules?: string;
}

export interface DomainPackHealth {
	pack_id: string;
	schema_version: number;
	status: 'healthy' | 'attention';
	guidance_scopes: string[];
	pattern_coverage: {
		registered: number;
		enriched: number;
		header_only: number;
		stale: number;
		missing_docs?: number;
		broken_references?: number;
		sparse_contracts?: number;
	};
	rule_count: number;
	issues: Array<{
		severity: 'error' | 'warning' | 'recommendation';
		code: string;
		message: string;
	}>;
}

export interface DomainPatternSummary {
	name: string;
	title: string;
	description: string;
	owner: string;
	compatibility: string;
	composition_scope: string;
	block_count: number;
	pack_id: string;
	role: string;
	summary: string;
	intents: string[];
	dynamic_content: boolean;
	post_types: string[];
	slot_count: number;
	docs: string;
	preview_url: string;
	preview_alt: string;
	preview_source: string;
}

export interface DomainPacksResponse {
	packs: DomainPackStatus[];
	health: DomainPackHealth[];
	patterns: DomainPatternSummary[];
	knowledge_backend: string;
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

/** OpenRouter key usage / optional account balance for the terminal header. */
export interface ProviderBilling {
	available: boolean;
	reason?: string;
	provider?: string;
	label?: string | null;
	usage?: number;
	usage_daily?: number;
	usage_weekly?: number;
	usage_monthly?: number;
	limit?: number | null;
	limit_remaining?: number | null;
	limit_reset?: string | null;
	is_free_tier?: boolean;
	balance?: number | null;
	total_credits?: number | null;
	total_usage?: number | null;
	fetched_at?: string;
}

export interface ChatResponse {
	content: string;
	turn_outcome?: TurnOutcome;
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

export interface TurnOutcome {
	status: 'staged' | 'no_change' | 'failed';
	message: string;
	error_code?: string;
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
	diagnostics?: {
		provider?: string;
		mode?: string;
		tool_count?: number;
		tools_offered?: number;
		completion_budget?: number;
		request_timeout_seconds?: number;
		proposal_only?: boolean;
		tool_profile?: string;
		design_level?: string;
		content_turn?: boolean;
		content_edit_turn?: boolean;
		presentation_edit?: boolean;
		work_mode?: string;
		auto_retrieve_knowledge?: boolean;
		history_limit?: number;
		tool_allowlist_count?: number;
		turn_phase?: string;
		explore_hops?: number;
		compose_compacted?: boolean;
		evidence_pack_chars?: number;
		parallel_batch_size?: number;
		tool_batch_total?: number;
		last_completed_call?: {
			provider?: string;
			model?: string;
			tool_round?: number | string;
			outcome?: string;
			error_code?: string;
			completion_budget?: number | string;
			prompt_tokens?: number | string;
			completion_tokens?: number | string;
			total_tokens?: number | string;
			duration_ms?: number | string;
			created_at?: string;
		};
	};
}
