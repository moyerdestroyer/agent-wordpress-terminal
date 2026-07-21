/** Format elapsed milliseconds for the agent turn status line. */
export function formatElapsed(ms: number): string {
	const value = Math.max(0, Math.round(ms));

	if (value < 1000) {
		return `${value}ms`;
	}

	if (value < 10_000) {
		return `${(value / 1000).toFixed(1)}s`;
	}

	return `${Math.round(value / 1000)}s`;
}

/** Short phase keys shown in the mono timing strip. */
export function phaseTimingLabel(phase: string): string {
	switch (phase) {
		case 'starting':
		case 'pending':
			return 'start';
		case 'planning':
			return 'planning';
		case 'tools':
			return 'tools';
		case 'composing':
			return 'composing';
		case 'preview':
			return 'preview';
		case 'complete':
			return 'done';
		case 'failed':
			return 'failed';
		default:
			return phase || 'working';
	}
}

export type PhaseTimings = Record<string, number>;

/**
 * Build a stable timing strip from closed phase buckets plus the active phase.
 * Example: "planning 1.1s · tools 840ms · total 3.4s"
 */
export function formatTimingStrip(
	phaseMs: PhaseTimings,
	activePhase: string,
	activePhaseElapsedMs: number,
	totalMs: number,
): string {
	const parts: string[] = [];
	const order = ['starting', 'pending', 'planning', 'tools', 'composing', 'preview'];
	const seen = new Set<string>();

	for (const phase of order) {
		let ms = phaseMs[phase] ?? 0;

		if (phase === activePhase) {
			ms += activePhaseElapsedMs;
		}

		if (ms <= 0) {
			continue;
		}

		seen.add(phase);
		parts.push(`${phaseTimingLabel(phase)} ${formatElapsed(ms)}`);
	}

	if (activePhase && !seen.has(activePhase) && activePhaseElapsedMs > 0) {
		parts.push(`${phaseTimingLabel(activePhase)} ${formatElapsed(activePhaseElapsedMs)}`);
	}

	parts.push(`total ${formatElapsed(totalMs)}`);

	return parts.join(' · ');
}
