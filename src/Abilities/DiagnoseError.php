<?php

/**
 * awpt/diagnose-error ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\SessionRepository;
use AWPT\Support\Diagnostics\ErrorLogReader;
use AWPT\Support\Diagnostics\ErrorPathAttributor;
use AWPT\Support\Diagnostics\RemediationHintBuilder;
use AWPT\Support\Diagnostics\SiteHealthReader;
use AWPT\Support\Diagnostics\UrlProbe;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Orchestrates error diagnosis from logs, attribution, plugins, site health, and URL probes.
 */
final class DiagnoseError implements AbilityInterface {
    private SessionRepository $sessions;

    public function __construct(?SessionRepository $sessions = null) {
        $this->sessions = $sessions ?? new SessionRepository();
    }

    /**
     * Register the ability.
     */
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/diagnose-error',
            'label' => __('Diagnose Error', 'agent-wordpress-terminal'),
            'description' => __(
                'Analyzes a PHP or JS error with log lines, suspects, Site Health context, and actionable remediation hints.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'error_text' => ['type' => 'string'],
                    'source' => ['type' => 'string'],
                    'attempted_action' => ['type' => 'string'],
                    'action_id' => ['type' => 'integer'],
                    'url' => ['type' => 'string'],
                    'kind' => ['type' => 'string'],
                ],
                'required' => ['session_id'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function can_read(array $input): bool {
        return current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $session_id = (int) ($input['session_id'] ?? 0);

        if (!$this->sessions->exists($session_id)) {
            return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'));
        }

        $error_text = sanitize_textarea_field((string) ($input['error_text'] ?? ''));
        $source = sanitize_text_field((string) ($input['source'] ?? ''));
        $attempted_action = sanitize_key((string) ($input['attempted_action'] ?? ''));
        $incident_kind = sanitize_key((string) ($input['kind'] ?? ''));
        $url = esc_url_raw((string) ($input['url'] ?? ''));

        if ('' === $error_text) {
            $log = new ErrorLogReader()->read(50);
            $error_text = implode("\n", $log['lines']);
        }

        $attributor = new ErrorPathAttributor();
        $attribution = $attributor->from_text($error_text);
        $site_health_reader = new SiteHealthReader();
        $site_health = $site_health_reader->summary();
        $site_health = is_wp_error($site_health) ? [] : $site_health;
        $site_health_tests = is_array($site_health['tests'] ?? null) ? $site_health['tests'] : [];
        $relevant_tests = $site_health_reader->correlate_tests($attribution['error_type'], $site_health_tests);

        $probe = null;

        if ('' !== $url) {
            $probe_result = new UrlProbe()->probe($url);

            if (!is_wp_error($probe_result)) {
                $probe = $probe_result;
            }
        }

        $environment = is_array($site_health['environment'] ?? null) ? $site_health['environment'] : [];
        $remediations = new RemediationHintBuilder()->build([
            'error_text' => $error_text,
            'error_type' => $attribution['error_type'],
            'suspects' => $attribution['suspects'],
            'evidence' => $attribution['evidence'],
            'attempted_action' => $attempted_action,
            'url' => $url,
            'incident_kind' => $incident_kind,
            'environment' => $environment,
            'relevant_tests' => $relevant_tests,
            'url_probe' => $probe,
        ]);

        $summary = $this->build_summary($attribution, $attempted_action);

        return [
            'summary' => $summary,
            'attempted_action' => $attempted_action,
            'source' => $source,
            'action_id' => (int) ($input['action_id'] ?? 0) > 0 ? (int) $input['action_id'] : null,
            'error_type' => $attribution['error_type'],
            'suspects' => $attribution['suspects'],
            'evidence' => $attribution['evidence'],
            'site_health' => [
                'environment' => $environment,
                'relevant_tests' => $relevant_tests,
            ],
            'url_probe' => $probe,
            'suggested_remediations' => $remediations,
        ];
    }

    /**
     * @param array{suspects: list<array<string, mixed>>, error_type: string|null, evidence: list<string>} $attribution
     */
    private function build_summary(array $attribution, string $attempted_action): string {
        $primary = $attribution['suspects'][0] ?? null;

        if (null === $primary) {
            return '' !== $attempted_action
                ? sprintf(
                    /* translators: %s: action name */
                    __('Error occurred while attempting %s.', 'agent-wordpress-terminal'),
                    $attempted_action,
                )
                : __(
                    'An error was detected but no plugin or theme suspect was identified.',
                    'agent-wordpress-terminal',
                );
        }

        return sprintf(
            /* translators: 1: error kind, 2: slug */
            __('Error likely originates from %1$s %2$s.', 'agent-wordpress-terminal'),
            (string) $primary['kind'],
            (string) $primary['slug'],
        );
    }
}
