<?php

/**
 * AWPT ability registration.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AbilityReplacementRegistry;
use AWPT\Domain\DomainPackRegistry;

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
        // Core and the AI feature plugin register/refresh core/* abilities by
        // priority 11. Run afterward so schema-verified replacements are based
        // on the final live Core contract.
        add_action('wp_abilities_api_init', [$this, 'register_abilities'], 20);
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

        if ('awpt/read-content' === new AbilityReplacementRegistry()->preferred('awpt/read-content')) {
            new ReadContent()->register();
        } elseif (
            function_exists('wp_has_ability')
            && function_exists('wp_unregister_ability')
            && wp_has_ability('awpt/read-content')
        ) {
            wp_unregister_ability('awpt/read-content');
        }
        new FindAbilities()->register();
        new GetWorkContext()->register();
        new ReadProposal()->register();
        new ReadAttachmentDocument()->register();
        new ListWordPressResources()->register();
        new ReadWordPressResource()->register();
        new ReadThemes()->register();
        new ReadThemeJson()->register();
        new ReadThemeFile()->register();
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
        new ListPatterns()->register();
        new RecommendPatterns()->register();
        new PreparePatternDraft()->register();
        new PreparePatternChange()->register();
        new ReadPattern()->register();
        new ListDomainPacks()->register();
        new ReadDomainGuidance()->register();
        new ValidateComposition()->register();
        new ReadGlobalStyles()->register();
        new ReadNavigation()->register();
        new SearchKnowledge()->register();
        new ListKnowledgeSources()->register();
        new ReadKnowledge()->register();
        new ProposeContentUpdate()->register();
        new ProposeBlockAttrsUpdate()->register();
        new ProposeBlockBatchUpdate()->register();
        new ProposeBlockInsert()->register();
        new ProposeBlockRemove()->register();
        new ProposePatternInsert()->register();
        new ProposePatternReplace()->register();
        new ProposeTemplateUpdate()->register();
        new ProposeGlobalStylesUpdate()->register();
        new ProposeGlobalStylesPatch()->register();
        new ProposeNavigationChange()->register();
        new ProposePatternedPost()->register();
        new ProposeNewPost()->register();
        new ProposeSiteSettingsUpdate()->register();
        new ProposeThemeSwitch()->register();
        new ProposePluginDeactivate()->register();
        new ProposeCustomCssUpdate()->register();
        new ProposeResourceChange()->register();
        new ApplyAction()->register();
        new SideloadMedia()->register();
        new ReadErrorLog()->register();
        new ReadPlugins()->register();
        new ReadSiteHealth()->register();
        new ProbeUrl()->register();
        new InspectFrontend()->register();
        new InspectRenderedElement()->register();
        new DiagnoseError()->register();

        foreach (DomainPackRegistry::instance()->proposal_operations() as $operation) {
            new DomainProposalAbility($operation)->register();
        }
    }
}
