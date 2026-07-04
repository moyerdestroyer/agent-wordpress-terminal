import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { actionMetadata, formatValue, titleCase } from '../actionDisplay';
import type { PreviewDetails, ProposedAction } from '../types';

interface PreviewPaneProps {
	preview: PreviewDetails | null;
	action: ProposedAction | null;
}

type PreviewTab = 'preview' | 'compare';

function stripBlocks(content: string): string {
	return content.replace(/<!--[\s\S]*?-->/g, '').trim();
}

function CompareView({ action }: { action: ProposedAction | null }): JSX.Element {
	const buildCompareContent = (
		content?: string,
		status?: string,
		meta?: Record<string, string | number | boolean>,
	): string => {
		const sections = [
			status ? `${__('Status', 'agent-wordpress-terminal')}: ${titleCase(status)}` : '',
			meta && Object.keys(meta).length > 0
				? `${__('Meta', 'agent-wordpress-terminal')}:\n${formatValue(meta)}`
				: '',
			content ? stripBlocks(content) : '',
		].filter(Boolean);

		return sections.join('\n\n');
	};

	const originalContent = buildCompareContent(
		action?.payload?.original_post_content ??
			(action?.payload?.original_settings
				? JSON.stringify(action.payload.original_settings, null, 2)
				: action?.payload?.current_stylesheet),
		action?.payload?.original_post_status,
		action?.payload?.original_post_meta,
	);
	const newContent = buildCompareContent(
		action?.payload?.post_content ??
			(action?.payload?.settings_changes
				? JSON.stringify(action.payload.settings_changes, null, 2)
				: action?.payload?.stylesheet),
		action?.payload?.post_status,
		action?.payload?.post_meta,
	);
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
			{metadata.length > 0 ? (
				<dl className="awpt-action-card__meta">
					{metadata.map((item) => (
						<div key={item.label}>
							<dt>{item.label}</dt>
							<dd>{item.value}</dd>
						</div>
					))}
				</dl>
			) : null}
			<div className="awpt-preview-compare">
				<div>
					<h4>{__('Before', 'agent-wordpress-terminal')}</h4>
					<pre>{originalContent || __('(empty)', 'agent-wordpress-terminal')}</pre>
				</div>
				<div>
					<h4>{__('After', 'agent-wordpress-terminal')}</h4>
					<pre>{newContent || __('(empty)', 'agent-wordpress-terminal')}</pre>
				</div>
			</div>
		</div>
	);
}

export function PreviewPane({ preview, action }: PreviewPaneProps): JSX.Element {
	const [tab, setTab] = useState<PreviewTab>('preview');
	const iframe = preview?.iframe;

	useEffect(() => {
		setTab('preview');
	}, [preview?.preview_url, action?.id]);

	const title = preview?.title ?? action?.title ?? __('Preview', 'agent-wordpress-terminal');

	return (
		<div className="awpt-preview-pane">
			<div className="awpt-preview-pane__header">
				<h3>{title}</h3>
				<div className="awpt-preview-pane__tabs">
					<Button
						variant={tab === 'preview' ? 'primary' : 'secondary'}
						onClick={() => setTab('preview')}
					>
						{__('Preview', 'agent-wordpress-terminal')}
					</Button>
					<Button
						variant={tab === 'compare' ? 'primary' : 'secondary'}
						onClick={() => setTab('compare')}
					>
						{__('Compare', 'agent-wordpress-terminal')}
					</Button>
				</div>
			</div>
			{tab === 'preview' ? (
				iframe?.src ? (
					<iframe
						className="awpt-preview-pane__iframe"
						src={iframe.src}
						title={iframe.title}
						height={iframe.height}
					/>
				) : (
					<p className="awpt-empty">
						{__(
							'Choose Preview on a proposed post action to load a live preview.',
							'agent-wordpress-terminal',
						)}
					</p>
				)
			) : (
				<CompareView action={action} />
			)}
		</div>
	);
}
