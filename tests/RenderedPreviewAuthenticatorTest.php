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
    $_SERVER['HTTP_USER_AGENT'] = 'WordPress/6.8; https://example.test';
    $_SERVER['REQUEST_URI'] =
        '/docs/?preview_id=32&preview=true&awpt_render_token=' . rawurlencode((string) $_GET['awpt_render_token']);

    Assert::same(
        1,
        RenderedPreviewAuthenticator::authenticate(0),
        'the exact one-use preview request should authenticate for browser and static fallback inspection',
    );
    Assert::same(
        0,
        RenderedPreviewAuthenticator::authenticate(0),
        'the render credential should be consumed after one use',
    );

    unset($_GET['awpt_render_token'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REQUEST_URI']);
}

test_rendered_preview_authenticator_is_same_target_and_one_use();

function test_rendered_preview_authenticator_reissues_preview_nonce_without_browser_cookie(): void {
    awpt_test_reset_state();
    $auth = new RenderedPreviewAuthenticator();
    $issued = $auth->issue('https://example.test/docs/?preview_id=32&preview_nonce=browser-session-nonce&preview=true');

    Assert::true(!is_wp_error($issued), 'a private preview should receive a render credential');

    if (is_wp_error($issued)) {
        return;
    }

    $parts = wp_parse_url($issued['url']);
    parse_str((string) ($parts['query'] ?? ''), $query);
    $action = 'post_preview_32';
    $expected = substr(wp_hash(wp_nonce_tick($action) . '|' . $action . '|1|', 'nonce'), -12, 10);

    Assert::same($expected, $query['preview_nonce'] ?? '', 'the nonce should match token-auth without a cookie');
    Assert::false(
        str_contains($issued['url'], 'browser-session-nonce'),
        'the browser-session-bound preview nonce should not leak into the headless request',
    );
}

test_rendered_preview_authenticator_reissues_preview_nonce_without_browser_cookie();

function test_rendered_preview_authenticator_rejects_external_targets(): void {
    $issued = new RenderedPreviewAuthenticator()->issue('https://external.example/docs/');

    Assert::true(is_wp_error($issued), 'render credentials should never be issued for another host');
}

test_rendered_preview_authenticator_rejects_external_targets();
