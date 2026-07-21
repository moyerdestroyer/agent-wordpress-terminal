<?php

/**
 * Staged action operation identifiers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Canonical operation values stored on staged action payloads.
 */
final class ActionOperations {
    public const CONTENT_UPDATE = 'content_update';
    public const BLOCK_ATTRS_UPDATE = 'block_attrs_update';
    public const BLOCK_INSERT = 'block_insert';
    public const BLOCK_REMOVE = 'block_remove';
    public const PATTERN_INSERT = 'pattern_insert';
    public const NEW_POST = 'new_post';
    public const TEMPLATE_UPDATE = 'template_update';
    public const GLOBAL_STYLES_UPDATE = 'global_styles_update';
    public const GLOBAL_STYLES_CREATE = 'global_styles_create';
    public const SITE_SETTINGS_UPDATE = 'site_settings_update';
    public const THEME_SWITCH = 'theme_switch';
    public const PLUGIN_DEACTIVATE = 'plugin_deactivate';

    /**
     * @var list<string>
     */
    public const ALL = [
        self::CONTENT_UPDATE,
        self::BLOCK_ATTRS_UPDATE,
        self::BLOCK_INSERT,
        self::BLOCK_REMOVE,
        self::PATTERN_INSERT,
        self::NEW_POST,
        self::TEMPLATE_UPDATE,
        self::GLOBAL_STYLES_UPDATE,
        self::GLOBAL_STYLES_CREATE,
        self::SITE_SETTINGS_UPDATE,
        self::THEME_SWITCH,
        self::PLUGIN_DEACTIVATE,
    ];

    /**
     * Operations that support frontend preview.
     *
     * @var list<string>
     */
    public const PREVIEWABLE = [
        self::CONTENT_UPDATE,
        self::BLOCK_ATTRS_UPDATE,
        self::BLOCK_INSERT,
        self::BLOCK_REMOVE,
        self::PATTERN_INSERT,
        self::NEW_POST,
    ];

    public static function is_valid(string $operation): bool {
        return in_array($operation, self::ALL, true);
    }

    public static function is_previewable(string $operation): bool {
        return in_array($operation, self::PREVIEWABLE, true);
    }
}
