<?php

/**
 * Runs active domain validation without granting callbacks write authority.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Agent\AgentFeedback;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

final class DomainValidationService {
    private DomainPackRegistry $registry;

    public function __construct(?DomainPackRegistry $registry = null) {
        $this->registry = $registry ?? DomainPackRegistry::instance();
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public function validate(string $content, array $context = []): array {
        return $this->evaluate($content, $context)['findings'];
    }

    /**
     * Run the complete AWPT baseline, declarative, and extension pipeline.
     *
     * @param array<string, mixed> $context
     * @return array{
     *     content: string,
     *     findings: list<array<string, mixed>>,
     *     fixes: list<array<string, mixed>>,
     *     ruleset_hash: string,
     *     agent_feedback: array<string, mixed>
     * }
     */
    public function evaluate(string $content, array $context = [], bool $apply_safe_fixes = false): array {
        $fixed = $apply_safe_fixes ? new SafeCompositionFixer()->fix($content) : ['content' => $content, 'fixes' => []];
        $validated_content = (string) $fixed['content'];
        $findings = new BaselineCompositionValidator()->validate($validated_content);
        array_push($findings, ...new DeclarativeRuleEngine(
            new DomainRuleRepository($this->registry),
            new PatternMetadataCatalog($this->registry),
        )->validate($validated_content, $context));
        array_push($findings, ...$this->extension_findings($validated_content, $context));
        $findings = $this->unique_findings($findings);
        $feedback = AgentFeedback::validation($findings, $fixed['fixes']);

        return [
            'content' => $validated_content,
            'findings' => $findings,
            'fixes' => $fixed['fixes'],
            'ruleset_hash' => $this->ruleset_hash(),
            'agent_feedback' => $feedback,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private function extension_findings(string $content, array $context): array {
        $findings = [];

        foreach ($this->registry->active() as $pack) {
            foreach ($this->registry->validators((string) $pack['id']) as $validator) {
                $callback = $validator['callback'] ?? null;

                if (!is_callable($callback)) {
                    continue;
                }

                $result = $callback($content, $context, $pack);

                if (!is_array($result)) {
                    continue;
                }

                foreach ($result as $finding) {
                    if (!is_array($finding)) {
                        continue;
                    }

                    $normalized = $this->normalize_finding(ArrayKey::string_map($finding), (string) $pack['id']);

                    if (null !== $normalized) {
                        $findings[] = $normalized;
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    public function blocking_error(array $findings): ?\WP_Error {
        $errors = array_values(array_filter(
            $findings,
            static fn(array $finding): bool => 'error' === (string) ($finding['severity'] ?? ''),
        ));

        if ([] === $errors) {
            return null;
        }

        return new \WP_Error(
            'awpt_domain_validation_failed',
            __('The active composition rules rejected this proposal.', 'agent-wordpress-terminal'),
            [
                'status' => 409,
                'validation_findings' => $findings,
                'recovery' => __('Resolve every error and stage the proposal again.', 'agent-wordpress-terminal'),
                'ruleset_hash' => $this->ruleset_hash(),
                'agent_feedback' => AgentFeedback::validation($findings),
            ],
        );
    }

    public function ruleset_hash(): string {
        $extension_ids = [];

        foreach ($this->registry->active() as $pack) {
            foreach ($this->registry->validators((string) $pack['id']) as $validator) {
                $extension_ids[] = (string) $pack['id'] . ':' . (string) ($validator['extension_id'] ?? '');
            }
        }

        sort($extension_ids);

        return hash('sha256', new DomainRuleRepository($this->registry)->hash() . '|' . implode('|', $extension_ids));
    }

    /**
     * @param array<string, mixed> $finding
     * @return array<string, mixed>|null
     */
    private function normalize_finding(array $finding, string $pack_id): ?array {
        $severity = sanitize_key((string) ($finding['severity'] ?? 'warning'));
        $code = sanitize_key((string) ($finding['code'] ?? ''));
        $message = sanitize_textarea_field((string) ($finding['message'] ?? ''));

        if (!in_array($severity, ['error', 'warning', 'info'], true) || '' === $code || '' === $message) {
            return null;
        }

        return [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'rule_id' => sanitize_key((string) ($finding['rule_id'] ?? $code)),
            'block_path' => sanitize_text_field((string) ($finding['block_path'] ?? '')),
            'source' => sanitize_text_field((string) ($finding['source'] ?? $pack_id)),
            'suggestion' => sanitize_textarea_field((string) ($finding['suggestion'] ?? '')),
            'pack_id' => sanitize_key($pack_id),
            'expected' => $this->scalar($finding['expected'] ?? ''),
            'actual' => $this->scalar($finding['actual'] ?? ''),
            'docs' => sanitize_text_field((string) ($finding['docs'] ?? '')),
        ];
    }

    private function scalar(mixed $value): string|int|float|bool {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) ? $value : '';
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return list<array<string, mixed>>
     */
    private function unique_findings(array $findings): array {
        $unique = [];

        foreach ($findings as $finding) {
            $normalized = $this->normalize_finding($finding, sanitize_key((string) ($finding['pack_id'] ?? '')));

            if (null === $normalized) {
                continue;
            }

            $key = implode(':', [
                (string) $normalized['pack_id'],
                (string) $normalized['code'],
                (string) $normalized['block_path'],
            ]);
            $unique[$key] = $normalized;
        }

        return array_values($unique);
    }
}
