<?php

/** Atomic block batch and presentation preservation. @package AWPT */

declare(strict_types=1);

use AWPT\Database\ActionContentPayloadSanitizer;
use AWPT\Domain\CompositionProposalGuard;
use AWPT\Domain\ExistingContentPreservationValidator;
use AWPT\Support\BlockBatchUpdater;
use AWPT\Support\BlockTree;

function test_block_batch_updates_multiple_paths_atomically(): void {
    $content =
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":4} --><h4>Question one?</h4><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Answer one.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":4} --><h4>Question two?</h4><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>A:</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Answer two.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $tree = BlockTree::from_content($content);
    $first = $tree->get_block('0.0');
    $second = $tree->get_block('1.0');
    $label = $tree->get_block('1.1');
    $result = new BlockBatchUpdater()->apply($content, [
        [
            'kind' => 'update_attrs',
            'block_path' => '0.0',
            'expected_fingerprint' => BlockTree::fingerprint($first ?? []),
            'attrs' => ['level' => 2],
        ],
        [
            'kind' => 'update_attrs',
            'block_path' => '1.0',
            'expected_fingerprint' => BlockTree::fingerprint($second ?? []),
            'attrs' => ['level' => 2],
        ],
        [
            'kind' => 'remove',
            'block_path' => '1.1',
            'expected_fingerprint' => BlockTree::fingerprint($label ?? []),
        ],
    ]);

    Assert::false(is_wp_error($result), 'a verified multi-path batch should succeed');
    Assert::true(str_contains((string) ($result['content'] ?? ''), '<h2>Question one?</h2>'), 'first heading changes');
    Assert::true(str_contains((string) ($result['content'] ?? ''), '<h2>Question two?</h2>'), 'second heading changes');
    Assert::false(str_contains((string) ($result['content'] ?? ''), '<p>A:</p>'), 'deep removal is included');
    Assert::true(str_contains((string) ($result['content'] ?? ''), 'Answer two.'), 'surrounding answer survives');
    $updated_group = BlockTree::from_content((string) ($result['content'] ?? ''))->get_block('1');
    $inner_blocks = is_array($updated_group['innerBlocks'] ?? null) ? $updated_group['innerBlocks'] : [];
    $inner_content = is_array($updated_group['innerContent'] ?? null) ? $updated_group['innerContent'] : [];
    Assert::same(
        count($inner_blocks),
        count(array_filter($inner_content, static fn(mixed $part): bool => null === $part)),
        'nested removal keeps parent innerContent placeholders aligned',
    );
    Assert::same(
        'Staged 3 verified block changes: 2 attribute updates, 1 block removal.',
        new BlockBatchUpdater()->describe($result['changes'] ?? []),
        'the action description is derived from the operations actually staged',
    );
}

function test_block_batch_rejects_one_stale_target_without_partial_output(): void {
    $content = '<!-- wp:paragraph --><p>Keep me.</p><!-- /wp:paragraph -->';
    $result = new BlockBatchUpdater()->apply($content, [[
        'kind' => 'update_attrs',
        'block_path' => '0',
        'expected_fingerprint' => str_repeat('0', 64),
        'attrs' => ['fontSize' => 'large'],
    ]]);

    Assert::instanceOf(WP_Error::class, $result, 'a stale member rejects the entire batch');
    Assert::same('awpt_block_fingerprint_mismatch', $result->get_error_code(), 'stale error remains actionable');
    Assert::same(
        BlockTree::fingerprint(BlockTree::from_content($content)->get_block('0') ?? []),
        $result->get_error_data()['current_fingerprint'] ?? '',
        'stale errors return the exact fingerprint needed for a corrected retry',
    );
}

