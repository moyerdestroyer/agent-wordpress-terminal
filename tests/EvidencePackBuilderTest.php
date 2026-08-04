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
