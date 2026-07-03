import { Button, TextareaControl, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	getKnowledgeSettings,
	getKnowledgeStatus,
	rebuildKnowledge,
	searchKnowledge,
	updateKnowledgeSettings,
} from '../api';
import type { KnowledgeSearchItem, KnowledgeSettings, KnowledgeStatus } from '../types';

function formatBytes(value: number): string {
	if (value >= 1048576) {
		return `${Math.round(value / 104857.6) / 10} MB`;
	}

	return `${Math.max(1, Math.round(value / 1024))} KB`;
}

function sourceId(item: KnowledgeSearchItem): string {
	if (item.source_post_id) {
		return `#${item.source_post_id}`;
	}

	return item.source_id.replace(/^file:/, 'file:').slice(0, 18);
}

export function KnowledgePanel(): JSX.Element {
	const [status, setStatus] = useState<KnowledgeStatus | null>(null);
	const [settings, setSettings] = useState<KnowledgeSettings | null>(null);
	const [rootsText, setRootsText] = useState('');
	const [maxFileSize, setMaxFileSize] = useState('2097152');
	const [query, setQuery] = useState('');
	const [results, setResults] = useState<KnowledgeSearchItem[]>([]);
	const [isLoading, setIsLoading] = useState(true);
	const [isRebuilding, setIsRebuilding] = useState(false);
	const [autoRebuildAttempted, setAutoRebuildAttempted] = useState(false);
	const [isSearching, setIsSearching] = useState(false);
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

	const handleSearch = async (): Promise<void> => {
		if (!query.trim()) {
			return;
		}

		setIsSearching(true);

		try {
			const response = await searchKnowledge(query.trim());
			setResults(response.items);
		} finally {
			setIsSearching(false);
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
			});
			setSettings(saved);
			setRootsText(saved.roots.join('\n'));
			setMaxFileSize(String(saved.max_file_size));
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
			<p className="awpt-empty">
				{__(
					'The agent uses Core Knowledge, guidelines, indexed site content, and allowed uploaded docs as read-only retrieval sources.',
					'agent-wordpress-terminal',
				)}
			</p>

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

			<h3 className="awpt-section-title" style={{ marginTop: 16 }}>
				{__('Search', 'agent-wordpress-terminal')}
			</h3>
			<TextControl
				label={__('Search Knowledge', 'agent-wordpress-terminal')}
				hideLabelFromVision
				value={query}
				onChange={setQuery}
				placeholder={__('Search guidelines, notes, docs…', 'agent-wordpress-terminal')}
				onKeyDown={(event) => {
					if (event.key === 'Enter') {
						void handleSearch();
					}
				}}
			/>
			<Button
				variant="secondary"
				onClick={() => void handleSearch()}
				disabled={isSearching || !query.trim()}
				style={{ marginTop: 8 }}
			>
				{isSearching
					? __('Searching…', 'agent-wordpress-terminal')
					: __('Search', 'agent-wordpress-terminal')}
			</Button>

			{results.length > 0 ? (
				<ul className="awpt-list awpt-knowledge-results">
					{results.map((item) => (
						<li key={item.id}>
							<div>
								{item.source_kind} {sourceId(item)}: {item.label}
							</div>
							<div className="awpt-list-meta">{item.excerpt}</div>
						</li>
					))}
				</ul>
			) : null}

			<details className="awpt-knowledge-advanced">
				<summary>{__('Advanced document sources', 'agent-wordpress-terminal')}</summary>
				<p className="awpt-empty">
					{sprintf(
						/* translators: %s: file size label */
						__(
							'Optional extra read-only document folders. Default max file size: %s. Folders must live under wp-content and cannot be plugin or theme directories.',
							'agent-wordpress-terminal',
						),
						formatBytes(settings?.max_file_size ?? 2097152),
					)}
				</p>
				<TextareaControl
					label={__('Additional document folders', 'agent-wordpress-terminal')}
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
				<Button variant="secondary" onClick={() => void handleSaveSettings()} disabled={isSaving}>
					{isSaving
						? __('Saving…', 'agent-wordpress-terminal')
						: __('Save document source settings', 'agent-wordpress-terminal')}
				</Button>
			</details>
		</div>
	);
}
