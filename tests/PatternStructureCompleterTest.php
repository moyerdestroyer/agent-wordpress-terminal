<?php

/**
 * Pattern-led documents retain exact required source blocks without constraining freeform work.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Domain\DomainPackRegistry;
use AWPT\Domain\PatternMetadataCatalog;
use AWPT\Domain\PatternStructureCompleter;

function test_pattern_structure_completer_restores_only_missing_dependency(): void {
    $root = awpt_domain_test_directory();
    file_put_contents($root . '/patterns.json', wp_json_encode([
        'patterns' => [
            'demo/news' => ['required_blocks' => ['core/query']],
        ],
    ]));
    file_put_contents($root . '/awpt-domain.json', wp_json_encode([
        'schema_version' => 1,
        'id' => 'demo',
        'label' => 'Demo',
        'version' => '1.0.0',
        'patterns' => ['catalog' => 'patterns.json'],
    ]));
    $registry = new DomainPackRegistry();
    $registry->load_manifest($root);
    $completer = new PatternStructureCompleter(new PatternMetadataCatalog($registry));
    $source = '<!-- wp:query {"query":{"perPage":6}} --><div class="wp-block-query"></div><!-- /wp:query -->';
    $draft = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>News intro.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $completed = $completer->complete('demo/news', $source, $draft);

    Assert::true(
        str_contains($completed['content'], 'wp:query'),
        'missing required query should be restored from the selected pattern',
    );
    Assert::same(
        ['restored_pattern_required_block:core/query'],
        $completed['repairs'],
        'repair should identify the exact restored dependency',
    );

    $unchanged = $completer->complete('demo/news', $source, $completed['content']);
    Assert::same([], $unchanged['repairs'], 'an existing required block must never be duplicated');

    awpt_remove_domain_test_directory($root);
}

test_pattern_structure_completer_restores_only_missing_dependency();
