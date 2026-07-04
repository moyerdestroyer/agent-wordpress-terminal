import { __ } from '@wordpress/i18n';
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

export function titleCase(value: string): string {
	return value.replace(/[_-]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function canPreviewAction(payload?: ActionPayload): boolean {
	return payload?.operation === 'content_update' || payload?.operation === 'new_post';
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

	return [
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
	].filter((item) => item.value !== '');
}

export function actionDiff(payload?: ActionPayload): { before: string; after: string } {
	if (!payload) {
		return { before: '', after: '' };
	}

	if (payload.operation === 'site_settings_update') {
		return {
			before: formatValue(payload.original_settings),
			after: formatValue(payload.settings_changes),
		};
	}

	if (payload.operation === 'theme_switch') {
		return {
			before: [payload.current_theme, payload.current_stylesheet].filter(Boolean).join(' / '),
			after: [payload.theme_name, payload.stylesheet].filter(Boolean).join(' / '),
		};
	}

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
			content ? formatValue(content) : '',
		].filter(Boolean);

		return sections.join('\n\n');
	};

	return {
		before: buildCompareContent(
			payload.original_post_content,
			payload.original_post_status,
			payload.original_post_meta,
		),
		after: buildCompareContent(payload.post_content, payload.post_status, payload.post_meta),
	};
}
