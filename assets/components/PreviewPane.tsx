import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { actionMetadata } from '../actionDisplay';
import { capturePreview, type PreviewCapture } from '../lib/previewCapture';
import type { PreviewDetails, ProposedAction } from '../types';
import { ActionDiffView } from './ActionDiffView';

interface PreviewPaneProps {
	preview: PreviewDetails | null;
	action: ProposedAction | null;
	onCapture?: (capture: PreviewCapture | null) => void;
}

type PreviewTab = 'preview' | 'compare';

function CompareView({ action }: { action: ProposedAction | null }): JSX.Element {
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
			<ActionDiffView payload={action.payload} />
		</div>
	);
}

export function PreviewPane({ preview, action, onCapture }: PreviewPaneProps): JSX.Element {
	const [tab, setTab] = useState<PreviewTab>('preview');
	const iframeRef = useRef<HTMLIFrameElement | null>(null);
	const iframe = preview?.iframe;

	useEffect(() => {
		setTab('preview');
	}, [preview?.preview_url, action?.id]);

	const title = preview?.title ?? action?.title ?? __('Preview', 'agent-wordpress-terminal');
	const captureLoadedPreview = (): void => {
		const iframeElement = iframeRef.current;

		if (!iframeElement || !onCapture) {
			return;
		}

		void capturePreview(iframeElement).then(onCapture);
	};

	return (
		<div className="awpt-preview-pane">
			<div className="awpt-preview-pane__header">
				<div>
					<h3>{title}</h3>
					{action?.id ? (
						<p className="awpt-preview-pane__context">
							{`${__('Current revision', 'agent-wordpress-terminal')} · ${__('Action', 'agent-wordpress-terminal')} #${action.id}`}
							{action.updated_at ? ` · ${action.updated_at}` : ''}
						</p>
					) : null}
				</div>
				<div className="awpt-preview-pane__tabs">
					<Button
						variant={tab === 'preview' ? 'primary' : 'secondary'}
						aria-pressed={tab === 'preview'}
						onClick={() => setTab('preview')}
					>
						{__('Preview', 'agent-wordpress-terminal')}
					</Button>
					<Button
						variant={tab === 'compare' ? 'primary' : 'secondary'}
						aria-pressed={tab === 'compare'}
						onClick={() => setTab('compare')}
					>
						{__('Compare', 'agent-wordpress-terminal')}
					</Button>
				</div>
			</div>
			{tab === 'preview' ? (
				iframe?.src ? (
					<iframe
						ref={iframeRef}
						className="awpt-preview-pane__iframe"
						src={iframe.src}
						title={iframe.title}
						height={iframe.height}
						onLoad={captureLoadedPreview}
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
