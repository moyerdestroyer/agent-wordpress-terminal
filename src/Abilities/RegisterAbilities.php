<?php

/**
 * AWPT ability registration.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Registers AWPT plugin abilities.
 */
final class RegisterAbilities {
    /**
     * Hook ability registration.
     */
    public function init(): void {
        add_action('wp_abilities_api_categories_init', [$this, 'register_category']);
        add_action('wp_abilities_api_init', [$this, 'register_abilities']);
    }

    /**
     * Register the AWPT ability category.
     */
    public function register_category(): void {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category('awpt', [
            'label' => __('Agent Terminal', 'agent-wordpress-terminal'),
            'description' => __('Tools for the Agent WordPress Terminal.', 'agent-wordpress-terminal'),
        ]);
    }

    /**
     * Register all AWPT abilities.
     */
    public function register_abilities(): void {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        new ReadContent()->register();
        new ReadThemes()->register();
        new ReadThemeJson()->register();
        new ReadBlockTree()->register();
        new GetBlock()->register();
        new ListBlocks()->register();
        new RenderBlock()->register();
        new AnalyzePage()->register();
        new PreviewPost()->register();
        new SearchContent()->register();
        new ListContent()->register();
        new ListTemplates()->register();
        new ReadTemplate()->register();
        new SearchKnowledge()->register();
        new ReadKnowledge()->register();
        new ProposeContentUpdate()->register();
        new ProposeBlockAttrsUpdate()->register();
        new ProposeBlockInsert()->register();
        new ProposeBlockRemove()->register();
        new ProposeNewPost()->register();
        new ProposeSiteSettingsUpdate()->register();
        new ProposeThemeSwitch()->register();
        new ProposePluginDeactivate()->register();
        new ApplyAction()->register();
        new SideloadMedia()->register();
        new ReadErrorLog()->register();
        new ReadPlugins()->register();
        new ReadSiteHealth()->register();
        new ProbeUrl()->register();
        new DiagnoseError()->register();
    }
}