function test_block_batch_updates_attrs_and_text_on_one_path_atomically(): void {
    $content = '<!-- wp:heading {"level":4} --><h4>Coverage' . "\x07" . ' Questions</h4><!-- /wp:heading -->';
    $heading = BlockTree::from_content($content)->get_block('0');
    $result = new BlockBatchUpdater()->apply($content, [[
        'kind' => 'update_block',
        'block_path' => '0',
        'expected_fingerprint' => BlockTree::fingerprint($heading ?? []),
        'attrs' => ['level' => 2],
        'content' => 'Coverage Questions',
    ]]);

    Assert::false(is_wp_error($result), 'one verified mutation may combine block attrs and rich text');
    Assert::true(
        str_contains((string) ($result['content'] ?? ''), '<h2>Coverage Questions</h2>'),
        'the heading tag and text should change together',
    );
    Assert::same(
        'Staged 1 verified block change: 1 combined block update.',
        new BlockBatchUpdater()->describe($result['changes'] ?? []),
        'the action description should identify a combined update',
    );
}

function test_block_batch_rejects_split_attr_and_text_mutations_on_one_path(): void {
    $content = '<!-- wp:heading {"level":4} --><h4>Coverage Questions</h4><!-- /wp:heading -->';
    $heading = BlockTree::from_content($content)->get_block('0');
    $fingerprint = BlockTree::fingerprint($heading ?? []);
    $result = new BlockBatchUpdater()->apply($content, [
        [
            'kind' => 'update_attrs',
            'block_path' => '0',
            'expected_fingerprint' => $fingerprint,
            'attrs' => ['level' => 2],
        ],
        [
            'kind' => 'replace_text',
            'block_path' => '0',
            'expected_fingerprint' => $fingerprint,
            'content' => 'Updated coverage questions',
        ],
    ]);

    Assert::instanceOf(WP_Error::class, $result, 'split non-insertion mutations remain invalid');
    Assert::same('awpt_invalid_block_batch_target', $result->get_error_code(), 'the conflict is explicit');
    Assert::true(
        str_contains($result->get_error_message(), 'kind set'),
        'the validation error tells the agent how to recover',
    );
}

function test_block_batch_can_insert_ordered_structure_with_other_verified_changes(): void {
    $content =
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":4} --><h4>Question?</h4><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>A:</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Preserved answer.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
    $tree = BlockTree::from_content($content);
    $group = $tree->get_block('0');
    $question = $tree->get_block('0.0');
    $label = $tree->get_block('0.1');
    $anchor = BlockTree::fingerprint($group ?? []);
    $result = new BlockBatchUpdater()->apply($content, [
        [
            'kind' => 'insert',
            'block_path' => '0',
            'expected_fingerprint' => $anchor,
            'position' => 'before',
            'block_name' => 'core/heading',
            'attrs' => ['level' => 1],
            'inner_html' => '<h1>Filing Procedures</h1>',
        ],
        [
            'kind' => 'insert',
            'block_path' => '0',
            'expected_fingerprint' => $anchor,
            'position' => 'before',
            'block_name' => 'core/paragraph',
            'inner_html' => '<p>Use this guide to find filing answers.</p>',
        ],
        [
            'kind' => 'insert',
            'block_path' => '0',
            'expected_fingerprint' => $anchor,
            'position' => 'before',
            'block_name' => 'core/heading',
            'attrs' => ['level' => 2],
            'inner_html' => '<h2>Filing Basics</h2>',
        ],
        [
            'kind' => 'update_attrs',
            'block_path' => '0.0',
            'expected_fingerprint' => BlockTree::fingerprint($question ?? []),
            'attrs' => ['level' => 3],
        ],
        [
            'kind' => 'remove',
            'block_path' => '0.1',
            'expected_fingerprint' => BlockTree::fingerprint($label ?? []),
        ],
    ]);

    Assert::false(is_wp_error($result), 'verified insertions should compose atomically with updates and removals');
    $updated = (string) ($result['content'] ?? '');
    Assert::true(
        strpos($updated, '<h1>Filing Procedures</h1>') < strpos(
            $updated,
            '<p>Use this guide to find filing answers.</p>',
        )
        && strpos($updated, '<p>Use this guide to find filing answers.</p>') < strpos(
            $updated,
            '<h2>Filing Basics</h2>',
        )
        && strpos($updated, '<h2>Filing Basics</h2>') < strpos($updated, '<h3>Question?</h3>'),
        'multiple insertions at one anchor should preserve provider order',
    );
    Assert::false(str_contains($updated, '<p>A:</p>'), 'the coordinated removal should also apply');
    Assert::same(
        'Staged 5 verified block changes: 1 attribute update, 1 block removal, 3 block insertions.',
        new BlockBatchUpdater()->describe($result['changes'] ?? []),
        'the canonical description should include insertions',
    );
}

