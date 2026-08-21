<?php

/**
 * PatternCarryForwardSlotFiller maps bad/missing updates onto editable slots.
 */

declare(strict_types=1);

use AWPT\Domain\PatternCarryForwardSlotFiller;
use AWPT\Domain\PatternEditableSlots;

function test_pattern_carry_forward_slot_filler_maps_required_slots(): void {
    $pattern =
        '<!-- wp:group -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Lead instructional filler for the page.</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading --><h2>Section heading (h2)</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Body instructional filler for the page.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';

    $slots = new PatternEditableSlots()->from_content($pattern);
    Assert::true(count($slots) >= 2, 'fixture exposes editable slots');

    $receipt = [
        'pattern_content' => $pattern,
        'carry_forward' => [
            'heading' => 'How do I create a renewal?',
            'excerpt' => 'You can create a renewal based on an existing policy stored in SLIP.',
            'links' => [],
            'numeric_tokens' => [],
        ],
    ];

    $filler = new PatternCarryForwardSlotFiller();
    $from_empty = $filler->resolve_updates($receipt, []);
    Assert::false(is_wp_error($from_empty), 'empty updates are allowed');
    Assert::true(is_array($from_empty) && count($from_empty) >= 1, 'empty updates synthesize from carry_forward');
    Assert::true(
        in_array('How do I create a renewal?', array_column($from_empty, 'content'), true)
        || str_contains(implode("\n", array_column($from_empty, 'content')), 'renewal'),
        'heading or excerpt lands in a slot',
    );

    $semantic = $filler->resolve_updates($receipt, [
        ['block_path' => 'intro_paragraph', 'content' => 'Find answers…'],
        ['block_path' => 'first_h2', 'content' => 'Frequently Asked Questions'],
    ]);
    Assert::true(is_wp_error($semantic), 'invented semantic paths are rejected');
    if (is_wp_error($semantic)) {
        Assert::same('awpt_pattern_text_path_invalid', $semantic->get_error_code(), 'path-invalid code');
        $data = $semantic->get_error_data();
        Assert::true(is_array($data) && isset($data['editable_slots']), 'editable_slots returned for retry');
    }

    $not_slot = $filler->resolve_updates($receipt, [
        ['block_path' => '0', 'content' => 'SLIP'],
    ]);
    Assert::true(is_wp_error($not_slot), 'numeric path outside editable_slots is rejected');

    $good_path = (string) ($slots[1]['block_path'] ?? $slots[0]['block_path'] ?? '');
    $by_path = $filler->resolve_updates($receipt, [
        ['block_path' => $good_path, 'content' => 'Mapped heading'],
    ]);
    Assert::false(is_wp_error($by_path), 'valid editable path accepted');
    Assert::same(1, is_array($by_path) ? count($by_path) : 0, 'valid editable path keeps model update');
    Assert::same('Mapped heading', is_array($by_path) ? $by_path[0]['content'] ?? null : null, 'model content kept');
    Assert::same($good_path, is_array($by_path) ? $by_path[0]['block_path'] ?? null : null, 'model path kept');
}

test_pattern_carry_forward_slot_filler_maps_required_slots();

function test_pattern_carry_forward_does_not_duplicate_excerpt_across_instructional_slots(): void {
    $pattern =
        '<!-- wp:group -->'
        . '<div class="wp-block-group">'
        . '<!-- wp:heading --><h2>Section heading (h2)</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Read the full documentation on our side navigation on the component page.</p><!-- /wp:paragraph -->'
        . '<!-- wp:heading {"level":3} --><h3>Subsection heading</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Keep each section and subsection focused.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Use the side navigation menu for related pages.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';

    $receipt = [
        'pattern_content' => $pattern,
        'carry_forward' => [
            'heading' => 'Next Generation',
            'excerpt' => 'Members Pam Quilici President, RSTUM Ryan Specialty',
            'links' => [],
            'numeric_tokens' => [],
        ],
    ];

    $updates = new PatternCarryForwardSlotFiller()->resolve_updates($receipt, []);
    Assert::false(is_wp_error($updates), 'resolve succeeds');
    Assert::true(is_array($updates), 'updates are a list');
    $bodies = [];
    foreach ($updates as $row) {
        $bodies[] = (string) ($row['content'] ?? '');
    }
    $joined = implode("\n", $bodies);
    Assert::false(str_contains($joined, 'component page'), 'instructional leftover cleared');
    $excerpt_hits = substr_count($joined, 'Members Pam Quilici');
    Assert::true($excerpt_hits <= 1, 'excerpt not stamped into every leftover slot');

    // Heading + excerpt must not both land the same roster text in lead and body.
    $receipt_both = [
        'pattern_content' => $pattern,
        'carry_forward' => [
            'heading' => 'Next Generation',
            'excerpt' => 'Members Pam Quilici President, RSTUM Ryan Specialty',
            'links' => [],
            'numeric_tokens' => [],
        ],
    ];
    $both = new PatternCarryForwardSlotFiller()->resolve_updates($receipt_both, []);
    Assert::false(is_wp_error($both), 'heading+excerpt resolve succeeds');
    $both_texts = is_array($both) ? array_column($both, 'content') : [];
    Assert::true(
        1 === count(array_filter($both_texts, static fn($t) => str_contains((string) $t, 'Members Pam Quilici'))),
        'roster excerpt appears in at most one mapped slot',
    );
}

test_pattern_carry_forward_does_not_duplicate_excerpt_across_instructional_slots();
