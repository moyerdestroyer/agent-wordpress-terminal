<?php

/** Tests one-use headless preview authentication. @package AWPT */

declare(strict_types=1);

use AWPT\Support\Diagnostics\RenderedPreviewAuthenticator;

function test_rendered_preview_authenticator_is_same_target_and_one_use(): void {
    awpt_test_reset_state();
    $auth = new RenderedPreviewAuthenticator();
    $issued = $auth->issue('https://example.test/docs/?preview=true&preview_id=32');

    Assert::true(!is_wp_error($issued), 'a signed-in user should be able to issue a same-site render credential');

    if (is_wp_error($issued)) {
        return;
    }

    $parts = wp_parse_url($issued['url']);
    parse_str((string) ($parts['query'] ?? ''), $query);
    $_GET['awpt_render_token'] = (string) ($query['awpt_render_token'] ?? '');
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 HeadlessChrome/136.0';
    $_SERVER['REQUEST_URI'] =
        '/docs/?preview_id=32&preview=true&awpt_render_token=' . rawurlencode((string) $_GET['awpt_render_token']);

    Assert::same(
        1,
        RenderedPreviewAuthenticator::authenticate(0),
        'the exact headless preview request should authenticate',
    );
    Assert::same(
        0,
        RenderedPreviewAuthenticator::authenticate(0),
        'the render credential should be consumed after one use',
    );

    unset($_GET['awpt_render_token'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI']);
}

test_rendered_preview_authenticator_is_same_target_and_one_use();

function test_rendered_preview_authenticator_rejects_external_targets(): void {
    $issued = new RenderedPreviewAuthenticator()->issue('https://external.example/docs/');

    Assert::true(is_wp_error($issued), 'render credentials should never be issued for another host');
}

test_rendered_preview_authenticator_rejects_external_targets();
