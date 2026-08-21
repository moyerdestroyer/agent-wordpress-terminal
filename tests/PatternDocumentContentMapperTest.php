<?php

/** Whole-document patterns preserve structured source blocks. */

declare(strict_types=1);

use AWPT\Domain\PatternDocumentContentMapper;

function test_pattern_document_mapper_preserves_blocks_and_builds_toc(): void {
    $pattern =
        '<!-- wp:cover --><div class="wp-block-cover"><!-- wp:post-title {"level":1} /--></div><!-- /wp:cover -->'
        . '<!-- wp:columns --><div class="wp-block-columns">'
        . '<!-- wp:column --><div class="wp-block-column">'
        . '<!-- wp:navigation {"overlayMenu":"never"} /-->'
        . '</div><!-- /wp:column -->'
        . '<!-- wp:column --><div class="wp-block-column">'
        . '<!-- wp:group {"tagName":"article","className":"content-main"} -->'
        . '<article class="wp-block-group content-main">'
        . '<!-- wp:heading --><h2>Section heading (h2)</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Instructional filler.</p><!-- /wp:paragraph -->'
        . '</article><!-- /wp:group -->'
        . '</div><!-- /wp:column -->'
        . '</div><!-- /wp:columns -->';
    $source =
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":4} --><h4>How do I renew?</h4><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Keep this complete answer and its '
        . '<a href="/renew">renewal link</a>.</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":4} --><h4>How do I cancel?</h4><!-- /wp:heading -->'
        . '<!-- wp:list --><ul><li>Keep this cancellation step.</li></ul><!-- /wp:list -->'
        . '</div><!-- /wp:group -->';

    $mapped = new PatternDocumentContentMapper()->map($pattern, $source);
    Assert::false(is_wp_error($mapped), 'document content maps into an article container');
    if (!is_string($mapped)) {
        return;
    }

    Assert::true(str_contains($mapped, 'Keep this complete answer'), 'first answer survives');
    Assert::true(str_contains($mapped, 'Keep this cancellation step'), 'second list survives');
    Assert::true(str_contains($mapped, 'href="/renew"'), 'source links survive');
    Assert::false(str_contains($mapped, 'Instructional filler'), 'starter filler is removed');
    Assert::false(str_contains($mapped, 'Section heading (h2)'), 'starter heading is removed');
    Assert::true(str_contains($mapped, '<h2'), 'source h4 headings are rebased below the page H1');
    Assert::true(str_contains($mapped, 'url":"#how-do-i-renew"'), 'TOC link is derived from source heading');
    Assert::true(str_contains($mapped, 'id="how-do-i-renew"'), 'source heading receives matching anchor');
}

function test_pattern_document_mapper_fails_without_content_container(): void {
    $result = new PatternDocumentContentMapper()->map(
        '<!-- wp:cover --><div class="wp-block-cover"></div><!-- /wp:cover -->',
        '<!-- wp:paragraph --><p>Source copy.</p><!-- /wp:paragraph -->',
    );

    Assert::instanceOf(WP_Error::class, $result, 'patterns without a content slot fail closed');
    Assert::same('awpt_pattern_document_slot_missing', $result->get_error_code(), 'specific slot error');
}

function test_pattern_document_mapper_ignores_headingless_nested_siblings_when_rebasing(): void {
    $pattern =
        '<!-- wp:group {"tagName":"article"} --><article class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Starter</p><!-- /wp:paragraph -->'
        . '</article><!-- /wp:group -->';
    $source =
        '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:paragraph --><p>Headingless sibling.</p><!-- /wp:paragraph -->'
        . '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:heading {"level":4} --><h4>Nested question</h4><!-- /wp:heading -->'
        . '</div><!-- /wp:group -->'
        . '</div><!-- /wp:group -->';
    $mapped = new PatternDocumentContentMapper()->map($pattern, $source);
    Assert::false(is_wp_error($mapped), 'headingless siblings do not prevent mapping');
    Assert::true(is_string($mapped) && str_contains($mapped, '<h2'), 'nested H4 is rebased to H2');
    Assert::false(is_string($mapped) && str_contains($mapped, '<h4'), 'stale H4 markup is removed');
}

test_pattern_document_mapper_preserves_blocks_and_builds_toc();
test_pattern_document_mapper_fails_without_content_container();
test_pattern_document_mapper_ignores_headingless_nested_siblings_when_rebasing();
