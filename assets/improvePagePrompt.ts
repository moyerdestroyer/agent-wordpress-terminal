/**
 * Improve briefs for focused pages (evaluate → act).
 *
 * Canonical strings live in PHP (`AWPT\Support\ImprovePagePrompt`) and are
 * localized onto `window.awptSettings` for the review bridge.
 */

const EVALUATE_MARKER = '[awpt:improve_evaluate]';
const ACT_MARKER = '[awpt:improve_act]';

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
		'Map existing copy into the new structure and replace required authoring placeholders with credible copy. ' +
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
		'5. End with a compact markdown plan, grouping incompatible work into coherent phases. If nothing needs changing, say so clearly.'
	);
}

/** Review-queue context layered onto the canonical read-only evaluation brief. */
export function improvePageReviewEvaluatePrompt(
	postId: number,
	title: string,
	reviewerNotes = '',
): string {
	const notes = reviewerNotes.trim();
	const pageTitle = title.trim() || 'Untitled';
	const reviewBrief =
		'You are preparing the focused WordPress page for final editorial review. ' +
		'Assess the whole page, not just the first visible defect. Check page-title and heading hierarchy, ' +
		'section structure, repeated or redundant markup, readability, accessibility, and fit with active-theme patterns. ' +
		'Plan a polished, coherent result while preserving accurate facts, useful copy, links, numbers, and dynamic sections. ' +
		'Only plan page-scoped, reversible content or block changes that are safe to apply automatically. ' +
		'Include all intended work in a compact, executable plan, grouped into coherent phases when operations cannot safely share one proposal.';

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
		'1. Trust the plan’s operations, paths, and preserve list.\n' +
		'2. At most one targeted re-read if fingerprints are missing (read-block-tree or get-block).\n' +
		'3. Stage batch/attrs and prepare-replace/insert as the plan named; one coherent proposal for the first incomplete phase(s).\n' +
		'For a block batch, use only one non-insertion mutation per path. Use one update_block with attrs and content when the same block needs both; never split those edits across update_attrs and replace_text.\n' +
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
