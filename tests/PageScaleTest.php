<?php

/**
 * Page scale tiers for large-page redesign strategy.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\PageScale;

function awpt_scale_content(int $blocks, int $chars): string {
    $chunk = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
    // Each chunk is one block; pad chars after.
    $markup = str_repeat($chunk, max(0, $blocks));
    if (strlen($markup) < $chars) {
        $markup .= str_repeat('a', $chars - strlen($markup));
    }

    return $markup;
}

function test_page_scale_boundaries(): void {
    $scale = new PageScale();

    Assert::same(PageScale::SMALL, $scale->classify(0, 0), 'empty is small');
    Assert::same(PageScale::SMALL, $scale->classify(19, 5_999), 'just under medium floors is small');
    Assert::same(PageScale::MEDIUM, $scale->classify(20, 100), '20 blocks is medium');
    Assert::same(PageScale::MEDIUM, $scale->classify(5, 6_000), '6k chars is medium');
    Assert::same(PageScale::MEDIUM, $scale->classify(39, 11_999), 'just under large floors is medium');
    Assert::same(PageScale::LARGE, $scale->classify(40, 100), '40 blocks is large');
    Assert::same(PageScale::LARGE, $scale->classify(10, 12_000), '12k chars is large');
    Assert::same(PageScale::LARGE, $scale->classify(40, 12_000), 'both large floors is large');
}

function test_page_scale_measure_content_counts_blocks(): void {
    $scale = new PageScale();
    $content = awpt_scale_content(40, 500);
    $measure = $scale->measure_content($content);

    Assert::same(PageScale::LARGE, $measure['scale'], '40 block markers measure large');
    Assert::true($measure['blocks'] >= 40, 'block count at least 40');
    Assert::true($measure['chars'] >= 500, 'char count tracks content length');
}

function test_page_scale_from_tool_calls_uses_read_content(): void {
    $scale = new PageScale();
    $content = awpt_scale_content(45, 13_000);
    $measure = $scale->from_tool_calls([
        [
            'tool' => 'awpt/read-content',
            'status' => 'success',
            'output' => ['id' => 503, 'content' => $content],
        ],
    ]);

    Assert::same(PageScale::LARGE, $measure['scale'], 'read-content drives large classification');
    Assert::true($scale->is_large($measure['scale']), 'is_large matches');
}

function test_page_scale_compose_guidance_for_large(): void {
    $scale = new PageScale();
    $text = $scale->compose_guidance(['scale' => PageScale::LARGE, 'blocks' => 47, 'chars' => 13_561]);

    Assert::true(str_contains($text, 'large'), 'guidance mentions large');
    Assert::true(str_contains($text, 'propose-block-batch-update'), 'guidance prefers batch tool');
    Assert::true(str_contains($text, '47'), 'guidance includes block count');
    Assert::same(
        '',
        $scale->compose_guidance(['scale' => PageScale::UNKNOWN, 'blocks' => 0, 'chars' => 0]),
        'unknown scale has no compose guidance',
    );
}

test_page_scale_boundaries();
test_page_scale_measure_content_counts_blocks();
test_page_scale_from_tool_calls_uses_read_content();
test_page_scale_compose_guidance_for_large();
