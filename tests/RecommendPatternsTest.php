<?php

/**
 * Domain-aware pattern recommendation relevance.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\RecommendPatterns;
use AWPT\Domain\DomainPackRegistry;
use AWPT\Domain\PatternMetadataCatalog;
use AWPT\Support\PatternCatalog;

function test_recommend_patterns_ignores_generic_request_words(): void {
    $ability = new RecommendPatterns();
    $method = new ReflectionMethod(RecommendPatterns::class, 'terms');
    $method->setAccessible(true);
    $terms = $method->invoke($ability, 'Create a public news and announcements landing page');

    Assert::same(
        ['public', 'news', 'announcements', 'landing'],
        $terms,
        'recommendation evidence should omit stopwords and generic composition nouns',
    );
}

function test_recommend_patterns_weights_curated_semantics(): void {
    awpt_test_reset_state();
    $root = sys_get_temp_dir() . '/awpt-recommend-' . bin2hex(random_bytes(6));
    mkdir($root);
    file_put_contents($root . '/patterns.json', wp_json_encode([
        'patterns' => [
            'civicpress/layout-page-news' => [
                'role' => 'page-layout',
                'summary' => 'News or press listing with dynamic results.',
                'intents' => ['news', 'press', 'announcements'],
                'search_terms' => ['news', 'updates'],
            ],
            'civicpress/header-hero' => [
                'role' => 'page-introduction',
                'summary' => 'Mission banner for a landing page.',
                'intents' => ['landing page', 'mission'],
                'search_terms' => ['hero', 'banner'],
            ],
        ],
    ]));
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 1,
        'id' => 'demo',
        'label' => 'Demo',
        'version' => '1.0.0',
        'patterns' => ['catalog' => 'patterns.json'],
    ]));

    $GLOBALS['awpt_test_registered_patterns'] = [
        [
            'name' => 'civicpress/header-hero',
            'title' => 'Hero Header',
            'description' => 'A prominent page introduction.',
            'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
        ],
        [
            'name' => 'civicpress/layout-page-news',
            'title' => 'News Page',
            'description' => 'A dynamic listing.',
            'content' => '<!-- wp:query /-->',
        ],
    ];

    $registry = new DomainPackRegistry();
    $registry->load_manifest($root);
    $catalog = new PatternCatalog(null, new PatternMetadataCatalog($registry));
    $result = new RecommendPatterns($catalog, $registry)->execute([
        'intent' => 'public news and announcements landing page',
        'post_type' => 'page',
        'max' => 2,
    ]);
    $recommendations = $result['recommendations'] ?? [];

    Assert::same(
        'civicpress/layout-page-news',
        $recommendations[0]['pattern']['name'] ?? '',
        'curated news and announcement semantics should outrank a generic landing-page match',
    );
    Assert::true(
        (int) ($recommendations[0]['score'] ?? 0) > (int) ($recommendations[1]['score'] ?? 0),
        'semantic field weighting should produce a meaningful score difference',
    );

    unlink($root . '/patterns.json');
    unlink($root . '/awpt-domain.json');
    rmdir($root);
}

function test_recommend_patterns_boosts_target_role_section_matches(): void {
    awpt_test_reset_state();
    $root = sys_get_temp_dir() . '/awpt-recommend-role-' . bin2hex(random_bytes(6));
    mkdir($root);
    file_put_contents($root . '/patterns.json', wp_json_encode([
        'patterns' => [
            'demo/layout-page-home' => [
                'role' => 'page-layout',
                'summary' => 'Full home page layout.',
                'intents' => ['home', 'landing'],
                'search_terms' => ['home', 'landing'],
            ],
            'demo/faq-accordion' => [
                'role' => 'faq',
                'summary' => 'Frequently asked questions accordion.',
                'intents' => ['faq', 'questions'],
                'search_terms' => ['faq', 'accordion'],
            ],
        ],
    ]));
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 1,
        'id' => 'demo-role',
        'label' => 'Demo Role',
        'version' => '1.0.0',
        'patterns' => ['catalog' => 'patterns.json'],
    ]));

    $GLOBALS['awpt_test_registered_patterns'] = [
        [
            'name' => 'demo/layout-page-home',
            'title' => 'Home Layout Page',
            'description' => 'Full landing page layout.',
            'content' => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
        ],
        [
            'name' => 'demo/faq-accordion',
            'title' => 'FAQ Accordion',
            'description' => 'Section FAQ pattern.',
            'content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>FAQ</h2><!-- /wp:heading --></div><!-- /wp:group -->',
        ],
    ];

    $registry = new DomainPackRegistry();
    $registry->load_manifest($root);
    $catalog = new PatternCatalog(null, new PatternMetadataCatalog($registry));
    $result = new RecommendPatterns($catalog, $registry)->execute([
        'intent' => 'refresh this area',
        'post_type' => 'page',
        'max' => 2,
        'semantic' => false,
        'target_role' => 'faq',
        'prefer_section_scope' => true,
    ]);
    $recommendations = $result['recommendations'] ?? [];

    Assert::same('faq', $result['target_role'] ?? null, 'target_role echoed');
    Assert::same(
        'demo/faq-accordion',
        $recommendations[0]['pattern']['name'] ?? '',
        'FAQ role should outrank full-page layout when target_role=faq',
    );
    Assert::true(
        str_contains((string) ($recommendations[0]['rationale'] ?? ''), 'section role'),
        'rationale mentions section role boost',
    );

    unlink($root . '/patterns.json');
    unlink($root . '/awpt-domain.json');
    rmdir($root);
}

test_recommend_patterns_ignores_generic_request_words();
test_recommend_patterns_weights_curated_semantics();
test_recommend_patterns_boosts_target_role_section_matches();
