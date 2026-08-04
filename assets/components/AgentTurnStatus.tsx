import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatTimingStrip, type PhaseTimings } from '../lib/turnTiming';
import type { ChatProgress } from '../types';
import './AgentTurnStatus.css';

interface AgentTurnStatusProps {
	progress?: ChatProgress | null;
	className?: string;
}

function useTurnTiming(phase: string): string {
	const [now, setNow] = useState(() => Date.now());
	const turnStartedAt = useRef(Date.now());
	const phaseStartedAt = useRef(Date.now());
	const activePhase = useRef(phase || 'starting');
	const closedPhaseMs = useRef<PhaseTimings>({});

	useEffect(() => {
		const timer = window.setInterval(() => setNow(Date.now()), 100);
		return () => window.clearInterval(timer);
	}, []);

	useEffect(() => {
		if (!phase || activePhase.current === phase) return;

		const changedAt = Date.now();
		const previous = activePhase.current;
		closedPhaseMs.current = {
			...closedPhaseMs.current,
			[previous]: (closedPhaseMs.current[previous] ?? 0) + (changedAt - phaseStartedAt.current),
		};
		activePhase.current = phase;
		phaseStartedAt.current = changedAt;
	}, [phase]);

	const totalMs = Math.max(0, now - turnStartedAt.current);
	const phaseMs = Math.max(0, now - phaseStartedAt.current);
	return formatTimingStrip(
		closedPhaseMs.current,
		activePhase.current || phase || 'starting',
		phaseMs,
		totalMs,
	);
}

/** Shared, evidence-based status for an active AWPT turn. */
export function AgentTurnStatus({ progress, className = '' }: AgentTurnStatusProps): JSX.Element {
	const phase = progress?.phase || 'starting';
	const label =
		progress?.label ||
		(phase === 'tools'
			? __('Running tools', 'agent-wordpress-terminal')
			: __('Working', 'agent-wordpress-terminal'));
	const detail = progress?.detail ?? '';
	const hasTotal = (progress?.total ?? 0) > 0;
	const completed = Math.min(progress?.completed ?? 0, progress?.total ?? 0);
	const percentage = hasTotal ? Math.max(4, (completed / (progress?.total ?? 1)) * 100) : 0;
	const timingStrip = useTurnTiming(phase);

	return (
		<div
			className={`awpt-agent-status ${className}`.trim()}
			role="status"
			aria-live="polite"
			aria-busy="true"
		>
			<div className="awpt-turn-status">
				<div className="awpt-turn-status__primary">
					<strong>{__('Agent', 'agent-wordpress-terminal')}:</strong>
					<span className="awpt-turn-status__label">{label}</span>
					{detail ? <span className="awpt-turn-status__detail">{detail}</span> : null}
				</div>
				{timingStrip ? <div className="awpt-turn-status__timing">{timingStrip}</div> : null}
				{hasTotal ? (
					<div
						className="awpt-turn-status__track is-determinate"
						role="progressbar"
						aria-label={label}
						aria-valuemin={0}
						aria-valuemax={progress?.total}
						aria-valuenow={completed}
					>
						<span style={{ transform: `scaleX(${percentage / 100})` }} />
					</div>
				) : (
					<div
						className="awpt-turn-status__track"
						role="progressbar"
						aria-label={label}
						aria-valuetext={label}
					>
						<span />
					</div>
				)}
			</div>
		</div>
	);
}
