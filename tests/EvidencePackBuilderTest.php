<?php

/**
 * Evidence pack compaction for compose phase.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\EvidencePackBuilder;

function test_evidence_pack_includes_patterns_and_media(): void {
    $builder = new EvidencePackBuilder();
    $calls = [
        [
            'tool' => 'awpt/read-pattern',
            'status' => 'success',
            'input' => ['name' => 'theme/hero'],
            'output' => [
                'name' => 'theme/hero',
                'title' => 'Hero',
                'composition_scope' => 'layout',
                'content' => '<!-- wp:group -->pack<!-- /wp:group -->',
            ],
        ],
        [
            'tool' => 'awpt/list-content',
            'status' => 'success',
            'input' => ['post_type' => 'attachment'],
            'output' => [
                'items' => [
                    ['id' => 11, 'title' => 'Photo'],
                    ['id' => 12, 'title' => 'Photo 2'],
                ],
            ],
        ],
    ];

    $pack = $builder->pack($calls, ['pattern_structure', 'media_inventory'], 'ready');

    Assert::same(1, count($pack['patterns']), 'pattern reads should enter the pack');
    Assert::same('theme/hero', $pack['patterns'][0]['name'] ?? '', 'pattern name preserved');
    Assert::same(2, count($pack['media']), 'media inventory should enter the pack');
    Assert::true(
        str_contains((string) ($pack['patterns'][0]['content'] ?? ''), 'wp:group'),
        'pattern body kept for adapted mode',
    );
    Assert::same('ready', $pack['reason'], 'reason should be preserved');
}

function test_evidence_pack_provider_messages_are_compact(): void {
    $builder = new EvidencePackBuilder();
    $messages = [
        ['role' => 'system', 'content' => 'You are AWPT.'],
        ['role' => 'user', 'content' => 'old'],
        ['role' => 'assistant', 'content' => 'huge intermediate'],
    ];
    $calls = [
        [
            'tool' => 'awpt/read-pattern',
            'status' => 'success',
            'input' => [],
            'output' => [
                'name' => 'x',
                'content' => str_repeat('A', 500),
            ],
        ],
    ];

    $compact = $builder->provider_messages($messages, $calls, 'Create a page.', [
        'coverage' => ['pattern_structure'],
        'reason' => 'done',
        'mode' => 'compose',
    ]);

    Assert::same(3, count($compact), 'compose context should be system + user + evidence');
    Assert::true(
        str_contains((string) ($compact[0]['content'] ?? ''), 'Discovery is complete'),
        'compose system tail should force staging',
    );
    Assert::true(
        str_contains((string) ($compact[0]['content'] ?? ''), 'exactly one complete proposal tool call'),
        'compose system tail should require a single proposal tool call',
    );
    Assert::same('Create a page.', $compact[1]['content'] ?? '', 'user request preserved');
    Assert::true(
        str_contains((string) ($compact[2]['content'] ?? ''), 'Verified discovery evidence'),
        'evidence pack message present',
    );
}

function test_evidence_pack_caps_pattern_markup_for_finalization(): void {
    $builder = new EvidencePackBuilder();
    $calls = [];

    foreach (range(1, 3) as $number) {
        $calls[] = [
            'tool' => 'awpt/read-pattern',
            'status' => 'success',
            'input' => ['name' => 'theme/pattern-' . $number],
            'output' => [
                'name' => 'theme/pattern-' . $number,
                'content' => str_repeat((string) $number, 14_000),
            ],
        ];
    }

    $pack = $builder->pack($calls);

    Assert::same(2, count($pack['patterns']), 'finalization should receive only two pattern bodies');
    Assert::same(12_000, strlen((string) $pack['patterns'][0]['content']), 'pattern markup should be bounded');
}

function test_evidence_pack_preserves_compact_proposal_revision_paths(): void {
    $pack = new EvidencePackBuilder()->pack(
        [[
            'tool' => 'awpt/read-proposal',
            'status' => 'success',
            'input' => ['action_id' => 9],
            'output' => [
                'id' => 9,
                'session_id' => 21,
                'title' => 'Commander page',
                'status' => 'proposed',
                'payload' => ['post_title' => 'Commander News', 'post_type' => 'page'],
                'revision_context' => [
                    'mode' => 'path_updates',
                    'editable_slots' => [['block_path' => '2.0', 'current_text' => 'Commander versus Modern']],
                ],
            ],
        ]],
        ['proposal_revision'],
        'ready to revise',
    );

    $read = $pack['content_reads'][0]['output'] ?? [];
    Assert::same(9, $read['id'] ?? 0, 'the proposal identity should survive compose compaction');
    Assert::same(
        '2.0',
        $read['revision_context']['editable_slots'][0]['block_path'] ?? '',
        'path-addressed revision evidence should survive compose compaction',
    );
}

function test_evidence_pack_preserves_custom_fallback_media_and_visuals(): void {
    $visual = [
        [
            'type' => 'text',
            'text' => 'Attachment #5',
        ],
        [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:image/png;base64,aW1hZ2U='],
        ],
    ];
    $calls = [[
        'tool' => 'awpt/prepare-pattern-draft',
        'status' => 'success',
        'input' => ['intent' => 'bespoke page', 'media_count' => 1],
        'output' => [
            'mode' => 'custom_fallback',
            'intent' => 'bespoke page',
            'post_type' => 'page',
            'media' => [['id' => 5, 'media_url' => 'https://example.test/teferi.png']],
        ],
    ]];

    $builder = new EvidencePackBuilder();
    $pack = $builder->pack($calls);
    $messages = $builder->provider_messages(
        [
            ['role' => 'system', 'content' => 'System'],
            ['role' => 'user', 'content' => $visual],
        ],
        $calls,
        'Build it.',
    );

    Assert::same(5, $pack['media'][0]['id'] ?? 0, 'custom fallback media should survive evidence compaction');
    Assert::same(4, count($messages), 'fresh multimodal evidence should survive compose message compaction');
    Assert::same('image_url', $messages[3]['content'][1]['type'] ?? '', 'the visual candidate should remain available');
}

function test_evidence_pack_preserves_pattern_media_slots_for_composition(): void {
    $pack = new EvidencePackBuilder()->pack([[
        'tool' => 'awpt/prepare-pattern-draft',
        'status' => 'success',
        'input' => ['intent' => 'landing page with hero image', 'media_count' => 1],
        'output' => [
            'mode' => 'pattern',
            'intent' => 'landing page with hero image',
            'post_type' => 'page',
            'pattern' => [
                'name' => 'theme/landing',
                'pattern_names' => ['theme/landing'],
                'editable_slots' => [],
                'media_slots' => [[
                    'block_path' => '0',
                    'block_name' => 'core/cover',
                    'slot' => 'cover_background',
                    'occupied' => false,
                    'recommended_placement' => 'featured_cover',
                ]],
            ],
            'media' => [['id' => 47]],
        ],
    ]]);

    Assert::same(
        'featured_cover',
        $pack['patterns'][0]['media_slots'][0]['recommended_placement'] ?? '',
        'semantic media destinations must survive the clean compose context',
    );
}

test_evidence_pack_includes_patterns_and_media();
test_evidence_pack_provider_messages_are_compact();
test_evidence_pack_caps_pattern_markup_for_finalization();
test_evidence_pack_preserves_compact_proposal_revision_paths();
test_evidence_pack_preserves_custom_fallback_media_and_visuals();
test_evidence_pack_preserves_pattern_media_slots_for_composition();

function test_evidence_pack_includes_inspect_heading_brief_and_compacts_trees(): void {
    $deep = [];
    $fingerprints = [];

    for ($i = 0; $i < 80; ++$i) {
        $fp = hash('sha256', 'node-' . $i);
        $fingerprints[] = $fp;
        $deep[] = [
            'path' => (string) $i,
            'name' => 'core/group',
            'attributes' => ['layout' => ['type' => 'constrained'], 'style' => ['spacing' => ['padding' => '1rem']]],
            'attributes_summary' => ['layout' => '[1 items]'],
            'text_excerpt' => str_repeat('Answer text for FAQ item ' . $i . ' ', 20),
            'fingerprint' => $fp,
            'inner' => [[
                'path' => $i . '.0',
                'name' => 'core/paragraph',
                'attributes' => [],
                'attributes_summary' => [],
                'text_excerpt' => 'A:',
                'fingerprint' => hash('sha256', 'inner-' . $i),
                'inner' => [],
            ]],
        ];
        $fingerprints[] = hash('sha256', 'inner-' . $i);
    }

    $pack = new EvidencePackBuilder()->pack([
        [
            'tool' => 'awpt/analyze-page',
            'status' => 'success',
            'input' => ['id' => 550],
            'output' => [
                'title' => 'SLIP',
                'headings' => ['How do I create a renewal?'],
                'risk_level' => 'low',
                'plain_text' => str_repeat('faq ', 500),
                'block_tree' => $deep,
            ],
        ],
        [
            'tool' => 'awpt/read-block-tree',
            'status' => 'success',
            'input' => ['id' => 550],
            'output' => [
                'blocks' => $deep,
                'count' => 160,
                'path_format' => 'Dotted zero-based visible block path.',
            ],
        ],
        [
            'tool' => 'awpt/read-content',
            'status' => 'success',
            'input' => ['id' => 550],
            'output' => [
                'id' => 550,
                'title' => 'SLIP',
                'type' => 'page',
                'status' => 'publish',
                'url' => 'http://example.test/slip/',
                'content' => str_repeat('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', 200),
                'plain_text' => str_repeat('faq body ', 300),
            ],
        ],
        [
            'tool' => 'awpt/inspect-rendered-element',
            'status' => 'success',
            'input' => ['post_id' => 550],
            'output' => [
                'rendered' => false,
                'warning' => 'headless_browser_unavailable',
                'main_h1_count' => 0,
                'main_heading_outline' => [['level' => 4, 'text' => 'How do I create a renewal?']],
                'url' => 'http://example.test/slip/',
            ],
        ],
    ], ['page_analysis', 'rendered_inspection'], 'ready');

    Assert::same(0, $pack['page_brief']['main_h1_count'] ?? -1, 'inspect main_h1_count must survive packing');
    Assert::same('low', $pack['page_brief']['risk_level'] ?? '', 'analyze risk must survive packing');
    Assert::true(
        is_array($pack['page_brief']['main_heading_outline'] ?? null),
        'heading outline must be packed for H1 decisions',
    );

    $tree_reads = array_values(array_filter(
        $pack['content_reads'],
        static fn(array $read): bool => 'awpt/read-block-tree' === ($read['tool'] ?? ''),
    ));
    Assert::same(1, count($tree_reads), 'analyze tree must be deduped against read-block-tree');

    $packed_blocks = $tree_reads[0]['output']['blocks'] ?? [];
    $packed_json = (string) wp_json_encode($packed_blocks);
    Assert::true(strlen($packed_json) <= 26_000, 'block structure should stay near the evidence budget');

    $collected = [];
    $walk = static function (array $nodes) use (&$walk, &$collected): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['fingerprint'])) {
                $collected[] = (string) $node['fingerprint'];
            }
            if (is_array($node['inner'] ?? null)) {
                $walk($node['inner']);
            }
        }
    };
    $walk(is_array($packed_blocks) ? $packed_blocks : []);
    Assert::true(count($collected) >= 160, 'every fingerprint should remain addressable');

    $content_read = null;

    foreach ($pack['content_reads'] as $read) {
        if ('awpt/read-content' === ($read['tool'] ?? '')) {
            $content_read = $read['output'];
        }
    }

    Assert::true(is_array($content_read), 'content read should remain');
    Assert::false(
        array_key_exists('content', $content_read ?? []),
        'full HTML should be omitted when a block tree is already packed',
    );
}

test_evidence_pack_includes_inspect_heading_brief_and_compacts_trees();

function test_evidence_pack_synthesizes_fingerprints_when_analyze_tree_was_truncated(): void {
    awpt_test_reset_state();
    $post_id = 550;
    $post = new WP_Post();
    $post->ID = $post_id;
    $post->post_title = 'SLIP';
    $post->post_content = '<!-- wp:heading {"level":4} --><h4>How do I create a renewal?</h4><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>You can create a renewal from an existing policy.</p><!-- /wp:paragraph -->';
    $GLOBALS['awpt_test_posts'][$post_id] = $post;

    $pack = new EvidencePackBuilder()->pack(
        [
            [
                'tool' => 'awpt/analyze-page',
                'status' => 'success',
                'input' => ['id' => $post_id],
                'output' => [
                    'title' => 'SLIP',
                    'headings' => ['How do I create a renewal?'],
                    'risk_level' => 'low',
                    'plain_text' => 'How do I create a renewal?',
                    // Mimic provider-truncator storage before the storage-compact fix,
                    // and also the explore path that never kept a tree.
                ],
            ],
            [
                'tool' => 'awpt/inspect-rendered-element',
                'status' => 'success',
                'input' => ['post_id' => $post_id],
                'output' => [
                    'rendered' => false,
                    'main_h1_count' => 0,
                    'main_heading_outline' => [['level' => 4, 'text' => 'How do I create a renewal?']],
                ],
            ],
        ],
        ['page_analysis', 'rendered_inspection'],
        'ready',
        ['focus_post_id' => $post_id],
    );

    Assert::true(
        new EvidencePackBuilder()->has_block_fingerprints($pack),
        'compose pack must synthesize fingerprints when analyze left no tree',
    );

    $tree_reads = array_values(array_filter(
        $pack['content_reads'],
        static fn(array $read): bool => 'awpt/read-block-tree' === ($read['tool'] ?? ''),
    ));
    Assert::same(1, count($tree_reads), 'one synthesized tree entry');
    Assert::same('compose-synthesis', $tree_reads[0]['input']['source'] ?? '', 'source labels synthesis');
    Assert::same(64, strlen((string) ($tree_reads[0]['output']['blocks'][0]['fingerprint'] ?? '')), 'synthesized fingerprint length');
}

function test_evidence_pack_reuses_storage_compacted_analyze_tree(): void {
    $fp = hash('sha256', 'stored-root');
    $pack = new EvidencePackBuilder()->pack([
        [
            'tool' => 'awpt/analyze-page',
            'status' => 'success',
            'input' => ['id' => 12],
            'output' => [
                'title' => 'Page',
                'block_tree' => [[
                    'path' => '0',
                    'name' => 'core/paragraph',
                    'fingerprint' => $fp,
                    'inner' => [],
                ]],
            ],
        ],
        [
            'tool' => 'awpt/inspect-rendered-element',
            'status' => 'success',
            'input' => ['post_id' => 12],
            'output' => ['main_h1_count' => 1],
        ],
    ], ['page_analysis', 'rendered_inspection'], 'ready');

    $tree_reads = array_values(array_filter(
        $pack['content_reads'],
        static fn(array $read): bool => 'awpt/read-block-tree' === ($read['tool'] ?? ''),
    ));
    Assert::same(1, count($tree_reads), 'storage-compacted analyze tree should be reused');
    Assert::same('analyze-page', $tree_reads[0]['input']['source'] ?? '', 'source labels analyze reuse');
    Assert::same($fp, $tree_reads[0]['output']['blocks'][0]['fingerprint'] ?? '', 'stored fingerprint reused');
}

test_evidence_pack_synthesizes_fingerprints_when_analyze_tree_was_truncated();
test_evidence_pack_reuses_storage_compacted_analyze_tree();
