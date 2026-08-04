<?php

/** Exact targeted replacements for staged new-post revisions. @package AWPT */

declare(strict_types=1);

use AWPT\Abilities\ProposeNewPost;
use AWPT\Support\PostContentSanitizer;

function test_new_post_unwraps_whole_document_transport_envelopes(): void {
    $ability = new ProposeNewPost();
    $method = new ReflectionMethod(ProposeNewPost::class, 'unwrap_content_transport');
    $blocks = '<!-- wp:group --><div class="wp-block-group">Body</div><!-- /wp:group -->';

    Assert::same(
        $blocks,
        $method->invoke($ability, "<![CDATA[\n" . $blocks . "\n]]>"),
        'a whole-document CDATA envelope should be removed',
    );
    Assert::same(
        $blocks,
        $method->invoke($ability, "```html\n" . $blocks . "\n```"),
        'a whole-document Markdown fence should be removed',
    );
    Assert::same(
        '<p>Literal <![CDATA[text]]> remains</p>',
        $method->invoke($ability, '<p>Literal <![CDATA[text]]> remains</p>'),
        'inner literal text must not be rewritten',
    );
}

function test_existing_content_updates_unwrap_whole_document_transport_envelopes(): void {
    $blocks = '<!-- wp:heading --><h1>Annual Meeting</h1><!-- /wp:heading -->';

    Assert::same(
        $blocks,
        PostContentSanitizer::for_staged_update("<![CDATA[\n" . $blocks . "\n]]>"),
        'existing-page full-document updates should not persist a CDATA transport envelope',
    );
    Assert::same(
        '<p>Literal <![CDATA[text]]> remains</p>',
        PostContentSanitizer::for_staged_update('<p>Literal <![CDATA[text]]> remains</p>'),
        'inner literal CDATA text should remain ordinary content',
    );
    Assert::same(
        $blocks . "\n",
        PostContentSanitizer::for_staged_update($blocks . "\n"),
        'existing content without a transport envelope must remain byte-for-byte stable',
    );
}

function test_new_post_targeted_replacements_preserve_all_other_bytes(): void {
    $ability = new ProposeNewPost();
    $method = new ReflectionMethod(ProposeNewPost::class, 'apply_content_replacements');
    $source =
        '<!-- wp:heading --><h2>Schedule</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>6:30 PM — Pods form.</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Untouched.</p><!-- /wp:paragraph -->';
    $result = $method->invoke($ability, $source, [
        ['search' => '>Schedule<', 'replace' => '>Friday Night Schedule<'],
        [
            'search' => '<p>6:30 PM — Pods form.</p>',
            'replace' => '<p>6:30 PM — Pods form.</p><p>9:30 PM — Please help tidy your pod area.</p>',
        ],
    ]);

    Assert::true(is_string($result), 'exact replacements should return revised content');
    Assert::same(
        str_replace(
            ['>Schedule<', '<p>6:30 PM — Pods form.</p>'],
            ['>Friday Night Schedule<', '<p>6:30 PM — Pods form.</p><p>9:30 PM — Please help tidy your pod area.</p>'],
            $source,
        ),
        $result,
        'only the declared byte ranges should change',
    );
}

function test_new_post_targeted_replacements_fail_on_ambiguous_search(): void {
    $ability = new ProposeNewPost();
    $method = new ReflectionMethod(ProposeNewPost::class, 'apply_content_replacements');
    $result = $method->invoke($ability, '<p>same</p><p>same</p>', [
        ['search' => 'same', 'replace' => 'changed'],
    ]);

    Assert::same(
        'awpt_content_replacement_mismatch',
        is_wp_error($result) ? $result->get_error_code() : '',
        'ambiguous replacements must fail closed',
    );
}

test_new_post_unwraps_whole_document_transport_envelopes();
test_existing_content_updates_unwrap_whole_document_transport_envelopes();
test_new_post_targeted_replacements_preserve_all_other_bytes();
test_new_post_targeted_replacements_fail_on_ambiguous_search();

function test_new_post_page_template_inventory_does_not_require_admin_only_function(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_page_templates'] = ['templates/landing.html' => 'Landing'];
    $ability = new ProposeNewPost();
    $method = new ReflectionMethod(ProposeNewPost::class, 'available_page_templates');

    Assert::same(
        ['templates/landing.html' => 'Landing'],
        $method->invoke($ability),
        'REST proposal validation should read templates through WP_Theme when admin helpers are unavailable',
    );
}

test_new_post_page_template_inventory_does_not_require_admin_only_function();
