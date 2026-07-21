<?php

/**
 * Tests for Knowledge backend compatibility discovery.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Knowledge\KnowledgeRepository;

function test_knowledge_repository_detects_core_backend(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_post_types'][] = 'wp_knowledge';

    $status = new KnowledgeRepository()->status();

    Assert::same('core', $status['mode'], 'wp_knowledge should be selected when Core registers it');
    Assert::same('wp_knowledge', $status['post_type'], 'status should identify the selected backend');
    Assert::true($status['core_available'], 'Core Knowledge should be reported as available');
}

function test_knowledge_repository_accepts_compatibility_backend(): void {
    awpt_test_reset_state();
    $GLOBALS['awpt_test_post_types'][] = 'vendor_knowledge';
    add_filter('awpt_knowledge_backends', static function (array $backends): array {
        $backends[] = [
            'post_type' => 'vendor_knowledge',
            'taxonomy' => 'vendor_knowledge_type',
            'kind' => 'vendor_knowledge',
            'mode' => 'compatibility',
            'label' => 'Compatibility Knowledge',
            'family' => 'extension',
        ];

        return $backends;
    });

    $status = new KnowledgeRepository()->status();

    Assert::same('compatibility', $status['mode'], 'filtered backends should support 7.1 rollout changes');
    Assert::same('vendor_knowledge', $status['post_type'], 'the compatibility post type should be selected');
}

test_knowledge_repository_detects_core_backend();
test_knowledge_repository_accepts_compatibility_backend();
