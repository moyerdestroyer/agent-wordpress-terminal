import { __, sprintf } from '@wordpress/i18n';
import type { EnvironmentStatus, McpStatus, ToolInfo, ToolsResponse } from '../types';

interface ToolsSidebarProps {
	tools: ToolsResponse | null;
	mcpStatus: McpStatus | null;
}

function statusText(items: ToolInfo[]): string {
	if (items.length === 0) {
		return __('Not available', 'agent-wordpress-terminal');
	}

	return sprintf(
		/* translators: %d: tool count */
		__('%d available', 'agent-wordpress-terminal'),
		items.length,
	);
}

function toolMode(tool: ToolInfo): string {
	if (tool.destructive) {
		return __('Write', 'agent-wordpress-terminal');
	}

	if (tool.requires_approval) {
		return __('Approval', 'agent-wordpress-terminal');
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

function ToolGroup({ title, items }: { title: string; items: ToolsResponse['core'] }): JSX.Element {
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

					return (
						<div className="awpt-tool-item" key={tool.name}>
							<div className="awpt-tool-item__main">
								<strong>{tool.name}</strong>
								<span>{toolMode(tool)}</span>
							</div>
							{tool.requires_approval || tool.destructive || permission ? (
								<div className="awpt-tool-item__meta">
									{tool.requires_approval ? (
										<span>{__('Needs approval', 'agent-wordpress-terminal')}</span>
									) : null}
									{tool.destructive ? (
										<span>{__('Destructive', 'agent-wordpress-terminal')}</span>
									) : null}
									{permission ? <span>{permission}</span> : null}
								</div>
							) : null}
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

export function ToolsSidebar({ tools, mcpStatus }: ToolsSidebarProps): JSX.Element {
	const total = (tools?.core.length ?? 0) + (tools?.plugin.length ?? 0) + (tools?.mcp.length ?? 0);

	return (
		<div>
			<div className="awpt-tool-summary">
				<strong>{__('Tool availability', 'agent-wordpress-terminal')}</strong>
				<span>
					{sprintf(
						/* translators: %d: total tool count */
						__('%d registered for agent use', 'agent-wordpress-terminal'),
						total,
					)}
				</span>
			</div>
			<EnvironmentGroup environment={tools?.environment} />

			<ToolGroup
				title={__('Core Abilities', 'agent-wordpress-terminal')}
				items={tools?.core ?? []}
			/>
			<ToolGroup
				title={__('Plugin Abilities', 'agent-wordpress-terminal')}
				items={tools?.plugin ?? []}
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

			<ToolGroup title={__('MCP Tools', 'agent-wordpress-terminal')} items={tools?.mcp ?? []} />
		</div>
	);
}
