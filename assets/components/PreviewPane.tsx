import { Button } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type {
	ActionPayload,
	ContextItem,
	PreviewDetails,
	ProposedAction,
	ToolCall,
} from '../types';

interface PreviewPaneProps {
	preview: PreviewDetails | null;
	contextItems: ContextItem[];
	toolCalls: ToolCall[];
	action: ProposedAction | null;
}

type PreviewTab = 'preview' | 'inspector' | 'compare';

function latestToolOutput(toolCalls: ToolCall[], tool: string): Record<string, unknown> | null {
	const call = [...toolCalls].reverse().find((item) => item.tool === tool);

	return call?.output ?? null;
}

function formatValue(value: unknown): string {
	if (typeof value === 'string') {
		return value;
	}

	if (value === null || value === undefined) {
		return '';
	}

	return JSON.stringify(value, null, 2);
}

function stripBlocks(content: string): string {
	return content.replace(/<!--[\s\S]*?-->/g, '').trim();
}

function titleCase(value: string): string {
	return value.replace(/[_-]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function actionMetadata(payload?: ActionPayload): Array<{ label: string; value: string }> {
	if (!payload) {
		return [];
	}

	const postTitle = payload.original_post_title || payload.post_title || '';
	const postType = payload.post_type
		? titleCase(payload.post_type)
		: __('Post/Page', 'agent-wordpress-terminal');
	const target = [
		postType,
		payload.post_id ? `#${payload.post_id}` : '',
		postTitle ? `- ${postTitle}` : '',
	]
		.filter(Boolean)
		.join(' ');

	return [
		{
			label: __('Target', 'agent-wordpress-terminal'),
			value: target,
		},
		{
			label: __('Status', 'agent-wordpress-terminal'),
			value: payload.post_status ? titleCase(payload.post_status) : '',
		},
		{
			label: __('Blocks / area', 'agent-wordpress-terminal'),
			value: payload.affected ?? '',
		},
	].filter((item) => item.value !== '');
}

function InspectorView({
	contextItems,
	toolCalls,
}: {
	contextItems: ContextItem[];
	toolCalls: ToolCall[];
}): JSX.Element {
	const blockTree = latestToolOutput(toolCalls, 'awpt/read-block-tree');
	const analysis = latestToolOutput(toolCalls, 'awpt/analyze-page');
	const readContent = latestToolOutput(toolCalls, 'awpt/read-content');
	const blocks = blockTree?.blocks ?? analysis?.block_tree ?? [];

	return (
		<div className="awpt-preview-panel">
			<h3 className="awpt-section-title">{__('Evidence', 'agent-wordpress-terminal')}</h3>
			<dl className="awpt-inspector-list">
				<div>
					<dt>{__('Historical context', 'agent-wordpress-terminal')}</dt>
					<dd>{contextItems.length}</dd>
				</div>
				<div>
					<dt>{__('Latest content', 'agent-wordpress-terminal')}</dt>
					<dd>
						{(readContent?.title as string | undefined) ?? __('None', 'agent-wordpress-terminal')}
					</dd>
				</div>
				<div>
					<dt>{__('Risk', 'agent-wordpress-terminal')}</dt>
					<dd>
						{(analysis?.risk_level as string | undefined) ??
							__('Unknown', 'agent-wordpress-terminal')}
					</dd>
				</div>
			</dl>

			<div className="awpt-preview-detail">
				<strong>{__('Block tree', 'agent-wordpress-terminal')}</strong>
				{Array.isArray(blocks) && blocks.length > 0 ? (
					<pre>{JSON.stringify(blocks, null, 2)}</pre>
				) : (
					<span>
						{__(
							'Ask the agent to analyze a page or inspect its block structure.',
							'agent-wordpress-terminal',
						)}
					</span>
				)}
			</div>
		</div>
	);
}

function CompareView({ action }: { action: ProposedAction | null }): JSX.Element {
	const originalTitle = action?.payload?.original_post_title ?? '';
	const newTitle = action?.payload?.post_title ?? originalTitle;
	const originalContent = stripBlocks(action?.payload?.original_post_content ?? '');
	const newContent = stripBlocks(action?.payload?.post_content ?? '');
	const metadata = actionMetadata(action?.payload);

	if (!action) {
		return (
			<div className="awpt-preview-panel">
				<h3 className="awpt-section-title">{__('Before / After', 'agent-wordpress-terminal')}</h3>
				<p className="awpt-empty">
					{__(
						'Choose Preview on a proposed action to compare staged changes.',
						'agent-wordpress-terminal',
					)}
				</p>
			</div>
		);
	}

	return (
		<div className="awpt-preview-panel">
			<h3 className="awpt-section-title">{__('Before / After', 'agent-wordpress-terminal')}</h3>
			<div className="awpt-compare-meta">
				<strong>{action.title}</strong>
				<span>{action.status}</span>
			</div>
			{metadata.length > 0 ? (
				<dl className="awpt-compare-context">
					{metadata.map((item) => (
						<div key={item.label}>
							<dt>{item.label}</dt>
							<dd>{item.value}</dd>
						</div>
					))}
				</dl>
			) : null}
			<div className="awpt-compare-grid">
				<div>
					<h4>{__('Before', 'agent-wordpress-terminal')}</h4>
					{originalTitle ? <strong>{originalTitle}</strong> : null}
					<pre>{formatValue(originalContent || action.payload?.original_post_content)}</pre>
				</div>
				<div>
					<h4>{__('After', 'agent-wordpress-terminal')}</h4>
					{newTitle ? <strong>{newTitle}</strong> : null}
					<pre>{formatValue(newContent || action.payload?.post_content)}</pre>
				</div>
			</div>
		</div>
	);
}

export function PreviewPane({
	preview,
	contextItems,
	toolCalls,
	action,
}: PreviewPaneProps): JSX.Element {
	const [tab, setTab] = useState<PreviewTab>('preview');
	const hasComparison = Boolean(action);
	const iframeSrc = preview?.iframe?.src ?? preview?.preview_url ?? null;
	const iframeTitle =
		preview?.iframe?.title ?? preview?.title ?? __('Preview', 'agent-wordpress-terminal');

	const tabs = useMemo(
		() => [
			{ key: 'preview' as const, label: __('Preview', 'agent-wordpress-terminal') },
			{ key: 'inspector' as const, label: __('Evidence', 'agent-wordpress-terminal') },
			{ key: 'compare' as const, label: __('Compare', 'agent-wordpress-terminal') },
		],
		[],
	);

	return (
		<div className="awpt-preview-pane">
			<div className="awpt-preview-tabs">
				{tabs.map((item) => (
					<Button
						key={item.key}
						variant={tab === item.key ? 'primary' : 'secondary'}
						onClick={() => setTab(item.key)}
						disabled={item.key === 'compare' && !hasComparison}
					>
						{item.label}
					</Button>
				))}
			</div>

			{tab === 'preview' ? (
				<div className="awpt-preview-panel">
					<h3 className="awpt-section-title">{__('Preview', 'agent-wordpress-terminal')}</h3>
					{iframeSrc ? (
						<>
							<p className="awpt-empty" style={{ marginBottom: 8 }}>
								{preview?.title}
							</p>
							<iframe className="awpt-preview-frame" src={iframeSrc} title={iframeTitle} />
						</>
					) : (
						<p className="awpt-empty">
							{__(
								'Use /preview {id} to review a page, or Preview on a proposed action to inspect staged changes. Knowledge retrieval appears in tool evidence, not this preview frame.',
								'agent-wordpress-terminal',
							)}
						</p>
					)}
				</div>
			) : null}

			{tab === 'inspector' ? (
				<InspectorView contextItems={contextItems} toolCalls={toolCalls} />
			) : null}

			{tab === 'compare' ? <CompareView action={action} /> : null}
		</div>
	);
}
