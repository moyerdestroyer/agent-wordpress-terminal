/**
 * Improve briefs for focused pages (evaluate → act).
 *
 * Canonical strings live in PHP (`AWPT\Support\ImprovePagePrompt`) and are
 * localized onto `window.awptSettings` for the review bridge.
 */

const EVALUATE_MARKER = '[awpt:improve_evaluate]';

function fromSettings(
	key: 'improvePagePrompt' | 'improvePageEvaluatePrompt' | 'improvePageActPrompt',
): string {
	if (typeof window === 'undefined' || !window.awptSettings) {
		return '';
	}
	const value = window.awptSettings[key];
	return typeof value === 'string' ? value.trim() : '';
}

/** Legacy one-shot redesign brief. */
export function improvePagePrompt(): string {
	const from = fromSettings('improvePagePrompt');
	if (from !== '') {
		return from;
	}

	return (
		'Redesign this focused page using active-theme patterns.\n\n' +
		'Read the current page (and block paths/fingerprints when targeting a section). ' +
		'Prefer prepare-pattern-change → propose-pattern-replace for structural section swaps, ' +
		'or propose-pattern-insert for additions. Use surgical block edits for copy-only fixes. ' +
		'Map existing copy into the new structure where it fits. Placeholders are fine for empty slots. ' +
		'Do not invent facts or Media Library URLs. Prefer theme patterns when they fit; ' +
		'a full-document freehand rewrite is fine when no pattern fits.'
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
		'Evaluate this focused page and produce a short execution plan only.\n\n' +
		'You must NOT stage proposals, call propose-* tools, or rewrite the page in this turn.\n\n' +
		'1. Read the page (prefer awpt/read-block-tree for top_level_sections).\n' +
		'2. Summarize what to keep vs improve.\n' +
		'3. For each change, name least-destructive op: batch/attrs, prepare-replace, insert, or no change.\n' +
		'4. Flag preserve_by_default sections and carry-forward links/numbers.\n' +
		'5. End with a compact markdown plan. If nothing needs changing, say so clearly.'
	);
}

/** Step 2 act brief (without plan body). */
export function improvePageActPrompt(): string {
	const from = fromSettings('improvePageActPrompt');
	if (from !== '') {
		return from;
	}

	return (
		'Execute the plan below for this focused page.\n\n' +
		'Follow the plan’s least-destructive operations. Prefer prepare-pattern-change → ' +
		'propose-pattern-replace for section swaps, or prepare-pattern-change mode=insert / propose-pattern-insert for additions. ' +
		'Use surgical block edits for copy-only items. Map existing copy into pattern slots; use carry_forward for links and numbers. ' +
		'Placeholders are fine for empty slots. Do not invent facts or Media Library URLs. ' +
		'Full-document freehand is only appropriate when the plan says no pattern fits. ' +
		'Do not invent preparation_id values — call prepare first when using propose-pattern-replace/insert.'
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
