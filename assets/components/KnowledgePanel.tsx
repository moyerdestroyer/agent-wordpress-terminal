import { Button, TextareaControl, TextControl, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	getKnowledgeSettings,
	getKnowledgeStatus,
	rebuildKnowledge,
	updateKnowledgeSettings,
} from '../api';
import type { KnowledgeSettings, KnowledgeStatus } from '../types';

function formatBytes(value: number): string {
	if (value >= 1048576) {
		return `${Math.round(value / 104857.6) / 10} MB`;
	}

	return `${Math.max(1, Math.round(value / 1024))} KB`;
}

function sourceCount(status: KnowledgeStatus | null, kind: string): number {
	return status?.source_kinds?.[kind] ?? 0;
}

function indexedLabel(count: number): string {
	if (count > 0) {
		return sprintf(
			/* translators: %d: indexed source count */
			__('%d indexed', 'agent-wordpress-terminal'),
			count,
		);
	}

	return __('Detected, not indexed', 'agent-wordpress-terminal');
}

function knowledgeSourceRows(status: KnowledgeStatus | null): Array<{
	key: string;
	label: string;
	state: string;
	available: boolean;
}> {
	const coreCount = sourceCount(status, 'core_knowledge');
	const legacyCount = sourceCount(status, 'legacy_guideline');
	const contentCount = sourceCount(status, 'wp_content');
	const filesystemCount = sourceCount(status, 'filesystem');
	const configuredRoots = status?.filesystem.allowed_roots.length ?? 0;

	return [
		{
			key: 'core',
			label: __('Core Knowledge', 'agent-wordpress-terminal'),
			state: status?.repository.core_available
				? indexedLabel(coreCount)
				: __('Not detected', 'agent-wordpress-terminal'),
			available: Boolean(status?.repository.core_available),
		},
		{
			key: 'legacy',
			label: __('Guidelines', 'agent-wordpress-terminal'),
			state: status?.repository.legacy_guidelines_available
				? indexedLabel(legacyCount)
				: __('Not detected', 'agent-wordpress-terminal'),
			available: Boolean(status?.repository.legacy_guidelines_available),
		},
		{
			key: 'content',
			label: __('Site content', 'agent-wordpress-terminal'),
			state:
				contentCount > 0
					? indexedLabel(contentCount)
					: __('No indexed entries', 'agent-wordpress-terminal'),
			available: contentCount > 0,
		},
		{
			key: 'filesystem',
			label: __('Theme & docs', 'agent-wordpress-terminal'),
			state:
				filesystemCount > 0
					? indexedLabel(filesystemCount)
					: configuredRoots > 0
						? __('No indexed files yet', 'agent-wordpress-terminal')
						: __('No open roots', 'agent-wordpress-terminal'),
			available: filesystemCount > 0 || configuredRoots > 0,
		},
	];
}

