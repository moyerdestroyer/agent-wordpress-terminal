<?php

/**
 * Namespaced metadata that maps WordPress Knowledge guidelines to Domain Packs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

if (!defined('ABSPATH')) {
    exit();
}

final class KnowledgeOverrideMeta {
    public static function register(): void {
        foreach (['wp_knowledge', 'wp_guideline'] as $post_type) {
            if (!post_type_exists($post_type)) {
                continue;
            }

            foreach (['_awpt_pack_id', '_awpt_guidance_id', '_awpt_override_mode'] as $key) {
                register_post_meta($post_type, $key, [
                    'type' => 'string',
                    'single' => true,
                    'show_in_rest' => true,
                    'sanitize_callback' => 'sanitize_key',
                    'auth_callback' => static fn(): bool => current_user_can('manage_options'),
                ]);
            }
        }
    }
}
