<?php

/**
 * Task-scoped compiled design-system slices.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\AgentWorkContextService;
use AWPT\Agent\TurnProfile;
use AWPT\Domain\DesignCatalog;
use AWPT\Domain\DomainPackRegistry;
use AWPT\Support\DesignSystemContextService;
use AWPT\Support\ImprovePagePrompt;

function awpt_design_context_test_directory(): string {
    $path = sys_get_temp_dir() . '/awpt-design-ctx-' . bin2hex(random_bytes(6));
    mkdir($path . '/agent', recursive: true);

    return $path;
}

function awpt_remove_design_context_test_directory(string $path): void {
    foreach (glob($path . '/agent/*') ?: [] as $file) {
        unlink($file);
    }

    foreach (glob($path . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    rmdir($path . '/agent');
    rmdir($path);
}

function awpt_design_context_test_pack(): array {
    $root = awpt_design_context_test_directory();
    file_put_contents($root . '/agent/design.json', wp_json_encode([
        'schema_version' => 1,
        'token_roles' => [
            'surface' => ['domain' => 'color', 'slugs' => ['background']],
        ],
        'components' => [
            'button' => [
                'label' => 'Button',
                'block' => 'core/button',
                'kind' => 'style',
                'name' => 'brand',
            ],
        ],
        'style_variations' => [
            'dark' => ['label' => 'Dark', 'slug' => 'dark'],
        ],
        'archetypes' => [
            'landing-page' => [
                'label' => 'Landing page',
                'pattern_roles' => ['header', 'cta'],
            ],
        ],
        'guidance_sets' => [
            'all' => ['domain-direction'],
            'compose' => ['page-composition', 'composition-rubric'],
            'edit' => ['content-accessibility'],
            'evaluate' => ['composition-rubric'],
            'global_styles' => ['design-system'],
        ],
    ]));
    file_put_contents($root . '/agent/patterns.json', wp_json_encode([
        'patterns' => [
            'demo/layout-page-landing' => [
                'role' => 'page-layout',
                'summary' => 'Landing layout.',
                'use_when' => ['A complete landing journey is needed.'],
                'avoid_when' => ['Only one section needs changing.'],
            ],
            'demo/section-cta' => [
                'role' => 'page-section',
                'summary' => 'Call to action.',
                'use_when' => ['A verified next action exists.'],
                'avoid_when' => ['No destination exists.'],
            ],
        ],
    ]));
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 2,
        'id' => 'demo',
        'label' => 'Demo',
        'version' => '2.1.0',
        'design' => ['catalog' => 'agent/design.json'],
        'patterns' => ['catalog' => 'agent/patterns.json'],
    ]));

    $registry = new DomainPackRegistry();
    $registry->load_manifest($root);
    $GLOBALS['awpt_test_registered_patterns'] = [
        [
            'name' => 'demo/layout-page-landing',
            'title' => 'Landing Layout',
            'description' => 'A complete landing page.',
            'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
        ],
        [
            'name' => 'demo/section-cta',
            'title' => 'Call to Action',
            'description' => 'A next action.',
            'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
        ],
    ];

    return [
        'root' => $root,
        'registry' => $registry,
        'service' => new DesignSystemContextService(null, new DesignCatalog($registry)),
        'work' => new AgentWorkContextService($registry),
    ];
}

function test_design_system_compose_slice_includes_archetypes_and_pattern_index(): void {
    awpt_test_reset_state();
    $pack = awpt_design_context_test_pack();
    $profile = TurnProfile::from_message('Create a polished landing page for a neighborhood garden club.');
    $context = $pack['work']->compile($profile->message, $profile);
    $prompt = $pack['service']->format_for_prompt(
        $pack['work']->scope($profile),
        $profile->message,
        $pack['work']->design_detail($profile),
    );

    Assert::same('compose', $context['design_scope'] ?? '', 'new-page work uses the compose design scope');
    Assert::same(
        DesignSystemContextService::DETAIL_COMPOSE,
        $context['design_detail'] ?? '',
        'new-page work gets the compose design slice',
    );
    Assert::true(str_contains($prompt, 'landing-page'), 'compose slice includes archetypes');
    Assert::true(str_contains($prompt, 'page-composition'), 'compose slice includes compose guidance IDs');
    Assert::true(str_contains($prompt, 'prefer_presets'), 'compose slice carries the preset constraint');
    Assert::true(str_contains($prompt, 'pattern_catalog'), 'compose slice carries the compact pattern index');
    Assert::false(str_contains($prompt, 'demo/layout-page-landing'), 'compose slice does not dump every pattern name');
    Assert::same(
        ['A complete landing journey is needed.'],
        $context['pattern_candidates'][0]['use_when'] ?? [],
        'creation work context carries compact use guidance',
    );
    Assert::same(
        ['Only one section needs changing.'],
        $context['pattern_candidates'][0]['avoid_when'] ?? [],
        'creation work context carries compact avoid guidance',
    );

    awpt_remove_design_context_test_directory($pack['root']);
}

function test_design_system_evaluate_slice_names_patterns(): void {
    awpt_test_reset_state();
    $pack = awpt_design_context_test_pack();
    $message = ImprovePagePrompt::evaluate_text();
    $profile = TurnProfile::from_message($message, [], ['has_focus' => true]);
    $prompt = $pack['service']->format_for_prompt(
        $pack['work']->scope($profile),
        $message,
        $pack['work']->design_detail($profile),
    );

    Assert::same('evaluate', $pack['work']->scope($profile), 'Improve evaluate uses the evaluate catalog key');
    Assert::same(
        DesignSystemContextService::DETAIL_EVALUATE,
        $pack['work']->design_detail($profile),
        'Improve evaluate gets the pattern-aware evaluate slice',
    );
    Assert::true(str_contains($prompt, 'composition-rubric'), 'evaluate slice includes evaluate guidance IDs');
    Assert::true(str_contains($prompt, 'landing-page'), 'evaluate slice includes the archetype catalog');
    Assert::true(str_contains($prompt, 'pattern_catalog'), 'evaluate slice includes the compact pattern index');
    Assert::false(str_contains($prompt, '"pattern_roles":{'), 'evaluate slice omits the all-pattern name map');
    Assert::false(str_contains($prompt, '"components"'), 'evaluate slice omits the component dump');

    awpt_remove_design_context_test_directory($pack['root']);
}

function test_design_system_global_styles_slice_includes_resolved_tokens(): void {
    awpt_test_reset_state();
    $pack = awpt_design_context_test_pack();
    $message = 'Update the site-wide palette and global styles tokens.';
    $profile = TurnProfile::from_message($message);
    $prompt = $pack['service']->format_for_prompt(
        $pack['work']->scope($profile),
        $message,
        $pack['work']->design_detail($profile),
    );

    Assert::same('global_styles', $pack['work']->scope($profile), 'palette/global-styles work uses that scope');
    Assert::same(
        DesignSystemContextService::DETAIL_GLOBAL_STYLES,
        $pack['work']->design_detail($profile),
        'global-styles work gets resolved token values',
    );
    Assert::true(str_contains($prompt, '"tokens"'), 'global-styles slice includes resolved tokens');
    Assert::true(str_contains($prompt, 'dark'), 'global-styles slice includes style variations');
    Assert::false(str_contains($prompt, 'landing-page'), 'global-styles slice omits compose archetypes');

    awpt_remove_design_context_test_directory($pack['root']);
}

function test_design_system_redesign_uses_compose_detail_without_evaluate_scope(): void {
    awpt_test_reset_state();
    $pack = awpt_design_context_test_pack();
    $message = ImprovePagePrompt::text();
    $profile = TurnProfile::from_message($message, [], ['has_focus' => true]);
    $prompt = $pack['service']->format_for_prompt(
        $pack['work']->scope($profile),
        $message,
        $pack['work']->design_detail($profile),
    );

    Assert::true($profile->is_redesign(), 'legacy Improve remains redesign');
    Assert::same(
        DesignSystemContextService::DETAIL_COMPOSE,
        $pack['work']->design_detail($profile),
        'bespoke/redesign fallback gets archetypes',
    );
    Assert::true(str_contains($prompt, 'landing-page'), 'redesign slice includes archetypes');
    Assert::false(str_contains($pack['work']->scope($profile), 'redesign'), 'work_mode is not used as a catalog key');

    awpt_remove_design_context_test_directory($pack['root']);
}

test_design_system_compose_slice_includes_archetypes_and_pattern_index();
test_design_system_evaluate_slice_names_patterns();
test_design_system_global_styles_slice_includes_resolved_tokens();
test_design_system_redesign_uses_compose_detail_without_evaluate_scope();
