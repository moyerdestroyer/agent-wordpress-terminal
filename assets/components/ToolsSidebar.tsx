import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { updateToolEnabled } from '../api';
import type { EnvironmentStatus, McpStatus, ToolInfo, ToolsResponse } from '../types';

interface ToolsSidebarProps {
	tools: ToolsResponse | null;
	mcpStatus: McpStatus | null;
	onToolsChange?: (tools: ToolsResponse) => void;
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

function ToolGroup({
	title,
	items,
	busyName,
	onToggle,
}: {
	title: string;
	items: ToolInfo[];
	busyName: string | null;
	onToggle: (tool: ToolInfo, enabled: boolean) => void;
}): JSX.Element {
	return (
		<div className="awpt-tool-group">
			<div className="awpt-tool-group__header">
				<h3 className="awpt-section-title">{title}</h3>
				<span>{statusText(items)}</span>
			</div>
			{items.length === 0 ? (
				<p className="awpt-empty">{__('None available.', 'agent-wordpress-terminal')}</p>
			) : (
				items.map((tool) => {
					const permission = toolPermission(tool);
					const canToggle = !tool.never_auto;

					return (
						<div
							className={`awpt-tool-item${tool.enabled === false ? ' awpt-tool-item--disabled' : ''}`}
							key={tool.name}
						>
							<div className="awpt-tool-item__main">
								<strong>{tool.name}</strong>
								<span>{toolMode(tool)}</span>
							</div>
							{tool.description ? (
								<p className="awpt-tool-item__description">{tool.description}</p>
							) : null}
							<div className="awpt-tool-item__meta">
								{tool.requires_approval ? (
									<span>{__('Stages approval', 'agent-wordpress-terminal')}</span>
								) : null}
								{tool.destructive ? (
									<span>{__('Destructive', 'agent-wordpress-terminal')}</span>
								) : null}
								{permission ? <span>{permission}</span> : null}
								{canToggle ? (
									<label className="awpt-tool-item__toggle">
										<input
											type="checkbox"
											checked={tool.enabled !== false}
											disabled={busyName === tool.name}
											onChange={(event) => {
												onToggle(tool, event.currentTarget.checked);
											}}
										/>
										<span>
											{tool.enabled === false
												? __('Enable for agent', 'agent-wordpress-terminal')
												: __('Enabled for agent', 'agent-wordpress-terminal')}
										</span>
									</label>
								) : (
									<span>{__('Always human-only', 'agent-wordpress-terminal')}</span>
								)}
							</div>
						</div>
					);
				})
			)}
		</div>
	);
}

function EnvironmentGroup({ environment }: { environment?: EnvironmentStatus }): JSX.Element {
	return (
		<div className="awpt-tool-group">
			<h3 className="awpt-section-title">{__('Status', 'agent-wordpress-terminal')}</h3>
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
	);
}

export function ToolsSidebar({ tools, mcpStatus, onToolsChange }: ToolsSidebarProps): JSX.Element {
	const [busyName, setBusyName] = useState<string | null>(null);
	const [error, setError] = useState<string | null>(null);
	const core = tools?.core ?? [];
	const plugin = tools?.plugin ?? [];
	const other = tools?.other ?? [];
	const mcp = tools?.mcp ?? [];
	const total = core.length + plugin.length + other.length + mcp.length;
	const enabled =
		tools?.agent_enabled_count ??
		[...core, ...plugin, ...other, ...mcp].filter(
			(tool) => tool.enabled !== false && !tool.never_auto,
		).length;

	async function handleToggle(tool: ToolInfo, enabledNext: boolean): Promise<void> {
		setBusyName(tool.name);
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
			setBusyName(null);
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
			</div>
			<p className="awpt-tool-summary__hint">
				{__(
					'Any plugin or theme ability and connected MCP tool is available to the agent. Uncheck tools to hide them from chat.',
					'agent-wordpress-terminal',
				)}
			</p>
			{error ? <p className="awpt-error">{error}</p> : null}
			<EnvironmentGroup environment={tools?.environment} />

			<ToolGroup
				title={__('Core Abilities', 'agent-wordpress-terminal')}
				items={core}
				busyName={busyName}
				onToggle={handleToggle}
			/>
			<ToolGroup
				title={__('AWPT Abilities', 'agent-wordpress-terminal')}
				items={plugin}
				busyName={busyName}
				onToggle={handleToggle}
			/>
			<ToolGroup
				title={__('Other plugin & theme abilities', 'agent-wordpress-terminal')}
				items={other}
				busyName={busyName}
				onToggle={handleToggle}
			/>

			<div className="awpt-tool-group">
				<h3 className="awpt-section-title">{__('MCP', 'agent-wordpress-terminal')}</h3>
				{mcpStatus ? (
					<div className="awpt-tool-item">
						<strong>{mcpStatus.label}</strong>
						<dl className="awpt-tool-item__facts">
							<div>
								<dt>{__('Tools', 'agent-wordpress-terminal')}</dt>
								<dd>{mcpStatus.tool_count}</dd>
							</div>
							<div>
								<dt>{__('Server', 'agent-wordpress-terminal')}</dt>
								<dd>{mcpStatus.server_url || __('Not configured', 'agent-wordpress-terminal')}</dd>
							</div>
							<div>
								<dt>{__('Last sync', 'agent-wordpress-terminal')}</dt>
								<dd>{mcpStatus.last_sync || __('Never', 'agent-wordpress-terminal')}</dd>
							</div>
						</dl>
					</div>
				) : (
					<p className="awpt-empty">{__('Loading…', 'agent-wordpress-terminal')}</p>
				)}
			</div>

			<ToolGroup
				title={__('MCP-only tools', 'agent-wordpress-terminal')}
				items={mcp}
				busyName={busyName}
				onToggle={handleToggle}
			/>
		</div>
	);
}
