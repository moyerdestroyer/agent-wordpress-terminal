import { Button } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { type ActionDiffModel, buildActionDiffModel, titleCase } from '../actionDisplay';
import { buildDiffHunks, countDiffStats, type DiffHunk, type DiffLine } from '../lib/textDiff';
import type { ActionPayload } from '../types';

type DiffLayout = 'unified' | 'split';

interface ActionDiffViewProps {
	payload?: ActionPayload;
	/** Compact card mode uses a shorter viewport than the preview drawer. */
	compact?: boolean;
}

function DiffLineRow({ line, layout }: { line: DiffLine; layout: DiffLayout }): JSX.Element {
	const prefix = line.kind === 'added' ? '+' : line.kind === 'removed' ? '-' : ' ';
	const oldNo = line.oldLine?.toString() ?? '';
	const newNo = line.newLine?.toString() ?? '';

	if (layout === 'split') {
		return (
			<div className={`awpt-diff-line awpt-diff-line--${line.kind} awpt-diff-line--split`}>
				<span className="awpt-diff-gutter">{line.kind === 'added' ? '' : oldNo}</span>
				<span className="awpt-diff-gutter">{line.kind === 'removed' ? '' : newNo}</span>
				<span className="awpt-diff-prefix">{prefix}</span>
				<span className="awpt-diff-text">{line.text === '' ? ' ' : line.text}</span>
			</div>
		);
	}

	return (
		<div className={`awpt-diff-line awpt-diff-line--${line.kind}`}>
			<span className="awpt-diff-gutter">{oldNo}</span>
			<span className="awpt-diff-gutter">{newNo}</span>
			<span className="awpt-diff-prefix">{prefix}</span>
			<span className="awpt-diff-text">{line.text === '' ? ' ' : line.text}</span>
		</div>
	);
}

function TextHunksView({
	before,
	after,
	layout,
	emptyBeforeLabel,
	emptyAfterLabel,
}: {
	before: string;
	after: string;
	layout: DiffLayout;
	emptyBeforeLabel?: string;
	emptyAfterLabel?: string;
}): JSX.Element {
	const hunks = useMemo(() => buildDiffHunks(before, after), [before, after]);
	const stats = useMemo(() => countDiffStats(hunks), [hunks]);

	if (before === '' && after === '') {
		return <p className="awpt-empty">{__('Nothing to compare.', 'agent-wordpress-terminal')}</p>;
	}

	if (before === after) {
		return (
			<p className="awpt-empty">
				{__('No textual differences between before and after.', 'agent-wordpress-terminal')}
			</p>
		);
	}

	return (
		<div className="awpt-diff-hunks">
			<div className="awpt-diff-stats">
				<span className="awpt-diff-stats__added">{`+${stats.added}`}</span>
				<span className="awpt-diff-stats__removed">{`−${stats.removed}`}</span>
				{before === '' && emptyBeforeLabel ? (
					<span className="awpt-diff-stats__note">{emptyBeforeLabel}</span>
				) : null}
				{after === '' && emptyAfterLabel ? (
					<span className="awpt-diff-stats__note">{emptyAfterLabel}</span>
				) : null}
			</div>
			{hunks.map((hunk) => {
				const hunkKey = hunkKeyFor(hunk);
				return (
					<div key={hunkKey} className="awpt-diff-hunk">
						{hunk.collapsedBefore > 0 ? (
							<div className="awpt-diff-collapse">{sprintfUnchanged(hunk.collapsedBefore)}</div>
						) : null}
						{hunk.lines.map((line) => (
							<DiffLineRow key={lineKeyFor(line)} line={line} layout={layout} />
						))}
					</div>
				);
			})}
		</div>
	);
}

function sprintfUnchanged(count: number): string {
	return __('%d unchanged lines', 'agent-wordpress-terminal').replace('%d', String(count));
}

function hunkKeyFor(hunk: DiffHunk): string {
	const first = hunk.lines[0];
	const last = hunk.lines[hunk.lines.length - 1];
	return [
		hunk.collapsedBefore,
		first?.kind,
		first?.oldLine ?? '',
		first?.newLine ?? '',
		last?.kind,
		last?.oldLine ?? '',
		last?.newLine ?? '',
		hunk.lines.length,
	].join(':');
}

function lineKeyFor(line: DiffLine): string {
	return [line.kind, line.oldLine ?? '', line.newLine ?? '', line.text].join('\u0001');
}

function keyedOutline(names: string[]): Array<{ key: string; name: string }> {
	const seen = new Map<string, number>();

	return names.map((name) => {
		const count = (seen.get(name) ?? 0) + 1;
		seen.set(name, count);
		return { key: `${name}#${count}`, name };
	});
}

function SettingsTable({
	rows,
}: {
	rows: Array<{ key: string; before: string; after: string }>;
}): JSX.Element {
	if (rows.length === 0) {
		return <p className="awpt-empty">{__('No settings changes.', 'agent-wordpress-terminal')}</p>;
	}

	return (
		<table className="awpt-diff-table">
			<thead>
				<tr>
					<th>{__('Setting', 'agent-wordpress-terminal')}</th>
					<th>{__('Before', 'agent-wordpress-terminal')}</th>
					<th>{__('After', 'agent-wordpress-terminal')}</th>
				</tr>
			</thead>
			<tbody>
				{rows.map((row) => (
					<tr key={row.key} className={row.before === row.after ? '' : 'awpt-diff-table__changed'}>
						<td>
							<code>{row.key}</code>
						</td>
						<td className="awpt-diff-table__before">{row.before || '—'}</td>
						<td className="awpt-diff-table__after">{row.after || '—'}</td>
					</tr>
				))}
			</tbody>
		</table>
	);
}

