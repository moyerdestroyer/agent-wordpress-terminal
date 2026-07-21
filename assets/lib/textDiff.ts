import { diffLines } from 'diff';

export type DiffLineKind = 'context' | 'added' | 'removed';

export interface DiffLine {
	kind: DiffLineKind;
	text: string;
	oldLine?: number;
	newLine?: number;
}

export interface DiffHunk {
	/** Consecutive visible lines (context/added/removed). */
	lines: DiffLine[];
	/** Unchanged lines collapsed before this hunk (0 if none). */
	collapsedBefore: number;
}

const DEFAULT_CONTEXT = 3;
const COLLAPSE_MIN = 8;

/** Normalize line endings for stable hunk generation. */
export function normalizeDiffText(value: string): string {
	return value.replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\n+$/, '');
}

function buildDiffLinesRaw(before: string, after: string): DiffLine[] {
	const left = normalizeDiffText(before);
	const right = normalizeDiffText(after);

	if (left === '' && right === '') {
		return [];
	}

	if (left === right) {
		return left.split('\n').map((text, index) => ({
			kind: 'context' as const,
			text,
			oldLine: index + 1,
			newLine: index + 1,
		}));
	}

	const parts = diffLines(left, right);
	const lines: DiffLine[] = [];
	let oldLine = 1;
	let newLine = 1;

	for (const part of parts) {
		if (part.value === '') {
			continue;
		}

		const raw = part.value.endsWith('\n') ? part.value.slice(0, -1) : part.value;
		const rows = raw.split('\n');

		for (const text of rows) {
			if (part.added) {
				lines.push({ kind: 'added', text, newLine });
				newLine += 1;
			} else if (part.removed) {
				lines.push({ kind: 'removed', text, oldLine });
				oldLine += 1;
			} else {
				lines.push({ kind: 'context', text, oldLine, newLine });
				oldLine += 1;
				newLine += 1;
			}
		}
	}

	return lines;
}

/**
 * Build line-level hunks, collapsing long unchanged runs while keeping
 * `context` lines of padding around each change.
 */
export function buildDiffHunks(
	before: string,
	after: string,
	context = DEFAULT_CONTEXT,
): DiffHunk[] {
	const lines = buildDiffLinesRaw(before, after);

	if (lines.length === 0) {
		return [];
	}

	const changeIndexes: number[] = [];
	lines.forEach((line, index) => {
		if (line.kind !== 'context') {
			changeIndexes.push(index);
		}
	});

	if (changeIndexes.length === 0) {
		return [{ lines, collapsedBefore: 0 }];
	}

	const keep = new Set<number>();
	for (const index of changeIndexes) {
		const start = Math.max(0, index - context);
		const end = Math.min(lines.length - 1, index + context);
		for (let i = start; i <= end; i++) {
			keep.add(i);
		}
	}

	const hunks: DiffHunk[] = [];
	let current: DiffLine[] = [];
	let collapsedBefore = 0;
	let pendingCollapse = 0;

	const flush = (): void => {
		if (current.length === 0) {
			return;
		}

		hunks.push({ lines: current, collapsedBefore });
		current = [];
		collapsedBefore = 0;
	};

	for (let i = 0; i < lines.length; i++) {
		if (keep.has(i)) {
			if (pendingCollapse > 0) {
				if (pendingCollapse >= COLLAPSE_MIN) {
					flush();
					collapsedBefore = pendingCollapse;
				} else {
					for (let j = i - pendingCollapse; j < i; j++) {
						current.push(lines[j]);
					}
				}
				pendingCollapse = 0;
			}

			current.push(lines[i]);
		} else {
			pendingCollapse += 1;
		}
	}

	flush();

	return hunks;
}

export function countDiffStats(hunks: DiffHunk[]): { added: number; removed: number } {
	let added = 0;
	let removed = 0;

	for (const hunk of hunks) {
		for (const line of hunk.lines) {
			if (line.kind === 'added') {
				added += 1;
			} else if (line.kind === 'removed') {
				removed += 1;
			}
		}
	}

	return { added, removed };
}

/** Rough top-level block names from serialized Gutenberg markup. */
export function topLevelBlockOutline(content: string, limit = 24): string[] {
	const names: string[] = [];
	const re = /<!--\s*wp:([a-z0-9-_]+\/[a-z0-9-_]+|[a-z0-9-_]+)/gi;
	let match: RegExpExecArray | null = re.exec(content);

	while (match !== null && names.length < limit) {
		const name = match[1] ?? '';
		if (name !== '') {
			names.push(name);
		}
		match = re.exec(content);
	}

	return names;
}
