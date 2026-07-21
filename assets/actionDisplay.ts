import { __ } from '@wordpress/i18n';
import { topLevelBlockOutline } from './lib/textDiff';
import type { ActionPayload } from './types';

export function formatValue(value: unknown): string {
	if (typeof value === 'string') {
		return value;
	}

	if (value === null || value === undefined) {
		return '';
	}

	return JSON.stringify(value, null, 2);
}

export type ActionDiffModel =
	| {
			kind: 'text';
			label: string;
			before: string;
			after: string;
			emptyBeforeLabel?: string;
			emptyAfterLabel?: string;
	  }
	| {
			kind: 'create';
			label: string;
			postTitle: string;
			postType: string;
			patternName?: string;
			outline: string[];
			attachmentIds: number[];
			content: string;
	  }
	| {
			kind: 'settings';
			label: string;
			rows: Array<{ key: string; before: string; after: string }>;
	  }
	| {
			kind: 'attrs';
			label: string;
			blockPath: string;
			blockName: string;
			rows: Array<{ key: string; before: string; after: string }>;
			note?: string;
	  }
	| {
			kind: 'state';
			label: string;
			before: string;
			after: string;
	  }
	| {
			kind: 'unavailable';
			label: string;
			reason: string;
	  };

export function titleCase(value: string): string {
	return value.replace(/[_-]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function canPreviewAction(payload?: ActionPayload): boolean {
	return (
		payload?.operation === 'content_update' ||
		payload?.operation === 'block_attrs_update' ||
		payload?.operation === 'block_insert' ||
		payload?.operation === 'block_remove' ||
		payload?.operation === 'new_post'
	);
}

export function actionMetadata(payload?: ActionPayload): Array<{ label: string; value: string }> {
	if (!payload) {
		return [];
	}

	if (payload.operation === 'site_settings_update') {
		return [
			{
				label: __('Target', 'agent-wordpress-terminal'),
				value: __('Site settings', 'agent-wordpress-terminal'),
			},
			{
				label: __('Settings', 'agent-wordpress-terminal'),
				value: Object.keys(payload.settings_changes ?? {}).join(', '),
			},
		].filter((item) => item.value !== '');
	}

	if (payload.operation === 'theme_switch') {
		return [
			{
				label: __('Target', 'agent-wordpress-terminal'),
				value: __('Active theme', 'agent-wordpress-terminal'),
			},
			{
				label: __('Current', 'agent-wordpress-terminal'),
				value: payload.current_theme ?? payload.current_stylesheet ?? '',
			},
			{
				label: __('New', 'agent-wordpress-terminal'),
				value: payload.theme_name ?? payload.stylesheet ?? '',
			},
		].filter((item) => item.value !== '');
	}

	if (payload.operation === 'plugin_deactivate') {
		return [
			{
				label: __('Target', 'agent-wordpress-terminal'),
				value: __('Plugin', 'agent-wordpress-terminal'),
			},
			{
				label: __('Plugin', 'agent-wordpress-terminal'),
				value: payload.plugin_name ?? payload.plugin_slug ?? payload.plugin_file ?? '',
			},
			{
				label: __('File', 'agent-wordpress-terminal'),
				value: payload.plugin_file ?? '',
			},
		].filter((item) => item.value !== '');
	}

	const postTitle = payload.original_post_title || payload.post_title || '';
	const postType = payload.post_type
		? titleCase(payload.post_type)
		: __('Post/Page', 'agent-wordpress-terminal');
	const postReference = [
		postType,
		payload.post_id ? `#${payload.post_id}` : '',
		postTitle ? `- ${postTitle}` : '',
	]
		.filter(Boolean)
		.join(' ');

	const originalStatus = payload.original_post_status ?? '';
	const nextStatus = payload.post_status ?? '';
	const statusValue =
		originalStatus && nextStatus && originalStatus !== nextStatus
			? `${titleCase(originalStatus)} → ${titleCase(nextStatus)}`
			: nextStatus
				? titleCase(nextStatus)
				: '';

	const metadata = [
		{
			label: __('Target', 'agent-wordpress-terminal'),
			value: postReference,
		},
		{
			label: __('Status', 'agent-wordpress-terminal'),
			value: statusValue,
		},
		{
			label: __('Meta', 'agent-wordpress-terminal'),
			value: payload.post_meta ? Object.keys(payload.post_meta).join(', ') : '',
		},
		{
			label: __('Blocks / area', 'agent-wordpress-terminal'),
			value: payload.affected ?? '',
		},
	];

	if (
		payload.operation === 'block_attrs_update' ||
		payload.operation === 'block_insert' ||
		payload.operation === 'block_remove'
	) {
		metadata.push(
			{
				label: __('Block', 'agent-wordpress-terminal'),
				value: [payload.inserted_path || payload.block_path, payload.block_name, payload.position]
					.filter(Boolean)
					.join(' · '),
			},
			{
				label: __('Attributes', 'agent-wordpress-terminal'),
				value: payload.attrs ? Object.keys(payload.attrs).join(', ') : '',
			},
			{
				label: __('Fingerprint', 'agent-wordpress-terminal'),
				value: payload.expected_fingerprint
					? `${payload.expected_fingerprint.slice(0, 12)}...`
					: '',
			},
		);
	}

	return metadata.filter((item) => item.value !== '');
}

/**
 * Structured review model for proposed actions (card Diff + preview Compare).
 */
export function buildActionDiffModel(payload?: ActionPayload): ActionDiffModel {
	if (!payload?.operation) {
		return {
			kind: 'unavailable',
			label: __('Diff', 'agent-wordpress-terminal'),
			reason: __('No staged change payload is available to compare.', 'agent-wordpress-terminal'),
		};
	}

	if (payload.operation === 'site_settings_update') {
		const original = payload.original_settings ?? {};
		const next = payload.settings_changes ?? {};
		const keys = Array.from(new Set([...Object.keys(original), ...Object.keys(next)])).sort();

		return {
			kind: 'settings',
			label: __('Site settings', 'agent-wordpress-terminal'),
			rows: keys.map((key) => ({
				key,
				before: formatValue(original[key]),
				after: formatValue(next[key]),
			})),
		};
	}

	if (payload.operation === 'theme_switch') {
		return {
			kind: 'state',
			label: __('Theme', 'agent-wordpress-terminal'),
			before: [payload.current_theme, payload.current_stylesheet].filter(Boolean).join(' / '),
			after: [payload.theme_name, payload.stylesheet].filter(Boolean).join(' / '),
		};
	}

	if (payload.operation === 'plugin_deactivate') {
		return {
			kind: 'state',
			label: payload.plugin_name ?? payload.plugin_slug ?? __('Plugin', 'agent-wordpress-terminal'),
			before: __('Active', 'agent-wordpress-terminal'),
			after: __('Deactivated', 'agent-wordpress-terminal'),
		};
	}

	if (payload.operation === 'custom_css_update') {
		return {
			kind: 'text',
			label: __('Additional CSS', 'agent-wordpress-terminal'),
			before: payload.original_css ?? '',
			after: payload.css ?? '',
			emptyBeforeLabel: __('(no previous Additional CSS)', 'agent-wordpress-terminal'),
		};
	}

	if (payload.operation === 'new_post') {
		const content = payload.post_content ?? '';
		return {
			kind: 'create',
			label: __('New draft', 'agent-wordpress-terminal'),
			postTitle: payload.post_title ?? '',
			postType: payload.post_type ?? 'post',
			patternName: payload.pattern_name,
			outline: topLevelBlockOutline(content),
			attachmentIds: payload.required_attachment_ids ?? [],
			content,
		};
	}

	if (payload.operation === 'block_attrs_update') {
		const before = payload.original_post_content ?? '';
		const after = payload.post_content ?? '';
		// Prefer a full-document text diff when originals were stored; attribute
		// maps alone omit previous values today.
		if (before !== '' || after !== '') {
			return {
				kind: 'text',
				label: [
					__('Block attributes', 'agent-wordpress-terminal'),
					payload.block_path,
					payload.block_name,
				]
					.filter(Boolean)
					.join(' · '),
				before,
				after,
				emptyBeforeLabel: __('(no previous content)', 'agent-wordpress-terminal'),
			};
		}

		const attrs = payload.attrs ?? {};
		return {
			kind: 'attrs',
			label: __('Block attributes', 'agent-wordpress-terminal'),
			blockPath: payload.block_path ?? '',
			blockName: payload.block_name ?? '',
			rows: Object.keys(attrs).map((key) => ({
				key,
				before: __('(previous value not stored)', 'agent-wordpress-terminal'),
				after: formatValue(attrs[key]),
			})),
		};
	}

	if (
		payload.operation === 'block_insert' ||
		payload.operation === 'block_remove' ||
		payload.operation === 'pattern_insert'
	) {
		const snippet =
			payload.operation === 'block_remove'
				? ''
				: formatValue(payload.block ?? payload.blocks ?? payload.pattern_name ?? '');
		const before = payload.original_post_content ?? '';
		const after = payload.post_content ?? '';

		if (before !== '' || after !== '') {
			return {
				kind: 'text',
				label:
					payload.operation === 'block_insert'
						? __('Block insert', 'agent-wordpress-terminal')
						: payload.operation === 'block_remove'
							? __('Block remove', 'agent-wordpress-terminal')
							: __('Pattern insert', 'agent-wordpress-terminal'),
				before,
				after,
				emptyBeforeLabel: __('(no previous content)', 'agent-wordpress-terminal'),
			};
		}

		return {
			kind: 'text',
			label: __('Block change', 'agent-wordpress-terminal'),
			before: '',
			after: snippet,
			emptyBeforeLabel: __('(no previous content)', 'agent-wordpress-terminal'),
		};
	}

	if (
		payload.operation === 'global_styles_update' ||
		payload.operation === 'global_styles_create'
	) {
		return {
			kind: 'text',
			label: __('Global styles', 'agent-wordpress-terminal'),
			before: payload.original_post_content ?? '',
			after: payload.post_content ?? '',
			emptyBeforeLabel: __('(no previous global styles revision)', 'agent-wordpress-terminal'),
		};
	}

	// content_update, template_update, and any other document-shaped ops
	const before = payload.original_post_content ?? '';
	const after = payload.post_content ?? '';
	const statusBits = [
		payload.original_post_status && payload.post_status
			? `${__('Status', 'agent-wordpress-terminal')}: ${titleCase(payload.original_post_status)} → ${titleCase(payload.post_status)}`
			: '',
	].filter(Boolean);

	return {
		kind: 'text',
		label:
			payload.operation === 'template_update'
				? __('Template', 'agent-wordpress-terminal')
				: __('Content', 'agent-wordpress-terminal'),
		before: [statusBits.join('\n'), before].filter(Boolean).join('\n\n'),
		after: [
			payload.post_status
				? `${__('Status', 'agent-wordpress-terminal')}: ${titleCase(payload.post_status)}`
				: '',
			after,
		]
			.filter(Boolean)
			.join('\n\n'),
		emptyBeforeLabel: __('(no previous content)', 'agent-wordpress-terminal'),
	};
}

/** @deprecated Prefer buildActionDiffModel + ActionDiffView */
export function actionDiff(payload?: ActionPayload): { before: string; after: string } {
	const model = buildActionDiffModel(payload);

	if (model.kind === 'text') {
		return { before: model.before, after: model.after };
	}

	if (model.kind === 'create') {
		return { before: '', after: model.content };
	}

	if (model.kind === 'settings') {
		return {
			before: model.rows.map((row) => `${row.key}: ${row.before}`).join('\n'),
			after: model.rows.map((row) => `${row.key}: ${row.after}`).join('\n'),
		};
	}

	if (model.kind === 'attrs') {
		return {
			before: model.rows.map((row) => `${row.key}: ${row.before}`).join('\n'),
			after: model.rows.map((row) => `${row.key}: ${row.after}`).join('\n'),
		};
	}

	if (model.kind === 'state') {
		return { before: model.before, after: model.after };
	}

	return { before: '', after: model.reason };
}
