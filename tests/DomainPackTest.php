<?php

/**
 * Domain Pack manifest, metadata, provenance, and validation contracts.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Domain\CompositionProposalGuard;
use AWPT\Domain\DeclarativeRuleEngine;
use AWPT\Domain\DesignCatalog;
use AWPT\Domain\DomainPackHealth;
use AWPT\Domain\DomainPackRegistry;
use AWPT\Domain\DomainProposalManager;
use AWPT\Domain\DomainRuleRepository;
use AWPT\Domain\DomainValidationService;
use AWPT\Domain\PatternMaterializer;
use AWPT\Domain\PatternMetadataCatalog;
use AWPT\Domain\SafeCompositionFixer;

function awpt_domain_test_directory(): string {
    $path = sys_get_temp_dir() . '/awpt-domain-' . bin2hex(random_bytes(6));
    mkdir($path . '/agent', recursive: true);
    mkdir($path . '/patterns', recursive: true);

    return $path;
}

function awpt_remove_domain_test_directory(string $path): void {
    foreach (glob($path . '/agent/*') ?: [] as $file) {
        unlink($file);
    }

    foreach (glob($path . '/patterns/*') ?: [] as $file) {
        unlink($file);
    }

    foreach (glob($path . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    rmdir($path . '/agent');
    rmdir($path . '/patterns');
    rmdir($path);
}

function test_domain_pack_loads_scoped_manifest_and_catalog(): void {
    $root = awpt_domain_test_directory();
    file_put_contents($root . '/agent/guidance.md', 'Use the theme patterns.');
    file_put_contents($root . '/agent/patterns.json', wp_json_encode([
        'patterns' => [
            'demo/hero' => [
                'role' => 'page-introduction',
                'intents' => ['landing page'],
                'max_per_document' => 1,
                'placement' => [
                    'regions' => ['first'],
                    'conflicts_with_roles' => ['page-introduction'],
                ],
                'slots' => [[
                    'id' => 'headline',
                    'type' => 'rich_text',
                    'required' => true,
                    'max_characters' => 90,
                ]],
                'design' => [
                    'width' => 'full',
                    'background_roles' => ['primary', 'image'],
                ],
            ],
        ],
    ]));
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 1,
        'id' => 'demo',
        'label' => 'Demo',
        'version' => '1.0.0',
        'guidance' => [
            [
                'id' => 'composition',
                'path' => 'agent/guidance.md',
                'applies_to' => ['compose'],
            ],
        ],
        'patterns' => [
            'namespace' => 'demo',
            'catalog' => 'agent/patterns.json',
            'aliases' => [
                'demo/page-header' => 'demo/header-page',
                'demo/team-directory' => 'demo/section-team-member-directory',
            ],
        ],
    ]));

    $registry = new DomainPackRegistry();
    $loaded = $registry->load_manifest($root);
    Assert::true(is_array($loaded), 'valid theme manifests should load');
    Assert::same('demo', $loaded['id'] ?? '', 'manifest IDs should be normalized');
    Assert::same(1, count($loaded['guidance'] ?? []), 'valid scoped guidance should be retained');

    $metadata = new PatternMetadataCatalog($registry);
    Assert::same(
        'page-introduction',
        $metadata->get('demo/hero')['role'] ?? '',
        'structured pattern roles should be available',
    );
    Assert::same(
        1,
        $metadata->get('demo/hero')['max_per_document'] ?? 0,
        'structured pattern constraints should be available',
    );
    Assert::same(
        ['first'],
        $metadata->get('demo/hero')['placement']['regions'] ?? [],
        'v2 placement contracts should survive sanitization',
    );
    Assert::same(
        'headline',
        $metadata->get('demo/hero')['slots'][0]['id'] ?? '',
        'v2 editable slots should survive sanitization',
    );
    Assert::same(
        ['primary', 'image'],
        $metadata->get('demo/hero')['design']['background_roles'] ?? [],
        'v2 design intent should survive sanitization',
    );
    Assert::same(
        'demo/header-page',
        $registry->pattern_aliases()['demo/page-header'] ?? '',
        'pack aliases should load from the manifest',
    );
    Assert::true(
        in_array('demo', $registry->pattern_namespaces(), true),
        'pack namespace should be discoverable for bare-slug resolution',
    );

    awpt_remove_domain_test_directory($root);
}

function test_domain_pack_rejects_outside_references(): void {
    $root = awpt_domain_test_directory();
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 1,
        'id' => 'demo',
        'label' => 'Demo',
        'version' => '1.0.0',
        'guidance' => [
            ['id' => 'unsafe', 'path' => '../outside.md'],
        ],
    ]));

    $registry = new DomainPackRegistry();
    $loaded = $registry->load_manifest($root);
    Assert::true(is_array($loaded), 'a manifest with one bad optional reference can still load');
    Assert::same([], $loaded['guidance'] ?? null, 'outside guidance references must be discarded');

    awpt_remove_domain_test_directory($root);
}

function test_domain_pack_design_catalog_is_typed_and_fault_tolerant(): void {
    $root = awpt_domain_test_directory();
    file_put_contents($root . '/agent/design.json', wp_json_encode([
        'schema_version' => 1,
        'token_roles' => [
            'surface' => ['domain' => 'color', 'slugs' => ['background']],
            'broken' => ['domain' => 'unknown', 'slugs' => []],
        ],
        'components' => [
            'button' => [
                'label' => 'Button',
                'block' => 'core/button',
                'kind' => 'style',
                'name' => 'brand',
                'class_names' => ['is-style-brand'],
            ],
        ],
        'guidance_sets' => ['edit' => ['design-system']],
    ]));
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 2,
        'id' => 'demo',
        'label' => 'Demo',
        'version' => '2.1.0',
        'design' => ['catalog' => 'agent/design.json'],
    ]));

    $registry = new DomainPackRegistry();
    $loaded = $registry->load_manifest($root);
    $catalog = new DesignCatalog($registry);

    Assert::same(
        'agent/design.json',
        $loaded['design']['catalog'] ?? '',
        'v2 should retain a safe design catalog reference',
    );
    Assert::same(
        ['background'],
        $catalog->all()['token_roles']['surface']['slugs'] ?? [],
        'valid semantic token roles should load',
    );
    Assert::false(isset($catalog->all()['token_roles']['broken']), 'invalid optional design records should be dropped');
    Assert::same(
        'core/button',
        $catalog->all()['components']['button']['block'] ?? '',
        'registered component contracts should load',
    );
    Assert::same(['design-system'], $catalog->guidance_for('edit'), 'task guidance sets should be addressable');
    Assert::same(
        '/demo/token_roles/broken',
        $catalog->diagnostics()[0]['pointer'] ?? '',
        'dropped records should report a JSON pointer',
    );

    awpt_remove_domain_test_directory($root);
}

function test_pattern_required_blocks_are_scoped_to_each_materialized_instance(): void {
    $root = awpt_domain_test_directory();
    file_put_contents($root . '/agent/patterns.json', wp_json_encode([
        'patterns' => [
            'demo/card' => ['required_blocks' => ['core/button']],
        ],
    ]));
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 2,
        'id' => 'demo',
        'label' => 'Demo',
        'version' => '2.0.0',
        'patterns' => ['namespace' => 'demo', 'catalog' => 'agent/patterns.json'],
    ]));
    $registry = new DomainPackRegistry();
    $registry->load_manifest($root);
    $engine = new DeclarativeRuleEngine(new DomainRuleRepository($registry), new PatternMetadataCatalog($registry));
    $content = implode('', [
        '<!-- wp:group {"metadata":{"patternName":"demo/card","patternInstance":"one"}} --><div class="wp-block-group">',
        '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link">Go</a></div><!-- /wp:button -->',
        '</div><!-- /wp:group -->',
        '<!-- wp:group {"metadata":{"patternName":"demo/card","patternInstance":"two"}} --><div class="wp-block-group">',
        '<!-- wp:paragraph --><p>Missing action</p><!-- /wp:paragraph -->',
        '</div><!-- /wp:group -->',
    ]);
    $missing = array_values(array_filter(
        $engine->validate($content),
        static fn(array $finding): bool => 'pattern-required-block-missing' === ($finding['code'] ?? ''),
    ));

    Assert::same(1, count($missing), 'a required block in one instance must not satisfy a different instance');
    Assert::same('1', $missing[0]['block_path'] ?? '', 'the finding should identify the incomplete instance');

    awpt_remove_domain_test_directory($root);
}

function test_domain_pack_health_reports_contract_quality_problems(): void {
    awpt_test_reset_state();
    $root = awpt_domain_test_directory();
    file_put_contents($root . '/agent/patterns.json', wp_json_encode([
        'schema_version' => 2,
        'patterns' => [
            'demo/hero' => [
                'role' => 'page-introduction',
                'summary' => 'Demo hero.',
                'intents' => ['landing'],
                'search_terms' => ['hero'],
                'companions' => ['demo/missing'],
                'docs' => 'agent/missing.md',
            ],
        ],
    ]));
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 2,
        'id' => 'demo',
        'label' => 'Demo',
        'version' => '2.0.0',
        'patterns' => ['namespace' => 'demo', 'catalog' => 'agent/patterns.json'],
    ]));
    $GLOBALS['awpt_test_registered_patterns'] = [[
        'name' => 'demo/hero',
        'title' => 'Hero',
        'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
    ]];
    $registry = new DomainPackRegistry();
    $registry->load_manifest($root);
    $report = new DomainPackHealth($registry)->report();
    $codes = array_map(static fn(array $issue): string => (string) $issue['code'], $report[0]['issues'] ?? []);

    Assert::true(in_array('missing-pattern-docs', $codes, true), 'health should detect missing pattern docs');
    Assert::true(
        in_array('broken-pattern-references', $codes, true),
        'health should detect broken companion and relationship slugs',
    );
    Assert::true(
        in_array('sparse-pattern-contracts', $codes, true),
        'health should identify patterns without selection guidance',
    );

    awpt_remove_domain_test_directory($root);
}

function test_domain_validation_normalizes_typed_findings(): void {
    $root = awpt_domain_test_directory();
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 1,
        'id' => 'demo',
        'label' => 'Demo',
        'version' => '1.0.0',
        'validators' => ['one-hero'],
    ]));

    $registry = new DomainPackRegistry();
    $registry->load_manifest($root);
    $registry->register_validator('demo', 'one-hero', [
        'callback' => static fn(string $content): array => (
            str_contains($content, 'duplicate')
                ? [
                    [
                        'severity' => 'error',
                        'code' => 'duplicate-hero',
                        'message' => 'Only one hero is permitted.',
                    ],
                ]
                : []
        ),
    ]);
    $service = new DomainValidationService($registry);
    $findings = $service->validate('duplicate');

    Assert::same('error', $findings[0]['severity'] ?? '', 'validator severity should be retained');
    Assert::same('demo', $findings[0]['pack_id'] ?? '', 'findings should identify their Domain Pack');
    Assert::true(
        $service->blocking_error($findings) instanceof WP_Error,
        'error findings should block a staged proposal',
    );

    awpt_remove_domain_test_directory($root);
}

function test_pattern_materializer_preserves_pattern_name(): void {
    $content = '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->';
    $materialized = new PatternMaterializer()->materialize('civicpress/header-hero', $content);

    Assert::true(
        str_contains($materialized, 'patternName'),
        'materialized pattern markup should carry editor-visible provenance',
    );
    Assert::true(
        str_contains($materialized, 'civicpress/header-hero'),
        'materialized provenance should use the exact registered pattern name',
    );
    Assert::true(
        str_contains($materialized, 'patternInstance'),
        'materialized pattern markup should identify one concrete pattern use',
    );
    Assert::true(
        new PatternMaterializer()->has_provenance('civicpress/header-hero', $materialized),
        'materialized markup should be recognized so revisions retain its instance identity',
    );
    Assert::false(
        new PatternMaterializer()->has_provenance('civicpress/other', $materialized),
        'provenance recognition should require the exact selected pattern',
    );
}

function test_domain_pack_v2_runs_declarative_rules_and_feedback(): void {
    $root = awpt_domain_test_directory();
    file_put_contents($root . '/agent/rules.json', wp_json_encode([
        'schema_version' => 1,
        'rules' => [[
            'id' => 'single-page-heading',
            'type' => 'headings.single_h1',
            'severity' => 'error',
            'scope' => ['compose'],
            'message' => 'Only one H1 is allowed.',
            'suggestion' => 'Demote the second heading.',
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
    $loaded = $registry->load_manifest($root);
    Assert::same(2, $loaded['schema_version'] ?? 0, 'v2 Domain Pack manifests should load');
    Assert::same(
        1,
        new DomainRuleRepository($registry)->summary()['count'],
        'v2 declarative rules should be discoverable',
    );

    $content = implode('', [
        '<!-- wp:heading {"level":1} --><h1>First</h1><!-- /wp:heading -->',
        '<!-- wp:heading {"level":1} --><h1>Second</h1><!-- /wp:heading -->',
    ]);
    $result = new DomainValidationService($registry)->evaluate($content, ['work_type' => 'compose']);

    Assert::same(
        'single-page-heading',
        $result['findings'][0]['code'] ?? '',
        'declarative heading constraints should produce stable findings',
    );
    Assert::same(
        'needs_correction',
        $result['agent_feedback']['outcome'] ?? '',
        'blocking findings should produce correction feedback',
    );
    Assert::same(
        1,
        $result['agent_feedback']['retry']['attempts_remaining'] ?? 0,
        'feedback should authorize one bounded correction attempt',
    );

    $query_page = implode('', [
        '<!-- wp:heading {"level":1} --><h1>News</h1><!-- /wp:heading -->',
        '<!-- wp:post-title {"level":3,"isLink":true} /-->',
        '<!-- wp:post-title {"level":3,"isLink":true} /-->',
    ]);
    $query_result = new DomainValidationService($registry)->evaluate($query_page, ['work_type' => 'compose']);
    Assert::same(
        [],
        array_values(array_filter(
            $query_result['findings'],
            static fn(array $finding): bool => 'single-page-heading' === ($finding['code'] ?? ''),
        )),
        'linked query-loop post titles below level one must not count as document H1s',
    );

    awpt_remove_domain_test_directory($root);
}

function test_safe_composition_fixer_reports_lossless_repairs(): void {
    $content = '<!-- wp:paragraph --><p class="notice notice">Keep this text.</p><!-- /wp:paragraph -->';
    $result = new SafeCompositionFixer()->fix($content);

    Assert::true(
        str_contains($result['content'], 'class="notice"'),
        'safe fixes should remove only duplicate class tokens',
    );
    Assert::same(
        'normalize-class-tokens',
        $result['fixes'][0]['id'] ?? '',
        'safe fixes should report the exact repair performed',
    );
    Assert::true(str_contains($result['content'], 'Keep this text.'), 'safe fixes should preserve visible copy');
}

function test_domain_operation_cleanup_runs_for_rejected_preview_resources(): void {
    $root = awpt_domain_test_directory();
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 1,
        'id' => 'demo',
        'label' => 'Demo',
        'version' => '1.0.0',
        'proposal_operations' => ['generate-report'],
    ]));

    $registry = new DomainPackRegistry();
    $registry->load_manifest($root);
    $cleaned = false;
    $registry->register_proposal_operation('demo', 'generate-report', [
        'ability_name' => 'demo/propose-generate-report',
        'input_schema' => ['type' => 'object'],
        'permission_callback' => static fn(array $payload, string $phase): bool => 'cleanup' === $phase,
        'sanitize_callback' => static fn(array $payload): array => $payload,
        'stage_callback' => static fn(): array => ['payload' => []],
        'apply_callback' => static fn(): array => [],
        'snapshot_callback' => static fn(): array => [],
        'fingerprint_callback' => static fn(): string => 'current-state',
        'rollback_callback' => static fn(): array => [],
        'cleanup_callback' => static function () use (&$cleaned): void {
            $cleaned = true;
        },
    ]);

    $error = new DomainProposalManager($registry)->cleanup([
        'operation' => 'generate-report',
        'domain_payload' => ['temporary_id' => 42],
    ]);

    Assert::same(null, $error, 'successful custom cleanup should not return an error');
    Assert::true($cleaned, 'reject cleanup should release custom preview resources');

    awpt_remove_domain_test_directory($root);
}

function test_proposal_guard_grandfathers_shifted_import_debt_without_hiding_new_debt(): void {
    $existing = [
        [
            'severity' => 'error',
            'code' => 'no-custom-html',
            'rule_id' => 'no-custom-html',
            'source' => 'CivicPress',
            'expected' => '',
            'actual' => 'core/html',
            'block_path' => '5.0',
        ],
        [
            'severity' => 'error',
            'code' => 'no-custom-html',
            'rule_id' => 'no-custom-html',
            'source' => 'CivicPress',
            'expected' => '',
            'actual' => 'core/html',
            'block_path' => '7',
        ],
    ];
    $shifted = $existing;
    $shifted[0]['block_path'] = '7.0';
    $shifted[1]['block_path'] = '9';

    Assert::same(
        [],
        CompositionProposalGuard::new_findings($shifted, $existing),
        'inserting an earlier block must not turn unchanged imported violations into new violations',
    );

    $shifted[] = [...$shifted[1], 'block_path' => '10'];
    Assert::same(
        '10',
        CompositionProposalGuard::new_findings($shifted, $existing)[0]['block_path'] ?? '',
        'the finding multiset must still reject an additional violation of the same rule',
    );
}

test_domain_pack_loads_scoped_manifest_and_catalog();
test_domain_pack_rejects_outside_references();
test_domain_pack_design_catalog_is_typed_and_fault_tolerant();
test_pattern_required_blocks_are_scoped_to_each_materialized_instance();
test_domain_pack_health_reports_contract_quality_problems();
test_domain_validation_normalizes_typed_findings();
test_pattern_materializer_preserves_pattern_name();
test_domain_pack_v2_runs_declarative_rules_and_feedback();
test_safe_composition_fixer_reports_lossless_repairs();
test_domain_operation_cleanup_runs_for_rejected_preview_resources();
test_proposal_guard_grandfathers_shifted_import_debt_without_hiding_new_debt();