function CreateSummary({
	model,
}: {
	model: Extract<ActionDiffModel, { kind: 'create' }>;
}): JSX.Element {
	const [showMarkup, setShowMarkup] = useState(false);
	const hunks: DiffHunk[] = useMemo(
		() => (showMarkup ? buildDiffHunks('', model.content) : []),
		[model.content, showMarkup],
	);

	return (
		<div className="awpt-diff-create">
			<p className="awpt-diff-create__banner">
				{__(
					'New draft — there is no previous document to diff against.',
					'agent-wordpress-terminal',
				)}
			</p>
			<dl className="awpt-diff-create__meta">
				<div>
					<dt>{__('Title', 'agent-wordpress-terminal')}</dt>
					<dd>{model.postTitle || __('(untitled)', 'agent-wordpress-terminal')}</dd>
				</div>
				<div>
					<dt>{__('Type', 'agent-wordpress-terminal')}</dt>
					<dd>{titleCase(model.postType)}</dd>
				</div>
				{model.patternName ? (
					<div>
						<dt>{__('Pattern', 'agent-wordpress-terminal')}</dt>
						<dd>
							<code>{model.patternName}</code>
						</dd>
					</div>
				) : null}
				{model.attachmentIds.length > 0 ? (
					<div>
						<dt>{__('Media', 'agent-wordpress-terminal')}</dt>
						<dd>{model.attachmentIds.map((id) => `#${id}`).join(', ')}</dd>
					</div>
				) : null}
			</dl>
			{model.outline.length > 0 ? (
				<div className="awpt-diff-create__outline">
					<strong>{__('Block outline', 'agent-wordpress-terminal')}</strong>
					<ol>
						{keyedOutline(model.outline).map((item) => (
							<li key={item.key}>
								<code>{item.name}</code>
							</li>
						))}
					</ol>
				</div>
			) : null}
			<div className="awpt-diff-create__actions">
				<Button variant="secondary" onClick={() => setShowMarkup((value) => !value)}>
					{showMarkup
						? __('Hide full markup', 'agent-wordpress-terminal')
						: __('Show full markup as added', 'agent-wordpress-terminal')}
				</Button>
			</div>
			{showMarkup ? (
				<div className="awpt-diff-hunks">
					<div className="awpt-diff-stats">
						<span className="awpt-diff-stats__added">{`+${countDiffStats(hunks).added}`}</span>
					</div>
					{hunks.map((hunk) => (
						<div key={hunkKeyFor(hunk)} className="awpt-diff-hunk">
							{hunk.lines.map((line) => (
								<DiffLineRow
									key={lineKeyFor({ ...line, kind: 'added' })}
									line={{ ...line, kind: 'added' }}
									layout="unified"
								/>
							))}
						</div>
					))}
				</div>
			) : null}
		</div>
	);
}

function ModelBody({ model, layout }: { model: ActionDiffModel; layout: DiffLayout }): JSX.Element {
	switch (model.kind) {
		case 'text':
			return (
				<TextHunksView
					before={model.before}
					after={model.after}
					layout={layout}
					emptyBeforeLabel={model.emptyBeforeLabel}
					emptyAfterLabel={model.emptyAfterLabel}
				/>
			);
		case 'create':
			return <CreateSummary model={model} />;
		case 'settings':
			return <SettingsTable rows={model.rows} />;
		case 'attrs':
			return (
				<div>
					<p className="awpt-diff-attrs-path">
						<code>{[model.blockPath, model.blockName].filter(Boolean).join(' · ')}</code>
					</p>
					{model.note ? <p className="awpt-diff-stats__note">{model.note}</p> : null}
					<SettingsTable rows={model.rows} />
				</div>
			);
		case 'state':
			return (
				<table className="awpt-diff-table">
					<tbody>
						<tr className="awpt-diff-table__changed">
							<td>
								<strong>{model.label}</strong>
							</td>
							<td className="awpt-diff-table__before">{model.before || '—'}</td>
							<td className="awpt-diff-table__after">{model.after || '—'}</td>
						</tr>
					</tbody>
				</table>
			);
		case 'unavailable':
			return <p className="awpt-empty">{model.reason}</p>;
	}
}

export function ActionDiffView({ payload, compact = false }: ActionDiffViewProps): JSX.Element {
	const model = useMemo(() => buildActionDiffModel(payload), [payload]);
	const [layout, setLayout] = useState<DiffLayout>('unified');
	const showLayoutToggle = model.kind === 'text';

	return (
		<div className={`awpt-diff-view${compact ? ' awpt-diff-view--compact' : ''}`}>
			<div className="awpt-diff-view__header">
				<strong className="awpt-diff-view__label">{model.label}</strong>
				{showLayoutToggle ? (
					<div className="awpt-diff-view__layout">
						<Button
							variant={layout === 'unified' ? 'primary' : 'secondary'}
							onClick={() => setLayout('unified')}
							size="small"
						>
							{__('Unified', 'agent-wordpress-terminal')}
						</Button>
						<Button
							variant={layout === 'split' ? 'primary' : 'secondary'}
							onClick={() => setLayout('split')}
							size="small"
						>
							{__('Split', 'agent-wordpress-terminal')}
						</Button>
					</div>
				) : null}
			</div>
			<div className="awpt-diff-view__body">
				<ModelBody model={model} layout={layout} />
			</div>
		</div>
	);
}