export function KnowledgePanel(): JSX.Element {
	const [status, setStatus] = useState<KnowledgeStatus | null>(null);
	const [settings, setSettings] = useState<KnowledgeSettings | null>(null);
	const [rootsText, setRootsText] = useState('');
	const [maxFileSize, setMaxFileSize] = useState('2097152');
	const [embeddingsEnabled, setEmbeddingsEnabled] = useState(true);
	const [embeddingModel, setEmbeddingModel] = useState('openai/text-embedding-3-small');
	const [isLoading, setIsLoading] = useState(true);
	const [isRebuilding, setIsRebuilding] = useState(false);
	const [autoRebuildAttempted, setAutoRebuildAttempted] = useState(false);
	const [isSaving, setIsSaving] = useState(false);

	const refresh = async (): Promise<KnowledgeStatus> => {
		const [nextStatus, nextSettings] = await Promise.all([
			getKnowledgeStatus(),
			getKnowledgeSettings(),
		]);
		setStatus(nextStatus);
		setSettings(nextSettings);
		setRootsText(nextSettings.roots.join('\n'));
		setMaxFileSize(String(nextSettings.max_file_size));
		setEmbeddingsEnabled(Boolean(nextSettings.embeddings_enabled));
		setEmbeddingModel(nextSettings.embedding_model || 'openai/text-embedding-3-small');

		return nextStatus;
	};

	useEffect(() => {
		const boot = async (): Promise<void> => {
			try {
				const nextStatus = await refresh();

				if (nextStatus.needs_rebuild && !autoRebuildAttempted) {
					setAutoRebuildAttempted(true);
					await handleRebuild();
				}
			} finally {
				setIsLoading(false);
			}
		};

		void boot();
	}, []);

	const handleRebuild = async (): Promise<void> => {
		setIsRebuilding(true);

		try {
			const response = await rebuildKnowledge();
			setStatus(response.status);
		} finally {
			setIsRebuilding(false);
		}
	};

	const handleSaveSettings = async (): Promise<void> => {
		setIsSaving(true);

		try {
			const saved = await updateKnowledgeSettings({
				roots: rootsText
					.split('\n')
					.map((item) => item.trim())
					.filter(Boolean),
				max_file_size: Number.parseInt(maxFileSize, 10) || 2097152,
				embeddings_enabled: embeddingsEnabled,
				embedding_model: embeddingModel.trim() || 'openai/text-embedding-3-small',
			});
			setSettings(saved);
			setRootsText(saved.roots.join('\n'));
			setMaxFileSize(String(saved.max_file_size));
			setEmbeddingsEnabled(Boolean(saved.embeddings_enabled));
			setEmbeddingModel(saved.embedding_model || 'openai/text-embedding-3-small');
			await handleRebuild();
		} finally {
			setIsSaving(false);
		}
	};

	if (isLoading) {
		return <p className="awpt-empty">{__('Loading Knowledge…', 'agent-wordpress-terminal')}</p>;
	}

	return (
		<div className="awpt-knowledge">
			<h3 className="awpt-section-title">{__('Knowledge', 'agent-wordpress-terminal')}</h3>
			<ul className="awpt-knowledge-sources">
				{knowledgeSourceRows(status).map((source) => (
					<li key={source.key} className={source.available ? 'is-available' : 'is-unavailable'}>
						<span>{source.label}</span>
						<strong>{source.state}</strong>
					</li>
				))}
			</ul>

			<dl className="awpt-knowledge-status">
				<div>
					<dt>{__('Backend', 'agent-wordpress-terminal')}</dt>
					<dd>{status?.repository.label ?? __('Unknown', 'agent-wordpress-terminal')}</dd>
				</div>
				<div>
					<dt>{__('Index', 'agent-wordpress-terminal')}</dt>
					<dd>
						{sprintf(
							/* translators: 1: source count, 2: chunk count */
							__('%1$d sources / %2$d chunks', 'agent-wordpress-terminal'),
							status?.source_count ?? 0,
							status?.chunk_count ?? 0,
						)}
					</dd>
				</div>
				<div>
					<dt>{__('Retrieval', 'agent-wordpress-terminal')}</dt>
					<dd>{status?.embedding.label ?? __('Keyword retrieval', 'agent-wordpress-terminal')}</dd>
				</div>
				<div>
					<dt>{__('Open roots', 'agent-wordpress-terminal')}</dt>
					<dd>
						{(status?.filesystem.allowed_roots.length ?? 0) > 0
							? sprintf(
									/* translators: %d: number of document roots */
									__('%d folders (theme + uploads + custom)', 'agent-wordpress-terminal'),
									status?.filesystem.allowed_roots.length ?? 0,
								)
							: __('None', 'agent-wordpress-terminal')}
					</dd>
				</div>
				<div>
					<dt>{__('Last indexed', 'agent-wordpress-terminal')}</dt>
					<dd>{status?.last_indexed_at || __('Never', 'agent-wordpress-terminal')}</dd>
				</div>
				<div>
					<dt>{__('Index state', 'agent-wordpress-terminal')}</dt>
					<dd>
						{status?.stale
							? __('Needs refresh', 'agent-wordpress-terminal')
							: __('Current', 'agent-wordpress-terminal')}
					</dd>
				</div>
			</dl>

			{status?.last_error ? <p className="awpt-knowledge-error">{status.last_error}</p> : null}

			<Button variant="secondary" onClick={() => void handleRebuild()} disabled={isRebuilding}>
				{isRebuilding
					? __('Rebuilding…', 'agent-wordpress-terminal')
					: __('Rebuild index', 'agent-wordpress-terminal')}
			</Button>

			<details className="awpt-knowledge-advanced">
				<summary>{__('Document sources & embeddings', 'agent-wordpress-terminal')}</summary>
				<p className="awpt-empty">
					{sprintf(
						/* translators: %s: file size label */
						__(
							'Open under wp-content: active theme, parent theme, uploads, and any extra folders you list. Text, markdown, CSS/JS, JSON, and PDF text are indexed. Default max file size: %s.',
							'agent-wordpress-terminal',
						),
						formatBytes(settings?.max_file_size ?? 2097152),
					)}
				</p>
				{settings?.allowed_roots && settings.allowed_roots.length > 0 ? (
					<p className="awpt-empty">
						{sprintf(
							/* translators: %s: comma-separated root paths */
							__('Currently open: %s', 'agent-wordpress-terminal'),
							settings.allowed_roots.join(', '),
						)}
					</p>
				) : null}
				<TextareaControl
					label={__('Extra document folders', 'agent-wordpress-terminal')}
					help={__(
						'One absolute path per line under wp-content (themes, plugins, custom docs — all open).',
						'agent-wordpress-terminal',
					)}
					value={rootsText}
					onChange={setRootsText}
					rows={4}
				/>
				<TextControl
					label={__('Max file size in bytes', 'agent-wordpress-terminal')}
					value={maxFileSize}
					onChange={setMaxFileSize}
					type="number"
				/>
				<ToggleControl
					label={__('Enable hybrid embeddings', 'agent-wordpress-terminal')}
					help={
						settings?.embeddings_available
							? sprintf(
									/* translators: %s: provider id */
									__(
										'Uses %s embeddings API when a key is configured; keyword search always remains available.',
										'agent-wordpress-terminal',
									),
									settings.embedding_provider || 'provider',
								)
							: __(
									'Add an OpenRouter or OpenAI API key in Agent Terminal settings to enable embeddings. Keyword search still works without it.',
									'agent-wordpress-terminal',
								)
					}
					checked={embeddingsEnabled}
					onChange={setEmbeddingsEnabled}
					disabled={!settings?.embeddings_available && !embeddingsEnabled}
				/>
				<TextControl
					label={__('Embedding model', 'agent-wordpress-terminal')}
					help={__(
						'OpenRouter-style model id (e.g. openai/text-embedding-3-small). Rebuild the index after changing.',
						'agent-wordpress-terminal',
					)}
					value={embeddingModel}
					onChange={setEmbeddingModel}
					disabled={!embeddingsEnabled}
				/>
				<Button variant="secondary" onClick={() => void handleSaveSettings()} disabled={isSaving}>
					{isSaving
						? __('Saving…', 'agent-wordpress-terminal')
						: __('Save & rebuild', 'agent-wordpress-terminal')}
				</Button>
			</details>
		</div>
	);
}
