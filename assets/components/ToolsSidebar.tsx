import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { updateToolEnabled, updateToolsDisabled } from '../api';
import type { EnvironmentStatus, ToolInfo, ToolsResponse } from '../types';

interface ToolsSidebarProps {
	tools: ToolsResponse | null;
	onToolsChange?: (tools: ToolsResponse) => void;
}

interface ToolSubgroup {
	key: string;
	label: string;
	items: ToolInfo[];
}

function statusText(items: ToolInfo[]): string {
	if (items.length === 0) {
		return __('Not available', 'agent-wordpress-terminal');
	}

	const enabled = items.filter((tool) => tool.enabled !== false && !tool.never_auto).length;

	return sprintf(
		/* translators: 1: enabled tool count, 2: total tool count */
		__('%1$d of %2$d enabled', 'agent-wordpress-terminal'),
		enabled,
		items.length,
	);
}

function toolMode(tool: ToolInfo): string {
	if (tool.never_auto) {
		return __('Human-only', 'agent-wordpress-terminal');
	}

	if (tool.enabled === false) {
		return __('Off', 'agent-wordpress-terminal');
	}

	if (tool.destructive) {
		return __('Write', 'agent-wordpress-terminal');
	}

	if (tool.requires_approval) {
		return __('Stage', 'agent-wordpress-terminal');
	}

	return tool.readonly
		? __('Read', 'agent-wordpress-terminal')
		: __('Manage', 'agent-wordpress-terminal');
}

function toolPermission(tool: ToolInfo): string | null {
	const permission = tool.permission?.trim();

	if (!permission || permission.toLowerCase().startsWith('capability check')) {
		return null;
	}

	return permission;
}

function toolSlug(tool: ToolInfo): string {
	const slash = tool.name.lastIndexOf('/');
	return slash >= 0 ? tool.name.slice(slash + 1) : tool.name;
}

function toolVerb(tool: ToolInfo): string {
	const slug = toolSlug(tool);
	const dash = slug.indexOf('-');
	return dash >= 0 ? slug.slice(0, dash) : slug;
}

function toggleableTools(items: ToolInfo[]): ToolInfo[] {
	// Bulk operations are for ordinary read/stage tools. Mutations and tools with
	// unknown effects require an intentional per-tool trust decision.
	return items.filter((tool) => !tool.never_auto && !tool.requires_trust);
}

/** Collect currently disabled tool names from the payload (deny-list). */
function collectDisabledNames(tools: ToolsResponse | null): string[] {
	if (!tools) {
		return [];
	}

	if (Array.isArray(tools.disabled)) {
		return [...tools.disabled];
	}

	const names: string[] = [];

	for (const tool of [
		...tools.core,
		...tools.plugin,
		...(tools.other ?? []),
		...(tools.mcp ?? []),
	]) {
		if (tool.enabled === false && !tool.never_auto) {
			names.push(tool.name);
		}
	}

	return names;
}

/**
 * Abilities that are not core/ or awpt/, plus any rare non-ability leftovers
 * the backend still exposes under tools.mcp (no separate MCP UI surface).
 */
function otherTools(tools: ToolsResponse | null): ToolInfo[] {
	const abilityOther = tools?.other ?? [];
	const leftovers = tools?.mcp ?? [];

	if (leftovers.length === 0) {
		return abilityOther;
	}

	const seen = new Set(abilityOther.map((tool) => tool.name));
	const merged = [...abilityOther];

	for (const tool of leftovers) {
		if (!seen.has(tool.name)) {
			merged.push(tool);
			seen.add(tool.name);
		}
	}

	return merged;
}