function test_block_batch_replaces_legacy_list_and_embedded_image_html_atomically(): void {
    awpt_test_reset_state();
    $content =
        '<!-- wp:paragraph --><p><strong>Bulletin <a href="/bulletin-1379.pdf">#1379</a></strong></p><!-- /wp:paragraph -->'
        . '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":2} --><h2>Digital signatures?</h2><!-- /wp:heading -->'
        . '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:list {"ordered":true} --><ol><li>Keep one.</li><li>See <a href="https://example.test/rules" target="_blank">https://example.test/rules</a>.</li></ol><!-- /wp:list -->'
        . '<!-- wp:paragraph --><p><img src="/adobe.png" alt=""></p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p><img src="/cert.png" alt=""></p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p><img src="/docusign.png" alt=""></p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $tree = BlockTree::from_content($content);
    $targets = ['1.0', '1.1.0', '1.1.1', '1.1.2', '1.1.3'];
    $fingerprints = [];

    foreach ($targets as $path) {
        $fingerprints[$path] = BlockTree::fingerprint($tree->get_block($path) ?? []);
    }

    $result = new BlockBatchUpdater()->apply($content, [
        [
            'kind' => 'remove',
            'block_path' => '1.0',
            'expected_fingerprint' => $fingerprints['1.0'],
        ],
        [
            'kind' => 'replace_inner_html',
            'block_path' => '1.1.0',
            'expected_fingerprint' => $fingerprints['1.1.0'],
            'inner_html' => '<ol><li>Keep one.</li><li>See <a href="https://example.test/rules" target="_blank">their website</a>.</li></ol>',
        ],
        [
            'kind' => 'replace_inner_html',
            'block_path' => '1.1.1',
            'expected_fingerprint' => $fingerprints['1.1.1'],
            'inner_html' => '<p><img src="/adobe.png" alt="Example Adobe signature"></p>',
        ],
        [
            'kind' => 'replace_inner_html',
            'block_path' => '1.1.2',
            'expected_fingerprint' => $fingerprints['1.1.2'],
            'inner_html' => '<p><img src="/cert.png" alt="Example certificate timestamp"></p>',
        ],
        [
            'kind' => 'replace_inner_html',
            'block_path' => '1.1.3',
            'expected_fingerprint' => $fingerprints['1.1.3'],
            'inner_html' => '<p><img src="/docusign.png" alt="Example DocuSign signature"></p>',
        ],
    ]);

    Assert::false(is_wp_error($result), 'legacy saved markup edits should stage as one atomic batch');
    $updated = (string) ($result['content'] ?? '');
    Assert::false(str_contains($updated, '<h2>Digital signatures?</h2>'), 'duplicate heading is removed');
    Assert::true(str_contains($updated, '>their website</a>'), 'legacy list anchor text changes');
    Assert::true(
        str_contains($updated, 'href="https://example.test/rules" target="_blank"'),
        'list link attributes survive',
    );
    Assert::true(str_contains($updated, 'src="/adobe.png" alt="Example Adobe signature"'), 'first image alt changes');
    Assert::true(
        str_contains($updated, 'src="/cert.png" alt="Example certificate timestamp"'),
        'second image alt changes',
    );
    Assert::true(
        str_contains($updated, 'src="/docusign.png" alt="Example DocuSign signature"'),
        'third image alt changes',
    );
    Assert::true(str_contains($updated, '/bulletin-1379.pdf'), 'unrelated bulletin link survives');
    Assert::same(
        'Staged 5 verified block changes: 4 saved HTML replacements, 1 block removal.',
        new BlockBatchUpdater()->describe($result['changes'] ?? []),
        'action description reflects saved HTML operations',
    );
}

