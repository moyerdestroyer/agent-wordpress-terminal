<?php

/**
 * REST controller base.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\REST;

defined('ABSPATH') || exit();

/**
 * Shared REST permission helpers.
 */
abstract class RestController
{
    /**
     * Permission check for admin endpoints.
     */
    public function can_manage(): bool
    {
        return current_user_can(capability: 'manage_options');
    }
}
