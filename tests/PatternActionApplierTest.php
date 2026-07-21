<?php

/**
 * Tests replaying a staged pattern insertion at apply time.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Abilities\ActionAppliers\BlockStructureUpdateActionApplier;
use AWPT\Support\ActionOperations;

function test_pattern_action_applier_rebuilds_current_post_content(): void {
    awpt_test_reset_state();
    awpt_test_post(71, 'Landing page', 'landing', 'page', 'publish');
    $GLOBALS['awpt_test_posts'][71]->post_content = '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->';

    $content = new BlockStructureUpdateActionApplier()->content_from_payload(71, [
        'operation' => ActionOperations::PATTERN_INSERT,
        'block_path' => '',
        'position' => 'append',
        'blocks' => [[
            'blockName' => 'core/group',
            'attrs' => [],
            'innerHTML' => '',
            'innerBlocks' => [[
                'blockName' => 'core/paragraph',
                'attrs' => [],
                'innerHTML' => '<p>Pattern body</p>',
                'innerBlocks' => [],
                'innerContent' => ['<p>Pattern body</p>'],
            ]],
            'innerContent' => [null],
        ]],
    ]);

    Assert::false(is_wp_error($content), 'a staged pattern action should rebuild from current post content');

    if (!is_wp_error($content)) {
        Assert::true(str_contains($content, '<p>Intro</p>'), 'existing content should remain in the rebuilt post');
        Assert::true(str_contains($content, '<p>Pattern body</p>'), 'nested pattern content should be applied');
    }
}

test_pattern_action_applier_rebuilds_current_post_content();
