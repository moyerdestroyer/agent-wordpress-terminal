<?php

/**
 * Shared agent instructions for failure diagnosis.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Single source of truth for diagnosis prompt text.
 */
final class DiagnosisInstructions {
    public static function system_prompt_line(): string {
        return implode(' ', [
            'When a tool call, apply action, or client error produces a failure:',
            'call awpt/diagnose-error with the incident context;',
            'lead with what was attempted, what failed, and cited evidence;',
            'walk through suggested_remediations as prose next steps (gather data first, then lighter fixes, then heavier options);',
            'use awpt/probe-url for preview or frontend issues when logs are insufficient;',
            'call awpt/read-site-health with scope full when failures may be environmental (timeouts, loopback/REST, memory, HTTPS, updates);',
            'stage awpt/propose-plugin-deactivate only when suggested_remediations contains a deactivate_plugin hint with confidence unambiguous — never as a first resort.',
        ]);
    }

    public static function incident_response_guidance(): string {
        return implode("\n", [
            'Explain what was attempted, what failed, and which component is implicated using the structured diagnosis seed.',
            'Present suggested_remediations as ordered next steps in plain language before proposing any staged action.',
            'Call awpt/diagnose-error again only if you need more evidence.',
            'Use awpt/probe-url when frontend or preview context is involved and logs are thin.',
            'Call awpt/read-site-health with scope full when the failure may be environmental.',
            'Stage awpt/propose-plugin-deactivate only when suggested_remediations includes deactivate_plugin with confidence unambiguous.',
        ]);
    }
}