/** Known category / verb keys → human labels. */
function groupLabel(key: string): string {
	const labels: Record<string, string> = {
		site: __('Site', 'agent-wordpress-terminal'),
		user: __('User', 'agent-wordpress-terminal'),
		content: __('Content', 'agent-wordpress-terminal'),
		'ai-experiments': __('AI experiments', 'agent-wordpress-terminal'),
		awpt: __('AWPT', 'agent-wordpress-terminal'),
		read: __('Read', 'agent-wordpress-terminal'),
		list: __('List', 'agent-wordpress-terminal'),
		get: __('Get', 'agent-wordpress-terminal'),
		search: __('Search', 'agent-wordpress-terminal'),
		propose: __('Propose', 'agent-wordpress-terminal'),
		apply: __('Apply', 'agent-wordpress-terminal'),
		render: __('Render', 'agent-wordpress-terminal'),
		analyze: __('Analyze', 'agent-wordpress-terminal'),
		preview: __('Preview', 'agent-wordpress-terminal'),
		diagnose: __('Diagnose', 'agent-wordpress-terminal'),
		probe: __('Probe', 'agent-wordpress-terminal'),
		sideload: __('Media', 'agent-wordpress-terminal'),
	};

	if (labels[key]) {
		return labels[key];
	}

	if (!key) {
		return __('Other', 'agent-wordpress-terminal');
	}

	return key.charAt(0).toUpperCase() + key.slice(1).replace(/-/g, ' ');
}

/**
 * Partition tools into collapsible subgroups.
 * Prefer ability categories when they differ; otherwise group by name verb
 * (read-*, propose-*, list-*, …). Returns a single bucket when grouping
 * would not reduce clutter.
 */
function partitionTools(items: ToolInfo[]): ToolSubgroup[] {
	if (items.length <= 4) {
		return [{ key: 'all', label: '', items }];
	}

	const categories = new Set(
		items.map((tool) => (tool.category || '').trim()).filter((category) => category !== ''),
	);

	const useCategory = categories.size > 1;
	const buckets = new Map<string, ToolInfo[]>();

	for (const tool of items) {
		const key = useCategory ? (tool.category || '').trim() || 'other' : toolVerb(tool) || 'other';
		const list = buckets.get(key) ?? [];
		list.push(tool);
		buckets.set(key, list);
	}

	if (buckets.size <= 1) {
		return [{ key: 'all', label: '', items }];
	}

	return [...buckets.entries()]
		.map(([key, groupItems]) => ({
			key,
			label: groupLabel(key),
			items: groupItems,
		}))
		.sort((a, b) => a.label.localeCompare(b.label));
}

function BulkToggleButtons({
	items,
	busy,
	onSetEnabled,
}: {
	items: ToolInfo[];
	busy: boolean;
	onSetEnabled: (items: ToolInfo[], enabled: boolean) => void;
}): JSX.Element | null {
	const toggleable = toggleableTools(items);

	if (toggleable.length === 0) {
		return null;
	}

	const enabledCount = toggleable.filter((tool) => tool.enabled !== false).length;
	const allOn = enabledCount === toggleable.length;
	const allOff = enabledCount === 0;

	return (
		<div className="awpt-tool-bulk">
			<button
				type="button"
				className="awpt-tool-bulk__btn"
				disabled={busy || allOn}
				onClick={() => {
					onSetEnabled(toggleable, true);
				}}
			>
				{__('Enable all', 'agent-wordpress-terminal')}
			</button>
			<button
				type="button"
				className="awpt-tool-bulk__btn"
				disabled={busy || allOff}
				onClick={() => {
					onSetEnabled(toggleable, false);
				}}
			>
				{__('Disable all', 'agent-wordpress-terminal')}
			</button>
		</div>
	);
}

function ToolItem({
	tool,
	busy,
	onToggle,
}: {
	tool: ToolInfo;
	busy: boolean;
	onToggle: (tool: ToolInfo, enabled: boolean) => void;
}): JSX.Element {
	const permission = toolPermission(tool);
	const canToggle = !tool.never_auto;

	return (
		<div className={`awpt-tool-item${tool.enabled === false ? ' awpt-tool-item--disabled' : ''}`}>
			<div className="awpt-tool-item__main">
				<strong title={tool.label || tool.name}>{tool.name}</strong>
				<span>{toolMode(tool)}</span>
			</div>
			{tool.description ? <p className="awpt-tool-item__description">{tool.description}</p> : null}
			{tool.policy_reason ? (
				<p className="awpt-tool-item__description">{tool.policy_reason}</p>
			) : null}
			<div className="awpt-tool-item__meta">
				{tool.requires_approval ? (
					<span>{__('Stages approval', 'agent-wordpress-terminal')}</span>
				) : null}
				{tool.destructive ? <span>{__('Destructive', 'agent-wordpress-terminal')}</span> : null}
				{permission ? <span>{permission}</span> : null}
				{canToggle ? (
					<label className="awpt-tool-item__toggle">
						<input
							type="checkbox"
							checked={tool.enabled !== false}
							disabled={busy}
							onChange={(event) => {
								onToggle(tool, event.currentTarget.checked);
							}}
						/>
						<span>
							{tool.enabled === false
								? tool.requires_trust
									? __('Trust and enable', 'agent-wordpress-terminal')
									: __('Enable for agent', 'agent-wordpress-terminal')
								: __('Enabled for agent', 'agent-wordpress-terminal')}
						</span>
					</label>
				) : (
					<span>{__('Always human-only', 'agent-wordpress-terminal')}</span>
				)}
			</div>
		</div>
	);
}

