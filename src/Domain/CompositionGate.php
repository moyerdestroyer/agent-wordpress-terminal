<?php

/**
 * Single composition validation entry for propose and apply paths.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Support\PostCompositionValidator;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Thin façade over baseline structure checks and Domain Pack validation.
 *
 * Abilities and appliers should not construct DomainValidationService and
 * PostCompositionValidator independently for the same content gate.
 */
final class CompositionGate {
    private DomainValidationService $domain;

    private PostCompositionValidator $structure;

    public function __construct(
        ?DomainValidationService $domain = null,
        ?PostCompositionValidator $structure = null,
    ) {
        $this->domain = $domain ?? new DomainValidationService();
        $this->structure = $structure ?? new PostCompositionValidator();
    }

    /**
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
        return $this->domain->evaluate($content, $context, $apply_safe_fixes);
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    public function blocking_error(array $findings): ?\WP_Error {
        return $this->domain->blocking_error($findings);
    }

    public function ruleset_hash(): string {
        return $this->domain->ruleset_hash();
    }

    /**
     * Extended structure / media / pattern-prefix checks used by greenfield compose.
     *
     * @param list<int>    $required_attachment_ids
     * @param list<string> $required_links
     * @param array{pattern_name?: string, minimum_library_images?: int, minimum_visuals?: int, featured_image_id?: int} $requirements
     * @return list<array{code: string, message: string}>
     */
    public function diagnose_structure(
        string $content,
        array $required_attachment_ids = [],
        array $required_links = [],
        string $required_pattern_prefix = '',
        array $requirements = [],
    ): array {
        return $this->structure->diagnose(
            $content,
            $required_attachment_ids,
            $required_links,
            $required_pattern_prefix,
            $requirements,
        );
    }

    public function structure_validator(): PostCompositionValidator {
        return $this->structure;
    }

    public function domain_service(): DomainValidationService {
        return $this->domain;
    }
}