function test_block_batch_rejects_unsafe_or_structural_inner_html_replacement(): void {
    awpt_test_reset_state();
    $content = '<!-- wp:paragraph --><p>Keep me.</p><!-- /wp:paragraph -->';
    $block = BlockTree::from_content($content)->get_block('0');
    $fingerprint = BlockTree::fingerprint($block ?? []);

    $wrapper = new BlockBatchUpdater()->apply($content, [[
        'kind' => 'replace_inner_html',
        'block_path' => '0',
        'expected_fingerprint' => $fingerprint,
        'inner_html' => '<div>Changed.</div>',
    ]]);
    Assert::false(is_wp_error($wrapper), 'wrong outer tag is re-wrapped to the live leaf');
    Assert::true(
        is_array($wrapper) && str_contains((string) ($wrapper['content'] ?? ''), '<p>Changed.</p>'),
        'div body lands in p',
    );

    $fragment = new BlockBatchUpdater()->apply($content, [[
        'kind' => 'replace_inner_html',
        'block_path' => '0',
        'expected_fingerprint' => $fingerprint,
        'inner_html' => '<strong>AUDIT CHAIR</strong>',
    ]]);
    Assert::false(is_wp_error($fragment), 'inline fragment is wrapped to match the leaf');
    Assert::true(
        is_array($fragment)
        && str_contains((string) ($fragment['content'] ?? ''), '<p><strong>AUDIT CHAIR</strong></p>'),
        'strong fragment wrapped in p',
    );
    $unsafe = new BlockBatchUpdater()->apply($content, [[
        'kind' => 'replace_inner_html',
        'block_path' => '0',
        'expected_fingerprint' => $fingerprint,
        'inner_html' => '<p><script>alert(1)</script>Changed.</p>',
    ]]);
    Assert::instanceOf(WP_Error::class, $unsafe, 'unsafe saved markup is rejected');
    Assert::same('awpt_block_inner_html_unsafe', $unsafe->get_error_code(), 'unsafe markup error is explicit');

    $delimiter = new BlockBatchUpdater()->apply($content, [[
        'kind' => 'replace_inner_html',
        'block_path' => '0',
        'expected_fingerprint' => $fingerprint,
        'inner_html' => '<p><!-- wp:image --><img src="/nested.png"><!-- /wp:image --></p>',
    ]]);
    Assert::instanceOf(WP_Error::class, $delimiter, 'nested Gutenberg delimiters are rejected');
    Assert::same(
        'awpt_block_inner_html_contains_blocks',
        $delimiter->get_error_code(),
        'nested delimiter error is explicit',
    );

    $siblings = new BlockBatchUpdater()->apply($content, [[
        'kind' => 'replace_inner_html',
        'block_path' => '0',
        'expected_fingerprint' => $fingerprint,
        'inner_html' => '<p>First.</p><p>Second.</p>',
    ]]);
    Assert::instanceOf(WP_Error::class, $siblings, 'multiple outer wrappers are rejected');
    Assert::same(
        'awpt_block_inner_html_wrapper_mismatch',
        $siblings->get_error_code(),
        'multiple wrapper error is explicit',
    );

    $oversized = new BlockBatchUpdater()->apply($content, [[
        'kind' => 'replace_inner_html',
        'block_path' => '0',
        'expected_fingerprint' => $fingerprint,
        'inner_html' => '<p>' . str_repeat('x', 20_001) . '</p>',
    ]]);
    Assert::instanceOf(WP_Error::class, $oversized, 'oversized saved markup is rejected');
    Assert::same('awpt_block_inner_html_too_large', $oversized->get_error_code(), 'size error is explicit');
}

function test_block_batch_rejects_unregistered_attribute_for_registered_block(): void {
    awpt_test_reset_state();
    WP_Block_Type_Registry::get_instance()->register('core/paragraph', [
        'attributes' => ['align' => ['type' => 'string']],
    ]);
    $content = '<!-- wp:paragraph --><p><img src="/example.png" alt=""></p><!-- /wp:paragraph -->';
    $block = BlockTree::from_content($content)->get_block('0');
    $result = new BlockBatchUpdater()->apply($content, [[
        'kind' => 'update_attrs',
        'block_path' => '0',
        'expected_fingerprint' => BlockTree::fingerprint($block ?? []),
        'attrs' => ['alt' => 'Not a paragraph attribute'],
    ]]);

    Assert::instanceOf(WP_Error::class, $result, 'registered blocks reject unknown attrs');
    Assert::same('awpt_unknown_block_attribute', $result->get_error_code(), 'unknown attr error is actionable');
    awpt_test_reset_state();
}

