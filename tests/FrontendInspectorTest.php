<?php

/** Rendered/static frontend semantic evidence. @package AWPT */

declare(strict_types=1);

use AWPT\Support\Diagnostics\FrontendInspector;

function test_frontend_inspector_distinguishes_page_headings_from_site_chrome(): void {
    $method = new ReflectionMethod(FrontendInspector::class, 'main_heading_evidence');
    $evidence = $method->invoke(
        new FrontendInspector(),
        '<body><header><h1>Agency</h1></header><main><h2>Filing Basics</h2><h3>Question?</h3></main></body>',
    );

    Assert::same(0, $evidence['h1_count'] ?? null, 'a site-header H1 must not masquerade as the page title');
    Assert::same(
        [
            ['level' => 2, 'text' => 'Filing Basics'],
            ['level' => 3, 'text' => 'Question?'],
        ],
        $evidence['outline'] ?? [],
        'the model should receive the content-region heading hierarchy',
    );

    $with_title = $method->invoke(
        new FrontendInspector(),
        '<main><h1>Broker Filing Procedures</h1><h2>Filing Basics</h2></main>',
    );
    Assert::same(1, $with_title['h1_count'] ?? null, 'a page-local H1 should be reported explicitly');
}

test_frontend_inspector_distinguishes_page_headings_from_site_chrome();
