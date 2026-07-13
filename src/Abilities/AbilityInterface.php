<?php

/**
 * Contract for AWPT abilities.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Every AWPT ability registers itself and provides an execute callback.
 */
interface AbilityInterface {
    /**
     * Register the ability with the WordPress Abilities API.
     */
    public function register(): void;

    /**
     * Execute the ability.
     *
     * @param array<string, mixed> $input Ability input.
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error;
}
