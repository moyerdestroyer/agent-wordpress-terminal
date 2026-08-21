/**
 * Improve briefs for focused pages (evaluate → act).
 *
 * Canonical strings live in PHP (`AWPT\Support\ImprovePagePrompt`) and are
 * localized onto `window.awptSettings` for the review bridge.
 */

const EVALUATE_MARKER = '[awpt:improve_evaluate]';
const ACT_MARKER = '[awpt:improve_act]';

function fromSettings(
	key:
		| 'improvePagePrompt'
		| 'improvePageEvaluatePrompt'
		| 'improvePageActPrompt'
		| 'improvePageReviewBrief',
): string {
	if (typeof window === 'undefined' || !window.awptSettings) {
		return '';
	}
	const value = window.awptSettings[key];
	return typeof value === 'string' ? value.trim() : '';
}

/** Legacy one-shot improve brief. */
export function improvePagePrompt(): string {
	const from = fromSettings('improvePagePrompt');
	if (from !== '') {
		return from;
	}

	return (
		'Improve this focused page.\n\n' +
		'Read the page. Keep what already works. Stage only changes that would actually help.'
	);
}

/** Step 1: plan only (must start with evaluate marker for runtime isolation). */
export function improvePageEvaluatePrompt(): string {
	const from = fromSettings('improvePageEvaluatePrompt');
	if (from !== '') {
		return from;
	}

	return (
		EVALUATE_MARKER +
		'\n' +
		'Improve this focused page.\n\n' +
		'Read the page, then write a short plan the next turn can execute. ' +
		'Do not stage changes in this turn. Keep what already works. If nothing should change, say so.'
	);
}

const FALLBACK_REVIEW_BRIEF = 'Improve this page.';

/** Review-queue context layered onto the canonical read-only evaluation brief. */
export function improvePageReviewEvaluatePrompt(
	postId: number,
	title: string,
	reviewerNotes = '',
): string {
	const notes = reviewerNotes.trim();
	const pageTitle = title.trim() || 'Untitled';
	const reviewBrief = fromSettings('improvePageReviewBrief') || FALLBACK_REVIEW_BRIEF;

	return (
		`${improvePageEvaluatePrompt()}\n\n## Review queue context\n` +
		`Focused post: #${postId}\nFocused title: ${pageTitle}\n\n${reviewBrief}` +
		(notes !== '' ? `\n\n## Reviewer request\n${notes}` : '')
	);
}

/** Step 2 act brief (without plan body). Must start with act marker for runtime isolation. */
export function improvePageActPrompt(): string {
	const from = fromSettings('improvePageActPrompt');
	if (from !== '') {
		return from;
	}

	return (
		ACT_MARKER +
		'\n' +
		'Execute the plan below for this focused page.\n\n' +
		'The plan is authoritative. Do not re-evaluate the page or restart open-ended discovery.\n\n' +
		'1. Trust the plan’s operations, paths, and preserve list. Do not re-discover the design system.\n' +
		'2. At most one targeted re-read if fingerprints are missing (read-block-tree or get-block).\n' +
		'3. Stage with propose-block-batch-update (kind set/remove/insert) or propose-pattern-replace/insert as the plan named; the server prepares section changes.\n' +
		'4. Map existing copy into slots, carry links/numbers forward, and replace required authoring placeholders before staging.\n' +
		'5. Full-document freehand only if the plan says no pattern fits.\n' +
		'6. Do not invent preparation_id values.'
	);
}

/** Full act user message including plan. Falls back to one-shot if plan empty. */
export function improvePageActMessage(plan: string): string {
	const trimmed = (plan || '').trim();
	if (trimmed === '') {
		return improvePagePrompt();
	}
	return `${improvePageActPrompt()}\n\n## Plan\n${trimmed}`;
}

/** Act brief with full plan essay plus one unit fence (mirrors PHP act_message_for_unit). */
export function improvePageActMessageForUnit(plan: string, unitJson: string): string {
	const trimmedPlan = (plan || '').trim();
	const trimmedUnit = (unitJson || '').trim();
	const parts = [
		improvePageActPrompt(),
		'',
		'Execute only this unit. Do not stage later units or reopen page-wide diagnosis.',
	];
	if (trimmedPlan !== '') {
		parts.push('', '## Plan', trimmedPlan);
	}
	if (trimmedUnit !== '') {
		parts.push('', `## Unit\n\`\`\`awpt-unit\n${trimmedUnit}\n\`\`\``);
	}
	return parts.join('\n');
}