function ToolList({
	items,
	busy,
	onToggle,
}: {
	items: ToolInfo[];
	busy: boolean;
	onToggle: (tool: ToolInfo, enabled: boolean) => void;
}): JSX.Element {
	return (
		<>
			{items.map((tool) => (
				<ToolItem key={tool.name} tool={tool} busy={busy} onToggle={onToggle} />
			))}
		</>
	);
}

function ToolGroup({
	title,
	items,
	busy,
	onToggle,
	onSetEnabled,
	defaultOpen = true,
}: {
	title: string;
	items: ToolInfo[];
	busy: boolean;
	onToggle: (tool: ToolInfo, enabled: boolean) => void;
	onSetEnabled: (items: ToolInfo[], enabled: boolean) => void;
	defaultOpen?: boolean;
}): JSX.Element {
	const subgroups = useMemo(() => partitionTools(items), [items]);
	const nested = subgroups.length > 1 && subgroups[0]?.key !== 'all';
	// Keep large nested lists collapsed so the sidebar stays scannable.
	const openSubgroupsByDefault = items.length <= 8;

	return (
		<details className="awpt-tool-group" defaultOpen={defaultOpen}>
			<summary className="awpt-tool-group__header">
				<span className="awpt-tool-group__title">{title}</span>
				<span className="awpt-tool-group__count">{statusText(items)}</span>
			</summary>
			<div className="awpt-tool-group__body">
				{items.length === 0 ? (
					<p className="awpt-empty">{__('None available.', 'agent-wordpress-terminal')}</p>
				) : (
					<>
						<BulkToggleButtons items={items} busy={busy} onSetEnabled={onSetEnabled} />
						{nested ? (
							subgroups.map((group) => (
								<details
									className="awpt-tool-subgroup"
									key={group.key}
									defaultOpen={openSubgroupsByDefault}
								>
									<summary className="awpt-tool-subgroup__header">
										<span className="awpt-tool-subgroup__title">{group.label}</span>
										<span className="awpt-tool-subgroup__count">{statusText(group.items)}</span>
									</summary>
									<div className="awpt-tool-subgroup__body">
										<BulkToggleButtons
											items={group.items}
											busy={busy}
											onSetEnabled={onSetEnabled}
										/>
										<ToolList items={group.items} busy={busy} onToggle={onToggle} />
									</div>
								</details>
							))
						) : (
							<ToolList items={items} busy={busy} onToggle={onToggle} />
						)}
					</>
				)}
			</div>
		</details>
	);
}

function EnvironmentGroup({ environment }: { environment?: EnvironmentStatus }): JSX.Element {
	return (
		<details className="awpt-tool-group" defaultOpen>
			<summary className="awpt-tool-group__header">
				<span className="awpt-tool-group__title">{__('Status', 'agent-wordpress-terminal')}</span>
			</summary>
			<div className="awpt-tool-group__body">
				{environment ? (
					<div className="awpt-tool-item">
						<strong>
							{__('Abilities', 'agent-wordpress-terminal')}: {environment.abilities.label}
						</strong>
						<dl className="awpt-tool-item__facts">
							<div>
								<dt>{__('WordPress', 'agent-wordpress-terminal')}</dt>
								<dd>
									{environment.wordpress.version} / {environment.wordpress.minimum}+
								</dd>
							</div>
							<div>
								<dt>{__('PHP', 'agent-wordpress-terminal')}</dt>
								<dd>
									{environment.php.version} / {environment.php.minimum}+
								</dd>
							</div>
						</dl>
						{environment.warnings.length > 0 ? (
							<ul className="awpt-status-warnings">
								{environment.warnings.map((warning) => (
									<li key={warning}>{warning}</li>
								))}
							</ul>
						) : null}
					</div>
				) : (
					<p className="awpt-empty">{__('Loading…', 'agent-wordpress-terminal')}</p>
				)}
			</div>
		</details>
	);
}

