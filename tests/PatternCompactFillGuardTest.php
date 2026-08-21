<?php

/**
 * PatternCompactFillGuard and empty-replace gate contracts.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\ProposePatternReplace;
use AWPT\Agent\ProposalFailureNormalizer;
use AWPT\Domain\PatternCompactFillGuard;

function test_pattern_compact_fill_guard_matrix(): void {
    $guard = new PatternCompactFillGuard();
    $pattern =
        '<!-- wp:heading --><h2>Section heading (h2)</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Instructional filler about body copy.</p><!-- /wp:paragraph -->';

    $substantive = [
        'heading' => 'How do I create a renewal?',
        'excerpt' => 'Detailed FAQ answer about renewals and endorsements on SLIP.',
        'links' => ['https://example.com/help'],
        'numeric_tokens' => [],
    ];
    $empty_target = [
        'heading' => '',
        'excerpt' => 'Short',
        'links' => [],
        'numeric_tokens' => [],
    ];

    Assert::true($guard->should_block_replace([
        'pattern_content' => $pattern,
        'carry_forward' => $substantive,
    ], []), 'empty updates + slots + substantive target should block');
    Assert::false(
        $guard->should_block_replace([
            'pattern_content' => $pattern,
            'carry_forward' => $substantive,
        ], [['block_path' => '0', 'content' => 'Mapped FAQ heading']]),
        'non-empty updates should allow',
    );
    Assert::false($guard->should_block_replace([
        'pattern_content' => $pattern,
        'carry_forward' => $empty_target,
    ], []), 'empty/shell targets should allow bare pattern');
    Assert::false($guard->should_block_replace([
        'pattern_content' => '<!-- wp:image {"id":1} --><figure></figure><!-- /wp:image -->',
        'carry_forward' => $substantive,
    ], []), 'patterns with zero text slots should allow');
    Assert::true($guard->target_is_substantive($substantive), 'FAQ-like carry_forward is substantive');
    Assert::false($guard->target_is_substantive($empty_target), 'short empty carry_forward is not');
}

function test_propose_pattern_replace_fill_required_error_shape(): void {
    awpt_test_reset_state();
    $pattern =
        '<!-- wp:heading --><h2>Section heading (h2)</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>The page heading communicates the main focus of the page.</p><!-- /wp:paragraph -->';
    $carry_forward = [
        'heading' => 'How do I create a renewal?',
        'excerpt' => 'See https://example.com/help for steps about renewals.',
        'links' => ['https://example.com/help'],
        'numeric_tokens' => [],
    ];
    $receipt = [
        'preparation_id' => 'prep-fill-1',
        'pattern_content' => $pattern,
        'carry_forward' => $carry_forward,
    ];

    Assert::true(
        new PatternCompactFillGuard()->should_block_replace($receipt, []),
        'guard blocks bare replace for FAQ-like target',
    );

    $method = new ReflectionMethod(ProposePatternReplace::class, 'prepared_slot_error');
    $error = $method->invoke(
        new ProposePatternReplace(),
        new WP_Error('awpt_pattern_text_updates_required', 'Overwrite blocked.', ['status' => 409]),
        $receipt,
    );

    Assert::true($error instanceof WP_Error, 'recovery remains a WordPress error');
    if (!$error instanceof WP_Error) {
        return;
    }

    Assert::same('awpt_pattern_text_updates_required', $error->get_error_code(), 'fill-required code');
    $data = $error->get_error_data();
    Assert::true(is_array($data), 'error data array');
    Assert::same('prep-fill-1', $data['preparation_id'] ?? null, 'preparation_id echoed');
    Assert::true(
        is_array($data['editable_slots'] ?? null) && count($data['editable_slots']) > 0,
        'editable_slots present',
    );
    Assert::same('How do I create a renewal?', $data['carry_forward']['heading'] ?? null, 'carry_forward echoed');
    Assert::true(
        is_array($data['retry_example']['pattern_text_updates'] ?? null),
        'retry_example includes pattern_text_updates',
    );
    Assert::true(
        str_contains((string) ($data['recovery'] ?? ''), 'pattern_text_updates'),
        'recovery names pattern_text_updates',
    );

    $source = (string) file_get_contents(dirname(__DIR__) . '/src/Abilities/ProposePatternReplace.php');
    Assert::true(
        str_contains($source, 'PatternCompactFillGuard') && str_contains($source, 'awpt_pattern_text_updates_required'),
        'ProposePatternReplace wires the fill guard before staging',
    );
}

function test_proposal_failure_normalizer_maps_pattern_text_updates_required(): void {
    $normalized = ProposalFailureNormalizer::normalize(
        'awpt_pattern_text_updates_required',
        [
            'preparation_id' => 'prep-1',
            'editable_slots' => [['block_path' => '0', 'current_text' => 'Filler']],
            'carry_forward' => ['heading' => 'FAQ'],
            'recovery' => 'Retry with pattern_text_updates.',
            'retry_example' => [
                'preparation_id' => 'prep-1',
                'pattern_text_updates' => [['block_path' => '0', 'content' => 'FAQ']],
            ],
            'status' => 409,
        ],
        'Overwrite blocked.',
    );

    Assert::same('pattern_text_updates_required', $normalized[0]['id'] ?? '', 'constraint id');
    $facts = $normalized[0]['facts'] ?? [];
    Assert::same('prep-1', $facts['preparation_id'] ?? null, 'preparation_id fact');
    Assert::true(isset($facts['editable_slots']), 'editable_slots fact');
    Assert::true(isset($facts['carry_forward']), 'carry_forward fact');
    Assert::true(isset($facts['retry_example']), 'retry_example fact');
    Assert::true(
        str_contains(implode(' ', $normalized[0]['hints'] ?? []), 'preparation_id'),
        'hint tells model to reuse preparation_id',
    );
}

test_pattern_compact_fill_guard_matrix();
test_propose_pattern_replace_fill_required_error_shape();
test_proposal_failure_normalizer_maps_pattern_text_updates_required();
