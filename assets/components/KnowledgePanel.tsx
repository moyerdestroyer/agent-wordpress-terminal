import {
	Button,
	SearchControl,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	getDomainPacks,
	getKnowledgeSettings,
	getKnowledgeStatus,
	processKnowledge,
	rebuildKnowledge,
	updateDomainPacks,
	updateKnowledgeSettings,
} from '../api';
import type { DomainPacksResponse, KnowledgeSettings, KnowledgeStatus } from '../types';

function formatBytes(value: number): string {
	if (value >= 1048576) {
		return `${Math.round(value / 104857.6) / 10} MB`;
	}

	return `${Math.max(1, Math.round(value / 1024))} KB`;
}

function withTimeout<T>(promise: Promise<T>, milliseconds: number): Promise<T> {
	return Promise.race([
		promise,
		new Promise<T>((_resolve, reject) => {
			window.setTimeout(
				() => reject(new Error('Knowledge status request timed out.')),
				milliseconds,
			);
		}),
	]);
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
	const [domainPacks, setDomainPacks] = useState<DomainPacksResponse | null>(null);
	const [rootsText, setRootsText] = useState('');
	const [maxFileSize, setMaxFileSize] = useState('2097152');
	const [embeddingsEnabled, setEmbeddingsEnabled] = useState(true);
	const [embeddingModel, setEmbeddingModel] = useState('text-embedding-3-small');
	const [patternSearch, setPatternSearch] = useState('');
	const [isLoading, setIsLoading] = useState(true);
	const [loadError, setLoadError] = useState<string | null>(null);
	const [isRebuilding, setIsRebuilding] = useState(false);
	const [isSaving, setIsSaving] = useState(false);
	const isIndexing =
		isRebuilding ||
		status?.progress.state === 'discovering' ||
		status?.progress.state === 'indexing';
	const progress = status?.progress;
	const activePackCount = domainPacks?.packs.filter((pack) => pack.enabled).length ?? 0;
	const visiblePatterns = (domainPacks?.patterns ?? []).filter((pattern) => {
		const query = patternSearch.trim().toLowerCase();
		const pack = domainPacks?.packs.find((item) => item.id === pattern.pack_id);

		if (!pack?.enabled) {
			return false;
		}

		return (
			query === '' ||
			[pattern.name, pattern.title, pattern.role, pattern.summary, ...pattern.intents]
				.join(' ')
				.toLowerCase()
				.includes(query)
		);
	});

	const refresh = async (): Promise<KnowledgeStatus> => {
		const [nextStatus, nextSettings, nextDomainPacks] = await Promise.all([
			getKnowledgeStatus(),
			getKnowledgeSettings(),
			getDomainPacks(),
		]);
		setStatus(nextStatus);
		setSettings(nextSettings);
		setRootsText(nextSettings.roots.join('\n'));
		setMaxFileSize(String(nextSettings.max_file_size));
		setEmbeddingsEnabled(Boolean(nextSettings.embeddings_enabled));
		setEmbeddingModel(nextSettings.embedding_model || 'text-embedding-3-small');
		setDomainPacks(nextDomainPacks);

		return nextStatus;
	};

	useEffect(() => {
		const boot = async (): Promise<void> => {
			try {
				await withTimeout(refresh(), 10_000);

				// Rebuilds are explicit: opening the terminal must not start a long,
				// synchronous database job against a large theme or uploads folder.
			} catch {
				setLoadError(
					__(
						'Knowledge status is taking longer than expected. Reload to try again.',
						'agent-wordpress-terminal',
					),
				);
			} finally {
				setIsLoading(false);
			}
		};

		void boot();
	}, []);

	useEffect(() => {
		if (!isIndexing) {
			return;
		}

		let processing = false;
		const poll = (): void => {
			if (processing) {
				return;
			}

			processing = true;
			const runId = status?.progress.run_id ?? 0;
			const request = runId > 0 ? processKnowledge(runId) : getKnowledgeStatus();
			void request
				.then((response) => {
					setStatus('status' in response ? response.status : response);
				})
				.catch(() => {})
				.finally(() => {
					processing = false;
				});
		};
		poll();
		const interval = window.setInterval(poll, 1500);

		return () => window.clearInterval(interval);
	}, [isIndexing, status?.progress.run_id]);

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
				embedding_model: embeddingModel.trim() || 'text-embedding-3-small',
			});
			setSettings(saved);
			setRootsText(saved.roots.join('\n'));
			setMaxFileSize(String(saved.max_file_size));
			setEmbeddingsEnabled(Boolean(saved.embeddings_enabled));
			setEmbeddingModel(saved.embedding_model || 'text-embedding-3-small');
			await handleRebuild();
		} finally {
			setIsSaving(false);
		}
	};

	const handlePackToggle = async (packId: string, enabled: boolean): Promise<void> => {
		const disabled = (domainPacks?.packs ?? [])
			.filter((pack) => (!pack.enabled && pack.id !== packId) || (pack.id === packId && !enabled))
			.map((pack) => pack.id);
		setDomainPacks(await updateDomainPacks(disabled));
	};

	if (isLoading) {
		return <p className="awpt-empty">{__('Loading Knowledge…', 'agent-wordpress-terminal')}</p>;
	}

	if (loadError) {
		return (
			<div className="awpt-knowledge">
				<h3 className="awpt-section-title">{__('Knowledge', 'agent-wordpress-terminal')}</h3>
				<p className="awpt-knowledge-error" role="alert">
					{loadError}
				</p>
				<Button variant="secondary" onClick={() => window.location.reload()}>
					{__('Reload terminal', 'agent-wordpress-terminal')}
				</Button>
			</div>
		);
	}

	return (
		<div className="awpt-knowledge">
			<h3 className="awpt-section-title">{__('Knowledge', 'agent-wordpress-terminal')}</h3>
			{isIndexing ? (
				<div className="awpt-knowledge-progress" role="status" aria-live="polite">
					<div>
						<strong>{__('Refreshing Knowledge', 'agent-wordpress-terminal')}</strong>
						<span>
							{progress && progress.total_sources > 0
								? sprintf(
										/* translators: 1: processed source count, 2: total source count */
										__('%1$d of %2$d sources', 'agent-wordpress-terminal'),
										progress.processed_sources,
										progress.total_sources,
									)
								: __('Preparing sources…', 'agent-wordpress-terminal')}
						</span>
					</div>
					<progress
						value={progress?.total_sources ? progress.processed_sources : undefined}
						max={progress?.total_sources || undefined}
					>
						{progress?.total_sources
							? `${progress.processed_sources}/${progress.total_sources}`
							: __('Working', 'agent-wordpress-terminal')}
					</progress>
					<p>
						{sprintf(
							/* translators: 1: indexed source count, 2: unchanged source count, 3: indexed chunk count */
							__(
								'%1$d updated · %2$d unchanged · %3$d chunks prepared',
								'agent-wordpress-terminal',
							),
							progress?.indexed_sources ?? 0,
							progress?.unchanged_sources ?? 0,
							progress?.indexed_chunks ?? 0,
						)}
					</p>
				</div>
			) : null}
			<ul className="awpt-knowledge-sources">
				{knowledgeSourceRows(status).map((source) => (
					<li key={source.key} className={source.available ? 'is-available' : 'is-unavailable'}>
						<span>{source.label}</span>
						<strong>{source.state}</strong>
					</li>
				))}
			</ul>

			{(domainPacks?.packs.length ?? 0) > 0 ? (
				<details className="awpt-domain-packs">
					<summary>
						<span className="awpt-domain-packs__title">
							{__('Theme expertise', 'agent-wordpress-terminal')}
						</span>
						<span className="awpt-domain-packs__count">
							{sprintf(
								/* translators: %d: number of enabled Domain Packs */
								_n(
									'%d active pack',
									'%d active packs',
									activePackCount,
									'agent-wordpress-terminal',
								),
								activePackCount,
							)}
						</span>
					</summary>
					<div className="awpt-domain-packs__body">
						<p className="awpt-empty awpt-domain-packs__intro">
							{__(
								'Domain Packs provide versioned pattern, design, and editorial guidance. They never bypass review or permissions.',
								'agent-wordpress-terminal',
							)}
						</p>
						{domainPacks?.packs.map((pack) => (
							<div className="awpt-domain-pack" key={pack.id}>
								<ToggleControl
									label={`${pack.label} ${pack.version}`}
									help={sprintf(
										/* translators: 1: source, 2: guideline count, 3: rule count */
										__(
											'%1$s theme · %2$d guidance modules · %3$d declarative rules',
											'agent-wordpress-terminal',
										),
										pack.source,
										pack.guidance_count,
										domainPacks.health.find((health) => health.pack_id === pack.id)?.rule_count ??
											0,
									)}
									checked={pack.enabled}
									onChange={(enabled) => void handlePackToggle(pack.id, enabled)}
								/>
								{domainPacks.health.find((health) => health.pack_id === pack.id) ? (
									<p className="awpt-empty">
										{(() => {
											const health = domainPacks.health.find((item) => item.pack_id === pack.id);
											if (!health) {
												return '';
											}
											const coverage = health.pattern_coverage;
											const detail = sprintf(
												/* translators: 1: health status, 2: enriched pattern count, 3: registered pattern count */
												__('%1$s · %2$d/%3$d patterns enriched', 'agent-wordpress-terminal'),
												health.status,
												coverage.enriched,
												coverage.registered,
											);
											const issue = health.issues.find(
												(item) => item.severity === 'error' || item.severity === 'warning',
											);
											return issue ? `${detail} · ${issue.message}` : detail;
										})()}
									</p>
								) : null}
							</div>
						))}
						<details className="awpt-pattern-catalog">
							<summary>
								<span>{__('Pattern catalog', 'agent-wordpress-terminal')}</span>
								<strong>
									{sprintf(
										/* translators: %d: number of patterns in enabled Domain Packs */
										__('%d patterns', 'agent-wordpress-terminal'),
										(domainPacks?.patterns ?? []).filter((pattern) =>
											domainPacks?.packs.some(
												(pack) => pack.id === pattern.pack_id && pack.enabled,
											),
										).length,
									)}
								</strong>
							</summary>
							<div className="awpt-pattern-catalog__body">
								<SearchControl
									label={__('Search theme patterns', 'agent-wordpress-terminal')}
									value={patternSearch}
									onChange={setPatternSearch}
									placeholder={__('Search by purpose, role, or name', 'agent-wordpress-terminal')}
								/>
								{visiblePatterns.length > 0 ? (
									<ul className="awpt-pattern-catalog__list">
										{visiblePatterns.map((pattern) => (
											<li key={pattern.name} className="awpt-pattern-card">
												{pattern.preview_url ? (
													<a
														className="awpt-pattern-card__preview"
														href={pattern.preview_url}
														target="_blank"
														rel="noreferrer"
														aria-label={sprintf(
															/* translators: %s: pattern title */
															__('Open %s preview', 'agent-wordpress-terminal'),
															pattern.title,
														)}
													>
														<img
															src={pattern.preview_url}
															alt={pattern.preview_alt}
															loading="lazy"
														/>
													</a>
												) : (
													<div className="awpt-pattern-card__fallback" aria-hidden="true">
														<span />
														<span />
														<span />
													</div>
												)}
												<div className="awpt-pattern-card__content">
													<div className="awpt-pattern-card__heading">
														<strong>{pattern.title}</strong>
														<span>{pattern.role.replaceAll('-', ' ')}</span>
													</div>
													<p>{pattern.summary || pattern.description}</p>
													<code>{pattern.name}</code>
													<div className="awpt-pattern-card__facts">
														<span>
															{sprintf(
																/* translators: %d: block count */
																__('%d blocks', 'agent-wordpress-terminal'),
																pattern.block_count,
															)}
														</span>
														<span>
															{sprintf(
																/* translators: %d: editable slot count */
																__('%d slots', 'agent-wordpress-terminal'),
																pattern.slot_count,
															)}
														</span>
														{pattern.dynamic_content ? (
															<span>{__('Dynamic', 'agent-wordpress-terminal')}</span>
														) : null}
													</div>
												</div>
											</li>
										))}
									</ul>
								) : (
									<p className="awpt-empty">
										{__('No enabled theme patterns match this search.', 'agent-wordpress-terminal')}
									</p>
								)}
							</div>
						</details>
						<p className="awpt-empty awpt-domain-packs__footer">
							{domainPacks?.knowledge_backend
								? sprintf(
										/* translators: %s: WordPress Knowledge backend */
										__('Site overrides can use %s.', 'agent-wordpress-terminal'),
										domainPacks.knowledge_backend,
									)
								: __(
										'Theme defaults are active. Site-level guideline overrides require WordPress Knowledge or Guidelines.',
										'agent-wordpress-terminal',
									)}
						</p>
					</div>
				</details>
			) : null}

			<dl className="awpt-knowledge-status">
				<div>
					<dt>{__('Backend', 'agent-wordpress-terminal')}</dt>
					<dd>
						{status?.repository.label ?? __('Unknown', 'agent-wordpress-terminal')} ·{' '}
						{status?.vector_backend.backend ?? 'local'}
					</dd>
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
									__('%d folders (theme + custom)', 'agent-wordpress-terminal'),
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
			{status?.embedding.last_error ? (
				<p className="awpt-knowledge-error">{status.embedding.last_error}</p>
			) : null}
			{status?.recent_failures?.map((failure) => (
				<p className="awpt-knowledge-error" key={`${failure.source_kind}:${failure.source_id}`}>
					<strong>{failure.source_id}</strong>: {failure.error_text}
				</p>
			))}

			<Button variant="secondary" onClick={() => void handleRebuild()} disabled={isIndexing}>
				{isIndexing
					? __('Rebuilding…', 'agent-wordpress-terminal')
					: __('Rebuild index', 'agent-wordpress-terminal')}
			</Button>

			<details className="awpt-knowledge-advanced">
				<summary>{__('Document sources & embeddings', 'agent-wordpress-terminal')}</summary>
				<p className="awpt-empty">
					{sprintf(
						/* translators: %s: file size label */
						__(
							'Indexes authored site content, Media Library documents, theme.json, templates, patterns, docs, and canonical source styles. Compiled CSS, dependency trees, and automatic uploads traversal are excluded. Default max file size: %s.',
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
						'One explicit path per line under wp-content. Media Library files do not need to be added here. Dependencies and generated files are excluded.',
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
					help={
						settings?.embedding_provider === 'openrouter'
							? __(
									'OpenRouter model id (for example, openai/text-embedding-3-small). Changing it schedules a compatible embedding refresh without reusing old vectors.',
									'agent-wordpress-terminal',
								)
							: __(
									'Provider model id (for OpenAI, text-embedding-3-small). Changing it schedules a compatible embedding refresh without reusing old vectors.',
									'agent-wordpress-terminal',
								)
					}
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