function test_action_payload_keeps_saved_html_batch_metadata(): void {
    $clean = new ActionContentPayloadSanitizer()->sanitize([], [
        'batch_changes' => [[
            'kind' => 'replace_inner_html',
            'block_path' => '1.1.3',
            'expected_fingerprint' => hash('sha256', 'legacy-list'),
            'block_name' => 'core/list',
            'inner_html' => '<ol><li>Updated</li></ol>',
        ]],
    ]);

    Assert::same('replace_inner_html', $clean['batch_changes'][0]['kind'] ?? '', 'saved HTML kind is retained');
    Assert::same(
        '<ol><li>Updated</li></ol>',
        $clean['batch_changes'][0]['inner_html'] ?? '',
        'saved HTML metadata is retained',
    );
}

function test_block_batch_can_insert_then_update_the_same_verified_anchor(): void {
    $content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Form fields.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
    $group = BlockTree::from_content($content)->get_block('0');
    $fingerprint = BlockTree::fingerprint($group ?? []);
    $result = new BlockBatchUpdater()->apply($content, [
        [
            'kind' => 'insert',
            'block_path' => '0',
            'expected_fingerprint' => $fingerprint,
            'position' => 'before',
            'block_name' => 'core/heading',
            'attrs' => ['level' => 2],
            'inner_html' => '<h2>Contact Information</h2>',
        ],
        [
            'kind' => 'update_attrs',
            'block_path' => '0',
            'expected_fingerprint' => $fingerprint,
            'attrs' => ['backgroundColor' => 'base-minus-3'],
        ],
    ]);

    Assert::false(is_wp_error($result), 'an insertion and attribute update may share one verified anchor');
    Assert::true(
        str_contains((string) ($result['content'] ?? ''), '<h2>Contact Information</h2>'),
        'the anchored heading should be inserted',
    );
    Assert::true(
        str_contains((string) ($result['content'] ?? ''), '"backgroundColor":"base-minus-3"'),
        'the original anchor should still receive its attribute update',
    );
}

function test_presentation_preservation_blocks_loss_but_allows_structure_only_changes(): void {
    $before =
        '<!-- wp:paragraph --><p>California filing section 1760.5 requires 75 transactions. '
        . '<a href="https://example.test/rule">Read the rule</a> and retain this substantive explanation.</p><!-- /wp:paragraph -->';
    $lossy = '<!-- wp:paragraph --><p>California filing guidance.</p><!-- /wp:paragraph -->';
    $validator = new ExistingContentPreservationValidator();

    Assert::same(
        null,
        $validator->validate('Make this page more presentable.', $before, $lossy),
        'default redesign allows lossy restructure without strict preservation',
    );
    Assert::instanceOf(
        WP_Error::class,
        $validator->validate_required('Use a page layout.', $before, $lossy),
        'a whole-document pattern adaptation requires preservation by default',
    );

    $error = $validator->validate(
        'Make this page more presentable. Preserve all existing content and links.',
        $before,
        $lossy,
    );

    Assert::instanceOf(WP_Error::class, $error, 'strict preservation language still blocks content loss');
    Assert::same('awpt_presentation_content_loss', $error->get_error_code(), 'loss gets a dedicated error');
    $recommendation = $error->get_error_data()['recommended_next_tools'][0] ?? [];
    Assert::same(
        'awpt/propose-block-batch-update',
        $recommendation['tool'] ?? '',
        'content-loss feedback should still suggest the batch path',
    );
    Assert::false(
        array_key_exists('input', $recommendation) && 0 === (int) ($recommendation['input']['post_id'] ?? null),
        'recommended_next_tools must not send a junk post_id of 0',
    );
    Assert::same(
        null,
        $validator->validate('Preserve all content while restyling.', $before, $before),
        'structure-preserving content passes under strict mode',
    );
    Assert::same(
        null,
        $validator->validate('Shorten and summarize this page. Preserve all wording.', $before, $lossy),
        'explicit reduction still bypasses strict presentation preservation',
    );
}

