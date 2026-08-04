<?php

/**
 * Public Domain Pack registration functions.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Domain\DomainPackRegistry;

if (!function_exists('awpt_register_domain_validator')) {
    /** @param array<string, mixed> $args */
    function awpt_register_domain_validator(string $pack_id, string $validator_id, array $args): void {
        DomainPackRegistry::instance()->register_validator($pack_id, $validator_id, $args);
    }
}

if (!function_exists('awpt_register_pattern_recommender')) {
    /** @param array<string, mixed> $args */
    function awpt_register_pattern_recommender(string $pack_id, string $recommender_id, array $args): void {
        DomainPackRegistry::instance()->register_recommender($pack_id, $recommender_id, $args);
    }
}

if (!function_exists('awpt_register_pattern_materializer')) {
    /** @param array<string, mixed> $args */
    function awpt_register_pattern_materializer(string $pack_id, string $materializer_id, array $args): void {
        DomainPackRegistry::instance()->register_materializer($pack_id, $materializer_id, $args);
    }
}

if (!function_exists('awpt_register_proposal_operation')) {
    /** @param array<string, mixed> $args */
    function awpt_register_proposal_operation(string $pack_id, string $operation_id, array $args): void {
        DomainPackRegistry::instance()->register_proposal_operation($pack_id, $operation_id, $args);
    }
}
