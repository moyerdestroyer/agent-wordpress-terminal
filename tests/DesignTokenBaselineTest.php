<?php

/**
 * Theme-derived preset enforcement on newly proposed markup.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Domain\CompositionGate;
use AWPT\Domain\CompositionProposalGuard;
use AWPT\Domain\DeclarativeRuleEngine;
use AWPT\Domain\DomainPackRegistry;
use AWPT\Domain\DomainRuleRepository;

function awpt_token_baseline_hex_paragraph(string $hex = '#ff0000'): string {
    return '<!-- wp:paragraph {"style":{"color":{"text":"' . $hex . '"}}} --><p>Hello</p><!-- /wp:paragraph -->';
}

function awpt_token_baseline_preset_paragraph(): string {
    return '<!-- wp:paragraph {"textColor":"primary"} --><p>Hello</p><!-- /wp:paragraph -->';
}

function awpt_token_baseline_test_directory(): string {
    $path = sys_get_temp_dir() . '/awpt-token-' . bin2hex(random_bytes(6));
    mkdir($path . '/agent', recursive: true);

    return $path;
}

function awpt_remove_token_baseline_test_directory(string $path): void {
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

function test_theme_preset_baseline_rejects_new_hardcoded_color(): void {
    awpt_test_reset_state();
    $findings = new DeclarativeRuleEngine()->validate(awpt_token_baseline_hex_paragraph(), ['work_type' => 'edit']);
    $codes = array_map(static fn(array $finding): string => (string) ($finding['code'] ?? ''), $findings);

    Assert::true(
        in_array('theme-require-presets', $codes, true),
        'hardcoded color on new edit markup should fail when the theme has a palette',
    );
}

function test_theme_preset_baseline_allows_registered_preset_markup(): void {
    awpt_test_reset_state();
    $findings = new DeclarativeRuleEngine()->validate(awpt_token_baseline_preset_paragraph(), [
        'work_type' => 'compose',
    ]);
    $preset = array_values(array_filter(
        $findings,
        static fn(array $finding): bool => 'theme-require-presets' === ($finding['code'] ?? ''),
    ));

    Assert::same([], $preset, 'registered preset slugs should pass the theme baseline');
}

function awpt_token_baseline_introduced_findings(string $proposed, string $original): array {
    $gate = new CompositionGate();
    $context = [
        'work_type' => 'edit',
        'operation' => 'content_update',
        'phase' => 'propose',
    ];
    $next = $gate->evaluate($proposed, $context);
    $baseline = $gate->evaluate($original, [...$context, 'phase' => 'baseline']);

    return CompositionProposalGuard::new_findings($next['findings'], $baseline['findings']);
}

function test_theme_preset_baseline_grandfathers_unchanged_legacy_hex(): void {
    awpt_test_reset_state();
    $legacy = awpt_token_baseline_hex_paragraph('#14532d');
    $introduced = awpt_token_baseline_introduced_findings($legacy, $legacy);
    $error = new CompositionGate()->blocking_error($introduced);

    Assert::same([], $introduced, 'unchanged imported hardcoded color is not a new finding');
    Assert::true(null === $error, 'unchanged imported hardcoded color must not block a surgical proposal');
}

function test_theme_preset_baseline_blocks_newly_introduced_hex(): void {
    awpt_test_reset_state();
    $introduced = awpt_token_baseline_introduced_findings(
        awpt_token_baseline_hex_paragraph('#ff0000'),
        awpt_token_baseline_preset_paragraph(),
    );
    $error = new CompositionGate()->blocking_error($introduced);
    $codes = array_map(static fn(array $finding): string => (string) ($finding['code'] ?? ''), $introduced);

    Assert::true(
        in_array('theme-require-presets', $codes, true),
        'a newly introduced hardcoded color should be a blocking finding',
    );
    Assert::true($error instanceof WP_Error, 'a newly introduced hardcoded color should fail before staging');
}

function test_theme_preset_baseline_is_silent_without_theme_presets(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_theme_json_data'] = ['settings' => []];
    $findings = new DeclarativeRuleEngine()->validate(awpt_token_baseline_hex_paragraph(), ['work_type' => 'edit']);
    $codes = array_map(static fn(array $finding): string => (string) ($finding['code'] ?? ''), $findings);

    Assert::false(
        in_array('theme-require-presets', $codes, true),
        'themes without theme/custom presets must not invent preset errors',
    );
}

function test_pack_preset_rule_does_not_duplicate_theme_baseline(): void {
    awpt_test_reset_state();
    $root = awpt_token_baseline_test_directory();
    file_put_contents($root . '/agent/rules.json', wp_json_encode([
        'schema_version' => 1,
        'rules' => [[
            'id' => 'use-design-presets',
            'type' => 'tokens.require_presets',
            'severity' => 'error',
            'scope' => ['edit'],
            'config' => ['domains' => ['color']],
            'message' => 'Use registered presets.',
            'suggestion' => 'Swap the hardcoded color for a theme slug.',
        ]],
    ]));
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 2,
        'id' => 'demo',
        'label' => 'Demo',
        'version' => '2.0.0',
        'rules' => ['path' => 'agent/rules.json'],
    ]));

    $registry = new DomainPackRegistry();
    $registry->load_manifest($root);
    $findings = new DeclarativeRuleEngine(
        new DomainRuleRepository($registry),
    )->validate(awpt_token_baseline_hex_paragraph(), ['work_type' => 'edit']);
    $codes = array_map(static fn(array $finding): string => (string) ($finding['code'] ?? ''), $findings);
    $preset_codes = array_values(array_filter($codes, static fn(string $code): bool => in_array(
        $code,
        ['use-design-presets', 'theme-require-presets'],
        true,
    )));

    Assert::same(
        ['use-design-presets'],
        $preset_codes,
        'a pack tokens.require_presets rule should replace the theme baseline, not stack with it',
    );

    awpt_remove_token_baseline_test_directory($root);
}

test_theme_preset_baseline_rejects_new_hardcoded_color();
test_theme_preset_baseline_allows_registered_preset_markup();
test_theme_preset_baseline_grandfathers_unchanged_legacy_hex();
test_theme_preset_baseline_blocks_newly_introduced_hex();
test_theme_preset_baseline_is_silent_without_theme_presets();
test_pack_preset_rule_does_not_duplicate_theme_baseline();
