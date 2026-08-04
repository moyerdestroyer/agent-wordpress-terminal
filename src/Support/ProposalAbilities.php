<?php

/**
 * Proposal ability names shared across agent runtime and admin UI.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Domain\DomainPackRegistry;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Single source of truth for abilities that create staged action cards.
 */
final class ProposalAbilities {
    /**
     * @var list<string>
     */
    public const NAMES = [
        'awpt/propose-content-update',
        'awpt/propose-block-attrs-update',
        'awpt/propose-block-batch-update',
        'awpt/propose-block-insert',
        'awpt/propose-block-remove',
        'awpt/propose-pattern-insert',
        'awpt/propose-template-update',
        'awpt/propose-global-styles-update',
        'awpt/propose-global-styles-patch',
        'awpt/propose-navigation-change',
        'awpt/propose-patterned-post',
        'awpt/propose-new-post',
        'awpt/propose-site-settings-update',
        'awpt/propose-theme-switch',
        'awpt/propose-plugin-deactivate',
        'awpt/propose-custom-css-update',
        'awpt/propose-resource-change',
    ];

    /**
     * Abilities that require a session_id injected at execution time.
     *
     * @var list<string>
     */
    public const SESSION_SCOPED = [
        ...self::NAMES,
        'awpt/diagnose-error',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array {
        $domain = array_map(
            static fn(array $operation): string => (string) ($operation['ability_name'] ?? ''),
            DomainPackRegistry::instance()->proposal_operations(),
        );

        return array_values(array_unique([...self::NAMES, ...array_filter($domain)]));
    }

    public static function is_proposal(string $ability_name): bool {
        return in_array($ability_name, self::names(), true);
    }

    public static function requires_session_id(string $ability_name): bool {
        return in_array($ability_name, [...self::SESSION_SCOPED, ...self::names()], true);
    }
}
