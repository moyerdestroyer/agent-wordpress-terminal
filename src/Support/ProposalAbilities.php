<?php

/**
 * Proposal ability names shared across agent runtime and admin UI.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Single source of truth for abilities that create staged action cards.
 */
final class ProposalAbilities
{
    /**
     * @var list<string>
     */
    public const NAMES = [
        'awpt/propose-content-update',
        'awpt/propose-new-post',
        'awpt/propose-site-settings-update',
        'awpt/propose-theme-switch',
    ];

    /**
     * Abilities that require a session_id injected at execution time.
     *
     * @var list<string>
     */
    public const SESSION_SCOPED = [
        ...self::NAMES,
        'awpt/sideload-media',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    public static function is_proposal(string $ability_name): bool
    {
        return in_array($ability_name, self::NAMES, true);
    }

    public static function requires_session_id(string $ability_name): bool
    {
        return in_array($ability_name, self::SESSION_SCOPED, true);
    }
}