function test_presentation_preservation_keeps_unique_short_imported_labels(): void {
    $paragraph =
        '<!-- wp:paragraph --><p>'
        . str_repeat('Complete the speaker request with event contact details and scheduling information. ', 12)
        . '</p><!-- /wp:paragraph -->';
    $label = '<!-- wp:html --><div>Virtual or In-Person?</div><!-- /wp:html -->';
    $validator = new ExistingContentPreservationValidator();
    $error = $validator->validate(
        'Improve this page but preserve all content including every label.',
        $paragraph . $label,
        $paragraph,
    );

    Assert::instanceOf(WP_Error::class, $error, 'strict mode still catches deleted short labels');
    Assert::same(
        ['Virtual or In-Person?'],
        $error->get_error_data()['missing_short_fragments'] ?? [],
        'the correction feedback should name the omitted label',
    );
    Assert::same(
        null,
        $validator->validate(
            'Improve this page but preserve all content.',
            $paragraph . '<!-- wp:paragraph --><p>A:</p><!-- /wp:paragraph -->',
            $paragraph,
        ),
        'non-substantive one-token answer markers may still be cleaned up',
    );
}

function test_presentation_h1_requirement_fails_closed_when_rendered_title_is_missing(): void {
    $guard = new CompositionProposalGuard();
    $missing = $guard->prepare([
        'operation' => 'content_update',
        'post_type' => 'page',
        'post_content' => '<!-- wp:heading --><h2>Filing Basics</h2><!-- /wp:heading -->',
        'presentation_requires_h1' => true,
    ], 'edit');

    Assert::instanceOf(WP_Error::class, $missing, 'a grounded missing-title requirement must block H2-only output');
    Assert::same('awpt_required_page_h1_missing', $missing->get_error_code(), 'the correction is actionable');
    Assert::false(
        is_wp_error($guard->prepare([
            'operation' => 'content_update',
            'post_type' => 'page',
            'post_content' => '<!-- wp:heading {"level":1} --><h1>Filing Procedures</h1><!-- /wp:heading -->',
            'presentation_requires_h1' => true,
        ], 'edit')),
        'exactly one content H1 satisfies the rendered requirement',
    );
    $duplicate = $guard->prepare([
        'operation' => 'content_update',
        'post_type' => 'page',
        'post_content' => implode('', [
            '<!-- wp:heading {"level":1} --><h1>Service of Suit</h1><!-- /wp:heading -->',
            '<!-- wp:heading --><h2>Service of Suit</h2><!-- /wp:heading -->',
        ]),
        'presentation_requires_h1' => true,
    ], 'edit');
    Assert::instanceOf(WP_Error::class, $duplicate, 'a page-local title must not be repeated as a section heading');
    Assert::same('awpt_duplicate_page_heading', $duplicate->get_error_code(), 'duplicate heading recovery is explicit');

    $skipped = $guard->prepare([
        'operation' => 'content_update',
        'post_type' => 'page',
        'post_content' => implode('', [
            '<!-- wp:heading {"level":1} --><h1>Insurers</h1><!-- /wp:heading -->',
            '<!-- wp:heading {"level":3} --><h3>What is surplus line insurance?</h3><!-- /wp:heading -->',
        ]),
        'presentation_requires_h1' => true,
    ], 'edit');
    Assert::instanceOf(WP_Error::class, $skipped, 'a presentation overhaul must not skip from H1 to H3');
    Assert::same('awpt_heading_level_skipped', $skipped->get_error_code(), 'heading skips get an actionable error');
    Assert::false(
        is_wp_error($guard->prepare([
            'operation' => 'content_update',
            'post_type' => 'page',
            'post_content' => implode('', [
                '<!-- wp:heading {"level":1} --><h1>Insurers</h1><!-- /wp:heading -->',
                '<!-- wp:heading --><h2>Frequently asked questions</h2><!-- /wp:heading -->',
                '<!-- wp:heading {"level":3} --><h3>What is surplus line insurance?</h3><!-- /wp:heading -->',
            ]),
            'presentation_requires_h1' => true,
        ], 'edit')),
        'a sequential H1/H2/H3 outline should remain valid',
    );
}

