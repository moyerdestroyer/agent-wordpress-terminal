<?php

/**
 * Tests multimodal Media Library evidence for providers.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\MediaLibraryVisualEvidence;

function test_media_library_visual_evidence_rejects_private_hosts(): void {
    $evidence = new MediaLibraryVisualEvidence();

    Assert::false(
        $evidence->is_provider_fetchable_url('http://awpt-testing.totem:8080/wp-content/uploads/2026/07/image.png'),
        'local .totem hosts must not be sent to remote providers as fetchable URLs',
    );
    Assert::false(
        $evidence->is_provider_fetchable_url('http://127.0.0.1/wp-content/uploads/x.png'),
        'loopback hosts must not be provider-fetchable',
    );
    Assert::true(
        $evidence->is_provider_fetchable_url('https://cdn.example.com/media/hero.png'),
        'public https hosts remain provider-fetchable',
    );
}

function test_media_library_visual_evidence_prefers_data_urls_for_composer_attachments(): void {
    awpt_test_reset_state();
    update_option('awpt_provider', 'openrouter');
    update_option('awpt_openrouter_model', 'google/gemini-2.5-pro');

    $fixture = sys_get_temp_dir() . '/awpt-media-evidence-' . getmypid() . '.png';
    // Minimal valid-looking PNG bytes are unnecessary; any readable file is enough for base64.
    file_put_contents($fixture, 'fake-image-bytes');

    $attachment = new WP_Post();
    $attachment->ID = 126;
    $attachment->post_type = 'attachment';
    $GLOBALS['awpt_test_posts'][126] = $attachment;
    $GLOBALS['awpt_test_attachment_is_image'][126] = true;
    $GLOBALS['awpt_test_attached_files'][126] = $fixture;
    $GLOBALS['awpt_test_attachment_mime_types'][126] = 'image/png';

    $parts = new MediaLibraryVisualEvidence()->parts_for_composer_attachments([
        [
            'id' => 126,
            'url' => 'http://awpt-testing.totem:8080/wp-content/uploads/2026/07/image-7.png',
        ],
    ]);

    $image_parts = array_values(array_filter(
        $parts,
        static fn(array $part): bool => 'image_url' === ($part['type'] ?? null),
    ));
    Assert::same(1, count($image_parts), 'vision-capable models should emit one image part for local files');
    $url = (string) ($image_parts[0]['image_url']['url'] ?? '');
    Assert::true(str_starts_with($url, 'data:image/png;base64,'), 'local private hosts should use data URLs');

    @unlink($fixture);
}

function test_media_library_visual_evidence_preserves_images_for_deepseek_sidecar(): void {
    awpt_test_reset_state();
    update_option('awpt_provider', 'openrouter');
    update_option('awpt_openrouter_model', 'deepseek/deepseek-v4-pro');

    $fixture = sys_get_temp_dir() . '/awpt-media-evidence-deepseek-' . getmypid() . '.png';
    file_put_contents($fixture, 'fake-image-bytes');

    $attachment = new WP_Post();
    $attachment->ID = 126;
    $attachment->post_type = 'attachment';
    $GLOBALS['awpt_test_posts'][126] = $attachment;
    $GLOBALS['awpt_test_attachment_is_image'][126] = true;
    $GLOBALS['awpt_test_attached_files'][126] = $fixture;
    $GLOBALS['awpt_test_attachment_mime_types'][126] = 'image/png';

    $parts = new MediaLibraryVisualEvidence()->parts_for_composer_attachments([
        [
            'id' => 126,
            'url' => 'http://awpt-testing.totem:8080/wp-content/uploads/2026/07/image-7.png',
        ],
    ]);

    $image_parts = array_values(array_filter(
        $parts,
        static fn(array $part): bool => 'image_url' === ($part['type'] ?? null),
    ));
    Assert::same(1, count($image_parts), 'image evidence must survive long enough for the DeepSeek vision sidecar');
    Assert::true(
        str_contains((string) ($parts[0]['text'] ?? ''), '#126'),
        'attachment ids remain as text for Image/Cover block attributes',
    );

    @unlink($fixture);
}

function test_media_library_visual_evidence_skips_unreadable_private_urls(): void {
    awpt_test_reset_state();

    $parts = new MediaLibraryVisualEvidence()->parts_for_composer_attachments([
        [
            'id' => 127,
            'url' => 'http://awpt-testing.totem:8080/wp-content/uploads/missing.png',
        ],
    ]);

    $image_parts = array_values(array_filter(
        $parts,
        static fn(array $part): bool => 'image_url' === ($part['type'] ?? null),
    ));
    Assert::same(0, count($image_parts), 'unreadable private attachments must not emit image_url parts');
    Assert::true(
        str_contains((string) ($parts[0]['text'] ?? ''), '#127'),
        'text evidence should still record the attachment id for block attributes',
    );
}

function test_media_library_visual_evidence_supports_pattern_preparation_media(): void {
    awpt_test_reset_state();
    $fixture = sys_get_temp_dir() . '/awpt-prepared-media-' . getmypid() . '.png';
    file_put_contents($fixture, 'fake-image-bytes');
    $attachment = new WP_Post();
    $attachment->ID = 128;
    $attachment->post_type = 'attachment';
    $GLOBALS['awpt_test_posts'][128] = $attachment;
    $GLOBALS['awpt_test_attachment_is_image'][128] = true;
    $GLOBALS['awpt_test_attached_files'][128] = $fixture;
    $GLOBALS['awpt_test_attachment_mime_types'][128] = 'image/png';
    $GLOBALS['awpt_test_attachment_urls'][128] = 'http://awpt-testing.totem:8080/uploads/image.png';

    $message = new MediaLibraryVisualEvidence()->build(
        'awpt/prepare-pattern-draft',
        ['media_count' => 1],
        ['media' => [['id' => 128, 'title' => 'image']]],
    );

    Assert::true(is_array($message), 'prepared media should produce visual evidence');
    Assert::same(
        'image_url',
        $message['content'][2]['type'] ?? '',
        'prepared media should include local image bytes for visual identification',
    );

    @unlink($fixture);
}

test_media_library_visual_evidence_rejects_private_hosts();
test_media_library_visual_evidence_prefers_data_urls_for_composer_attachments();
test_media_library_visual_evidence_preserves_images_for_deepseek_sidecar();
test_media_library_visual_evidence_skips_unreadable_private_urls();
test_media_library_visual_evidence_supports_pattern_preparation_media();