export function ToolsSidebar({ tools, onToolsChange }: ToolsSidebarProps): JSX.Element {
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const core = tools?.core ?? [];
	const plugin = tools?.plugin ?? [];
	const other = useMemo(() => otherTools(tools), [tools]);
	const allTools = useMemo(() => [...core, ...plugin, ...other], [core, plugin, other]);
	const total = allTools.length;
	const enabled =
		tools?.agent_enabled_count ??
		allTools.filter((tool) => tool.enabled !== false && !tool.never_auto).length;

	async function handleToggle(tool: ToolInfo, enabledNext: boolean): Promise<void> {
		setBusy(true);
		setError(null);

		try {
			const response = await updateToolEnabled(tool.name, enabledNext);

			if (response.tools && onToolsChange) {
				onToolsChange(response.tools);
			}
		} catch (toggleError) {
			setError(
				toggleError instanceof Error
					? toggleError.message
					: __('Could not update tool preference.', 'agent-wordpress-terminal'),
			);
		} finally {
			setBusy(false);
		}
	}

	async function handleSetEnabled(items: ToolInfo[], enabledNext: boolean): Promise<void> {
		const names = toggleableTools(items).map((tool) => tool.name);

		if (names.length === 0) {
			return;
		}

		setBusy(true);
		setError(null);

		try {
			const disabled = new Set(collectDisabledNames(tools));

			if (enabledNext) {
				for (const name of names) {
					disabled.delete(name);
				}
			} else {
				for (const name of names) {
					disabled.add(name);
				}
			}

			const response = await updateToolsDisabled([...disabled]);

			if (response.tools && onToolsChange) {
				onToolsChange(response.tools);
			}
		} catch (toggleError) {
			setError(
				toggleError instanceof Error
					? toggleError.message
					: __('Could not update tool preferences.', 'agent-wordpress-terminal'),
			);
		} finally {
			setBusy(false);
		}
	}

	return (
		<div>
			<div className="awpt-tool-summary">
				<strong>{__('Connected tools', 'agent-wordpress-terminal')}</strong>
				<span>
					{sprintf(
						/* translators: 1: enabled count, 2: total discovered */
						__('%1$d enabled · %2$d discovered', 'agent-wordpress-terminal'),
						enabled,
						total,
					)}
				</span>
				<BulkToggleButtons items={allTools} busy={busy} onSetEnabled={handleSetEnabled} />
			</div>
			<p className="awpt-tool-summary__hint">
				{__(
					'WordPress Abilities from core, this plugin, and other plugins or themes are available to the agent. Uncheck tools to hide them from chat.',
					'agent-wordpress-terminal',
				)}
			</p>
			{error ? <p className="awpt-error">{error}</p> : null}
			<EnvironmentGroup environment={tools?.environment} />

			<ToolGroup
				title={__('Core Abilities', 'agent-wordpress-terminal')}
				items={core}
				busy={busy}
				onToggle={handleToggle}
				onSetEnabled={handleSetEnabled}
				defaultOpen
			/>
			<ToolGroup
				title={__('AWPT Abilities', 'agent-wordpress-terminal')}
				items={plugin}
				busy={busy}
				onToggle={handleToggle}
				onSetEnabled={handleSetEnabled}
				defaultOpen
			/>
			<ToolGroup
				title={__('Other abilities & tools', 'agent-wordpress-terminal')}
				items={other}
				busy={busy}
				onToggle={handleToggle}
				onSetEnabled={handleSetEnabled}
				defaultOpen={false}
			/>
		</div>
	);
}
