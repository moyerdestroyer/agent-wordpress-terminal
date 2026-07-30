/**
 * Smoke tests for assets/lib/textDiff.ts (via esbuild-free dynamic checks of logic
 * duplicated here as pure JS for the PHP-free runner). Prefer importing the built
 * module when a JS test runner exists; until then this mirrors normalize + stats.
 */

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { diffLines } from 'diff';

const require = createRequire(import.meta.url);
// Use the TypeScript source through a minimal reimplementation matching textDiff.ts
// so we do not need a TS loader in CI.

function normalizeDiffText(value) {
	return value.replace(/\r\n/g, '\n').replace(/\r/g, '\n').replace(/\n+$/, '');
}

function normalizeBlockMarkupForDiff(value) {
	let text = normalizeDiffText(value);
	if (text === '') {
		return '';
	}
	text = text.replace(/([^\n])(<!--\s*\/?wp:)/g, '$1\n$2');
	text = text.replace(/(-->)(?!\n|$)/g, '$1\n');
	text = text.replace(/(-->)\n+(<!--)/g, '$1\n$2');
	text = text.replace(/\n{3,}/g, '\n\n');
	return text.replace(/\n+$/, '');
}

function countLineStats(before, after) {
	const left = normalizeBlockMarkupForDiff(before);
	const right = normalizeBlockMarkupForDiff(after);
	const parts = diffLines(left, right);
	let added = 0;
	let removed = 0;
	for (const part of parts) {
		if (part.value === '') continue;
		const raw = part.value.endsWith('\n') ? part.value.slice(0, -1) : part.value;
		const rows = raw.split('\n');
		for (const _ of rows) {
			if (part.added) added += 1;
			else if (part.removed) removed += 1;
		}
	}
	return { added, removed };
}

const pretty = `<!-- wp:paragraph -->
<p>Hello</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>World</p>
<!-- /wp:paragraph -->`;

const minified =
	'<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>World</p><!-- /wp:paragraph -->';

const stats = countLineStats(pretty, minified);
assert.equal(stats.added, 0, `minified vs pretty should not invent adds, got +${stats.added}`);
assert.equal(
	stats.removed,
	0,
	`minified vs pretty should not invent removals, got -${stats.removed}`,
);

const changedMinified =
	'<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>World changed</p><!-- /wp:paragraph -->';
const changed = countLineStats(pretty, changedMinified);
assert.ok(changed.added + changed.removed > 0, 'real text change should still show in the diff');
assert.ok(
	changed.added + changed.removed < 8,
	'localized change should stay small, not whole-document',
);

// Asymmetric status headers used to be baked into before/after — ensure pure content compares cleanly.
const withStatusChaosBefore = `Status: Publish → Publish\n\n${pretty}`;
const withStatusChaosAfter = `Status: Publish\n\n${minified}`;
const chaos = countLineStats(withStatusChaosBefore, withStatusChaosAfter);
// Content is equal after normalize; status lines still differ → small delta only
assert.ok(
	chaos.added <= 2 && chaos.removed <= 2,
	`status-only mismatch should stay tiny, got +${chaos.added}/-${chaos.removed}`,
);

console.log('text-diff.mjs: OK');
void require;
void dirname;
void join;
void fileURLToPath;
