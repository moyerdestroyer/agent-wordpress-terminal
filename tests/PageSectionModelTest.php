<?php

/**
 * PageSectionModel outline / role heuristics.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PageSectionModel;

function test_page_section_model_roles_header_body_faq_query(): void {
    $content =
        '<!-- wp:group -->'
        . '<!-- wp:post-title /-->'
        . '<!-- wp:paragraph --><p>Site intro</p><!-- /wp:paragraph -->'
        . '<!-- /wp:group -->'
        . '<!-- wp:paragraph --><p>Main body copy about our services and history.</p><!-- /wp:paragraph -->'
        . '<!-- wp:group -->'
        . '<!-- wp:heading --><h2>FAQ</h2><!-- /wp:heading -->'
        . '<!-- wp:details --><details><summary>Question one?</summary><p>Answer</p></details><!-- /wp:details -->'
        . '<!-- /wp:group -->'
        . '<!-- wp:query -->'
        . '<!-- wp:post-template -->'
        . '<!-- wp:post-title /-->'
        . '<!-- /wp:post-template -->'
        . '<!-- /wp:query -->';

    $sections = PageSectionModel::from_content($content);
    Assert::same(4, count($sections), 'four top-level sections');

    Assert::same('0', $sections[0]['path'] ?? null, 'header path');
    Assert::same(PageSectionModel::ROLE_HEADER, $sections[0]['role'] ?? null, 'post-title group is header');
    Assert::true(64 === strlen((string) ($sections[0]['fingerprint'] ?? '')), 'header fingerprint');

    Assert::same(PageSectionModel::ROLE_BODY, $sections[1]['role'] ?? null, 'paragraph body');
    Assert::same(PageSectionModel::ROLE_FAQ, $sections[2]['role'] ?? null, 'FAQ group');
    Assert::true(str_contains(mb_strtolower((string) ($sections[2]['heading'] ?? '')), 'faq'), 'FAQ heading');

    Assert::same(PageSectionModel::ROLE_QUERY, $sections[3]['role'] ?? null, 'query is query role');
    Assert::true((bool) ($sections[3]['has_dynamic_blocks'] ?? false), 'query has dynamic');
    Assert::true((bool) ($sections[3]['preserve_by_default'] ?? false), 'query preserve_by_default');
}

function test_page_section_model_hero_cta_and_carry_forward_signals(): void {
    $content =
        '<!-- wp:cover -->'
        . '<!-- wp:heading --><h1>Welcome hero</h1><!-- /wp:heading -->'
        . '<!-- /wp:cover -->'
        . '<!-- wp:buttons -->'
        . '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="https://example.com/apply">Apply now</a></div><!-- /wp:button -->'
        . '<!-- /wp:buttons -->'
        . '<!-- wp:paragraph --><p>Call us at 555-0100 or see plan 2.5 details.</p><!-- /wp:paragraph -->';

    $sections = PageSectionModel::from_content($content);
    Assert::same(3, count($sections), 'three sections');
    Assert::same(PageSectionModel::ROLE_HERO, $sections[0]['role'] ?? null, 'cover is hero');
    Assert::same(PageSectionModel::ROLE_CTA, $sections[1]['role'] ?? null, 'buttons apply is cta');
    Assert::true(in_array('https://example.com/apply', $sections[1]['links'] ?? [], true), 'cta link extracted');
    Assert::true(
        in_array('555-0100', $sections[2]['numeric_tokens'] ?? [], true)
        || in_array('2.5', $sections[2]['numeric_tokens'] ?? [], true),
        'numeric tokens extracted from body',
    );
}

function test_page_section_model_suggest_for_intent(): void {
    $sections = PageSectionModel::from_content(
        '<!-- wp:group --><!-- wp:post-title /--><!-- /wp:group -->'
        . '<!-- wp:group --><!-- wp:heading --><h2>FAQ</h2><!-- /wp:heading --><!-- /wp:group -->'
        . '<!-- wp:query --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->',
    );

    $faq = PageSectionModel::suggest_for_intent($sections, 'replace the FAQ section with a cleaner accordion');
    Assert::true(count($faq) > 0, 'FAQ intent yields suggestions');
    Assert::same('1', $faq[0]['path'] ?? null, 'FAQ path preferred');
    Assert::same(PageSectionModel::ROLE_FAQ, $faq[0]['role'] ?? null, 'FAQ role');

    $header = PageSectionModel::suggest_for_intent($sections, 'swap the page header');
    Assert::true(count($header) > 0, 'header intent yields suggestions');
    Assert::same(PageSectionModel::ROLE_HEADER, $header[0]['role'] ?? null, 'header role suggested');
}

function test_page_section_model_recommend_operation(): void {
    $copy = PageSectionModel::recommend_operation('fix a typo in the wording');
    Assert::same('batch_or_attrs', $copy['operation'] ?? null, 'copy-only → batch');

    $add = PageSectionModel::recommend_operation('add a new section for testimonials', null, 'insert');
    Assert::same('pattern_insert', $add['operation'] ?? null, 'insert mode');

    $replace = PageSectionModel::recommend_operation('replace the middle with staff directory', null, 'replace');
    Assert::same('pattern_replace', $replace['operation'] ?? null, 'replace mode');

    $dynamic = PageSectionModel::recommend_operation(
        'refresh layout',
        ['preserve_by_default' => true, 'role' => PageSectionModel::ROLE_QUERY],
        'replace',
    );
    Assert::same(
        'pattern_replace_with_preserve_warning',
        $dynamic['operation'] ?? null,
        'dynamic target warns preserve',
    );
}

function test_page_section_model_find_by_path(): void {
    $sections = PageSectionModel::from_content('<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->'
    . '<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->');
    $found = PageSectionModel::find_by_path($sections, '1');
    Assert::true(is_array($found), 'found section 1');
    Assert::same('1', $found['path'] ?? null, 'path 1');
    Assert::same(null, PageSectionModel::find_by_path($sections, '9'), 'missing path');
}

test_page_section_model_roles_header_body_faq_query();
test_page_section_model_hero_cta_and_carry_forward_signals();
test_page_section_model_suggest_for_intent();
test_page_section_model_recommend_operation();
test_page_section_model_find_by_path();
