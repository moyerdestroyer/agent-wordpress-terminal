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
			/** Shown above the hunk list (status changes, empty payload hints, etc.). */
			note?: string;
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
		payload?.operation === 'new_post' ||
		Boolean(payload?.domain_previewable)
	);
}

export function actionMetadata(payload?: ActionPayload): Array<{ label: string; value: string }> {
	if (!payload) {
		return [];
	}

	if (payload.domain_pack_id) {
		return [
			{
				label: __('Domain Pack', 'agent-wordpress-terminal'),
				value: payload.domain_pack_id,
			},
			{
				label: __('Operation', 'agent-wordpress-terminal'),
				value: titleCase(payload.operation ?? ''),
			},
			{
				label: __('Affected', 'agent-wordpress-terminal'),
				value: payload.affected ?? '',
			},
			{
				label: __('Rollback', 'agent-wordpress-terminal'),
				value: payload.domain_irreversible
					? __('Unavailable', 'agent-wordpress-terminal')
					: __('Available after apply', 'agent-wordpress-terminal'),
			},
		].filter((item) => item.value !== '');
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

	if (payload.operation === 'resource_change') {
		return [
			{
				label: __('Target', 'agent-wordpress-terminal'),
				value: [payload.resource_type, payload.resource_id ? `#${payload.resource_id}` : '']
					.filter(Boolean)
					.join(' '),
			},
			{
				label: __('Operation', 'agent-wordpress-terminal'),
				value: titleCase(payload.resource_operation ?? ''),
			},
			{
				label: __('Affected', 'agent-wordpress-terminal'),
				value: payload.affected ?? '',
			},
			{
				label: __('Fingerprint', 'agent-wordpress-terminal'),
				value: payload.resource_fingerprint
					? `${payload.resource_fingerprint.slice(0, 12)}...`
					: '',
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
			label: __('Design basis', 'agent-wordpress-terminal'),
			value: payload.composition_context
				? [
						payload.composition_context.theme_name,
						payload.composition_context.stylesheet
							? `(${payload.composition_context.stylesheet})`
							: '',
						payload.composition_context.pattern_name
							? `· ${payload.composition_context.pattern_name}`
							: '',
						payload.composition_context.fallback_reason
							? `· ${payload.composition_context.fallback_reason}`
							: '',
					]
						.filter(Boolean)
						.join(' ')
				: '',
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

	if (payload.operation === 'resource_change') {
		const original = payload.resource_original ?? {};
		const desired = payload.resource_data ?? {};
		const keys = Array.from(new Set([...Object.keys(original), ...Object.keys(desired)])).sort();

		return {
			kind: 'settings',
			label: [
				titleCase(payload.resource_operation ?? __('Change', 'agent-wordpress-terminal')),
				titleCase(payload.resource_type ?? __('Resource', 'agent-wordpress-terminal')),
			]
				.filter(Boolean)
				.join(' · '),
			rows: keys.map((key) => ({
				key,
				before: formatValue(original[key]),
				after: Object.hasOwn(desired, key) ? formatValue(desired[key]) : formatValue(original[key]),
			})),
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

	// content_update, template_update, and any other document-shaped ops.
	// Do not bake status into the text streams: asymmetric headers
	// ("Publish → Publish" vs "Publish") destroy line alignment, and minified
	// Gutenberg from the model already needs structural normalization in textDiff.
	const before = payload.original_post_content ?? '';
	const after = payload.post_content ?? '';
	const notes: string[] = [];

	if (
		payload.original_post_status &&
		payload.post_status &&
		payload.original_post_status !== payload.post_status
	) {
		notes.push(
			`${__('Status', 'agent-wordpress-terminal')}: ${titleCase(payload.original_post_status)} → ${titleCase(payload.post_status)}`,
		);
	}

	if (after === '' && before !== '' && payload.operation === 'content_update') {
		notes.push(
			__(
				'No proposed post_content was stored on this action — the diff only shows the baseline document. Ask the agent to restage a full content update.',
				'agent-wordpress-terminal',
			),
		);
	}

	return {
		kind: 'text',
		label:
			payload.operation === 'template_update'
				? __('Template', 'agent-wordpress-terminal')
				: payload.operation === 'navigation_update'
					? __('Navigation', 'agent-wordpress-terminal')
					: __('Content', 'agent-wordpress-terminal'),
		before,
		after,
		emptyBeforeLabel: __('(no previous content)', 'agent-wordpress-terminal'),
		emptyAfterLabel:
			after === '' ? __('(no proposed content)', 'agent-wordpress-terminal') : undefined,
		note: notes.length > 0 ? notes.join(' ') : undefined,
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