function test_block_batch_insert_materializes_content_and_rejects_empty_text_blocks(): void {
    $content = '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->';
    $anchor = BlockTree::from_content($content)->get_block('0');
    $fingerprint = BlockTree::fingerprint($anchor ?? []);
    $inserted = new BlockBatchUpdater()->apply($content, [[
        'kind' => 'insert',
        'block_path' => '0',
        'expected_fingerprint' => $fingerprint,
        'position' => 'before',
        'block_name' => 'core/heading',
        'attrs' => ['level' => 1],
        'content' => 'Verified page title',
    ]]);

    Assert::false(is_wp_error($inserted), 'heading insert accepts compact content');
    Assert::true(
        str_contains((string) ($inserted['content'] ?? ''), '<h1>Verified page title</h1>'),
        'server materializes semantic heading markup',
    );

    $empty = new BlockBatchUpdater()->apply($content, [[
        'kind' => 'insert',
        'block_path' => '0',
        'expected_fingerprint' => $fingerprint,
        'position' => 'before',
        'block_name' => 'core/heading',
        'attrs' => ['level' => 1],
    ]]);
    Assert::true(is_wp_error($empty), 'empty content-bearing insert fails before composition validation');
    if (is_wp_error($empty)) {
        Assert::same('awpt_block_insert_content_required', $empty->get_error_code(), 'empty insert code is precise');
        Assert::true(
            is_array($empty->get_error_data()['retry_example']['changes'] ?? null),
            'empty insert includes a complete retry shape',
        );
    }
}

test_block_batch_updates_multiple_paths_atomically();
test_block_batch_rejects_one_stale_target_without_partial_output();
test_block_batch_updates_attrs_and_text_on_one_path_atomically();
test_block_batch_rejects_split_attr_and_text_mutations_on_one_path();
test_block_batch_replaces_legacy_list_and_embedded_image_html_atomically();
test_block_batch_rejects_unsafe_or_structural_inner_html_replacement();
test_block_batch_rejects_unregistered_attribute_for_registered_block();
test_action_payload_keeps_saved_html_batch_metadata();
test_block_batch_can_insert_ordered_structure_with_other_verified_changes();
test_block_batch_can_insert_then_update_the_same_verified_anchor();
test_presentation_preservation_blocks_loss_but_allows_structure_only_changes();
test_presentation_preservation_keeps_unique_short_imported_labels();
test_presentation_h1_requirement_fails_closed_when_rendered_title_is_missing();
test_block_batch_set_kind_updates_attrs_and_strips_wrapping_block_comments();
test_block_batch_insert_materializes_content_and_rejects_empty_text_blocks();

function test_block_batch_set_kind_updates_attrs_and_strips_wrapping_block_comments(): void {
    $content =
        '<!-- wp:heading {"level":4} --><h4>Where can I view videos?</h4><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Accounting Services Bureau State of California</p><!-- /wp:paragraph -->';
    $tree = BlockTree::from_content($content);
    $heading = $tree->get_block('0');
    $paragraph = $tree->get_block('1');
    $result = new BlockBatchUpdater()->apply($content, [
        [
            'kind' => 'set',
            'block_path' => '0',
            'expected_fingerprint' => BlockTree::fingerprint($heading ?? []),
            'attrs' => ['level' => 2],
        ],
        [
            'kind' => 'set',
            'path' => '1',
            'fingerprint' => BlockTree::fingerprint($paragraph ?? []),
            'html' => "<!-- wp:paragraph -->\n<p>Accounting Services Bureau<br>State of California</p>\n<!-- /wp:paragraph -->",
        ],
    ]);

    Assert::false(is_wp_error($result), 'set should accept attrs and wrapping-comment html');
    Assert::true(
        str_contains((string) ($result['content'] ?? ''), '<h2>Where can I view videos?</h2>'),
        'heading level set',
    );
    Assert::true(
        str_contains((string) ($result['content'] ?? ''), 'Bureau<br>State'),
        'address line breaks are applied after stripping block comments',
    );
}
