import { __, sprintf } from '@wordpress/i18n';
import { titleCase } from './actionDisplay';
import { buildDiffHunks, countDiffStats } from './lib/textDiff';
import type { ActionPayload } from './types';

export type ReviewChangeSummary = {
	eyebrow: string;
	lines: string[];
	hint?: string;
};

function formatAttrValue(value: unknown): string {
	if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
		return String(value);
	}
	if (value === null || value === undefined) {
		return '—';
	}
	return JSON.stringify(value);
}

function summarizeBatchChanges(changes: NonNullable<ActionPayload['batch_changes']>): string[] {
	const kindCounts = new Map<string, number>();
	const headingLevels = new Map<string, number>();
	const attrKeys = new Map<string, Map<string, number>>();
	let headingUpdates = 0;

	for (const change of changes) {
		const kind = change.kind || 'update_attrs';
		kindCounts.set(kind, (kindCounts.get(kind) ?? 0) + 1);

		if (kind !== 'update_attrs' && kind !== 'update_block') {
			continue;
		}

		const isHeading = (change.block_name ?? '').includes('heading');
		const attrs = change.attrs ?? {};
		if (isHeading && Object.hasOwn(attrs, 'level')) {
			headingUpdates += 1;
			const level = formatAttrValue(attrs.level);
			headingLevels.set(level, (headingLevels.get(level) ?? 0) + 1);
			continue;
		}

		for (const [key, value] of Object.entries(attrs)) {
			const byValue = attrKeys.get(key) ?? new Map<string, number>();
			const rendered = formatAttrValue(value);
			byValue.set(rendered, (byValue.get(rendered) ?? 0) + 1);
			attrKeys.set(key, byValue);
		}
	}

	const lines: string[] = [];

	if (headingUpdates > 0) {
		const levelParts = [...headingLevels.entries()]
			.sort((a, b) => Number(a[0]) - Number(b[0]))
			.map(([level, count]) =>
				sprintf(
					/* translators: 1: heading level number, 2: count of headings */
					__('H%1$s × %2$d', 'agent-wordpress-terminal'),
					level,
					count,
				),
			);
		lines.push(
			sprintf(
				/* translators: 1: number of heading updates, 2: level breakdown */
				__('Heading levels updated on %1$d block(s): %2$s', 'agent-wordpress-terminal'),
				headingUpdates,
				levelParts.join(', '),
			),
		);
	}

	for (const [kind, count] of kindCounts.entries()) {
		if (kind === 'update_attrs' && headingUpdates === count) {
			continue;
		}
		if (kind === 'update_attrs' && headingUpdates > 0) {
			const other = count - headingUpdates;
			if (other > 0) {
				lines.push(
					sprintf(
						/* translators: %d: count of non-heading attribute updates */
						__('%d other attribute update(s)', 'agent-wordpress-terminal'),
						other,
					),
				);
			}
			continue;
		}
		lines.push(
			sprintf(
				/* translators: 1: change kind, 2: count */
				__('%1$s × %2$d', 'agent-wordpress-terminal'),
				kind === 'replace_inner_html'
					? __('Saved HTML replacement', 'agent-wordpress-terminal')
					: titleCase(kind),
				count,
			),
		);
	}

	for (const [key, byValue] of attrKeys.entries()) {
		const parts = [...byValue.entries()].map(([value, count]) => `${key} → ${value} (×${count})`);
		lines.push(parts.join(', '));
	}

	return lines;
}

/**
 * Short, human summary for the Review assistant — never a full markup diff.
 */
export function buildReviewChangeSummary(payload?: ActionPayload): ReviewChangeSummary {
	if (!payload?.operation) {
		return {
			eyebrow: __('Change', 'agent-wordpress-terminal'),
			lines: [__('No staged change details are available.', 'agent-wordpress-terminal')],
		};
	}

	const eyebrow = payload.affected ? String(payload.affected) : titleCase(payload.operation);

	if (payload.batch_changes && payload.batch_changes.length > 0) {
		return {
			eyebrow,
			lines: summarizeBatchChanges(payload.batch_changes),
			hint: __(
				'Open the staged preview to visually confirm before applying.',
				'agent-wordpress-terminal',
			),
		};
	}

	if (payload.operation === 'block_attrs_update' && payload.attrs) {
		const rows = Object.entries(payload.attrs).map(
			([key, value]) => `${key} → ${formatAttrValue(value)}`,
		);
		return {
			eyebrow: [
				__('Block attributes', 'agent-wordpress-terminal'),
				payload.block_path,
				payload.block_name,
			]
				.filter(Boolean)
				.join(' · '),
			lines: rows.length > 0 ? rows : [__('Attribute update staged.', 'agent-wordpress-terminal')],
			hint: __(
				'Open the staged preview to visually confirm before applying.',
				'agent-wordpress-terminal',
			),
		};
	}

	if (
		payload.operation === 'block_insert' ||
		payload.operation === 'block_remove' ||
		payload.operation === 'pattern_insert' ||
		payload.operation === 'pattern_replace'
	) {
		const label =
			payload.operation === 'block_insert'
				? __('Block insert', 'agent-wordpress-terminal')
				: payload.operation === 'block_remove'
					? __('Block remove', 'agent-wordpress-terminal')
					: payload.operation === 'pattern_replace'
						? __('Pattern replace', 'agent-wordpress-terminal')
						: __('Pattern insert', 'agent-wordpress-terminal');
		const detail =
			(payload.operation === 'pattern_insert' || payload.operation === 'pattern_replace') &&
			payload.pattern_name
				? payload.pattern_name
				: payload.block_path
					? `path ${payload.block_path}`
					: '';
		return {
			eyebrow: label,
			lines: [detail || __('Structural page change staged.', 'agent-wordpress-terminal')],
			hint: __(
				'Open the staged preview to visually confirm before applying.',
				'agent-wordpress-terminal',
			),
		};
	}

	const before = payload.original_post_content ?? '';
	const after = payload.post_content ?? '';
	if (before !== '' || after !== '') {
		const stats = countDiffStats(buildDiffHunks(before, after));
		return {
			eyebrow,
			lines: [
				sprintf(
					/* translators: 1: added line count, 2: removed line count */
					__('Content update (+%1$d / −%2$d lines in markup)', 'agent-wordpress-terminal'),
					stats.added,
					stats.removed,
				),
			],
			hint: __(
				'Markup diff is hidden here — use Open staged preview to review the page.',
				'agent-wordpress-terminal',
			),
		};
	}

	return {
		eyebrow,
		lines: [__('Staged change ready to apply.', 'agent-wordpress-terminal')],
		hint: __(
			'Open the staged preview to visually confirm before applying.',
			'agent-wordpress-terminal',
		),
	};
}
