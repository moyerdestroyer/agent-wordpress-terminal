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

test_evidence_pack_includes_patterns_and_media();
test_evidence_pack_provider_messages_are_compact();
