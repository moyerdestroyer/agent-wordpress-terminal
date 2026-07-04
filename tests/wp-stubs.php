<?php

/**
 * Minimal WordPress function/constant stubs for AWPT's bootstrap-free test harness.
 *
 * This intentionally does NOT pull in a full WordPress test suite. It only stubs the
 * handful of WordPress globals/functions that the classes under test touch, backed by
 * in-memory state the tests can freely reset between cases.
 *
 * @package AWPT
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/fixtures/');
}

/**
 * Resets all in-memory WordPress test state. Call at the start of every test case.
 */
function awpt_test_reset_state(): void
{
    $GLOBALS['awpt_test_options'] = [];
    $GLOBALS['awpt_test_connectors'] = [];
    $GLOBALS['awpt_test_active_plugins'] = [];
    $GLOBALS['awpt_test_env'] = [];
    $GLOBALS['awpt_test_current_user_can'] = null;
    $GLOBALS['awpt_test_post_meta_updates'] = [];
    $GLOBALS['awpt_test_next_post_id'] = 42;
    $GLOBALS['awpt_test_current_user_id'] = 1;
    $GLOBALS['awpt_test_posts'] = [];
    $GLOBALS['awpt_test_post_thumbnails'] = [];
    $GLOBALS['awpt_test_set_post_thumbnail_result'] = true;
    $GLOBALS['awpt_test_attachment_is_image'] = [];
    $GLOBALS['awpt_test_trashed_posts'] = [];
}

awpt_test_reset_state();

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['awpt_test_options'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value): bool
    {
        $GLOBALS['awpt_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        unset($domain);

        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return __($text, $domain);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        $key = strtolower($key);

        return (string) preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim($value);
    }
}

if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $value);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . $path;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}

if (!function_exists('is_plugin_active')) {
    function is_plugin_active(string $plugin_file): bool
    {
        return in_array($plugin_file, $GLOBALS['awpt_test_active_plugins'], true);
    }
}

if (!function_exists('is_plugin_active_for_network')) {
    function is_plugin_active_for_network(string $plugin_file): bool
    {
        unset($plugin_file);

        return false;
    }
}

if (!function_exists('wp_get_connectors')) {
    /**
     * @return array<string, array<string, mixed>>
     */
    function wp_get_connectors(): array
    {
        return $GLOBALS['awpt_test_connectors'];
    }
}

if (!function_exists('wp_get_connector')) {
    /**
     * @return array<string, mixed>|null
     */
    function wp_get_connector(string $provider_id): ?array
    {
        return $GLOBALS['awpt_test_connectors'][$provider_id] ?? null;
    }
}

if (!function_exists('wp_is_connector_registered')) {
    function wp_is_connector_registered(string $provider_id): bool
    {
        return array_key_exists($provider_id, $GLOBALS['awpt_test_connectors']);
    }
}

if (!function_exists('current_user_can')) {
    /**
     * Delegates to $GLOBALS['awpt_test_current_user_can'] when set (so tests can assert
     * exactly which capability/args a call site passes — this is how the
     * `current_user_can(capability: ..., args: ...)` named-argument-vs-variadic bug was
     * caught), otherwise defaults to an "allow everything" super-admin-like user.
     *
     * @param mixed ...$args
     */
    function current_user_can(string $capability, mixed ...$args): bool
    {
        $handler = $GLOBALS['awpt_test_current_user_can'] ?? null;

        if (is_callable($handler)) {
            return (bool) $handler($capability, ...$args);
        }

        return true;
    }
}

if (!function_exists('wp_update_post')) {
    /**
     * @param array<string, mixed> $postarr
     */
    function wp_update_post(array $postarr, bool $wp_error = false): int|WP_Error
    {
        unset($wp_error);

        return (int) ($postarr['ID'] ?? 0);
    }
}

if (!function_exists('wp_insert_post')) {
    /**
     * @param array<string, mixed> $postarr
     */
    function wp_insert_post(array $postarr, bool $wp_error = false): int|WP_Error
    {
        unset($wp_error);

        $post_id = $GLOBALS['awpt_test_next_post_id'] ?? 42;
        $post = new WP_Post();
        $post->ID = $post_id;
        $post->post_type = is_string($postarr['post_type'] ?? null) ? $postarr['post_type'] : 'post';
        $post->post_status = is_string($postarr['post_status'] ?? null) ? $postarr['post_status'] : 'draft';
        $post->post_title = is_string($postarr['post_title'] ?? null) ? $postarr['post_title'] : '';
        $post->post_content = is_string($postarr['post_content'] ?? null) ? $postarr['post_content'] : '';
        $GLOBALS['awpt_test_posts'][$post_id] = $post;

        return $post_id;
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link(int $post_id, string $context = 'display'): string
    {
        unset($context);

        return 'https://example.test/wp-admin/post.php?post=' . $post_id . '&action=edit';
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return $GLOBALS['awpt_test_current_user_id'] ?? 1;
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string
    {
        return $content;
    }
}

if (!function_exists('get_post_statuses')) {
    /**
     * @return array<string, string>
     */
    function get_post_statuses(): array
    {
        return [
            'publish' => 'Published',
            'draft' => 'Draft',
            'pending' => 'Pending',
            'private' => 'Private',
            'future' => 'Scheduled',
        ];
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $meta_key, mixed $meta_value): bool
    {
        $GLOBALS['awpt_test_post_meta_updates'][$post_id][$meta_key] = $meta_value;

        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
    {
        unset($hook_name, $args);

        return $value;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data): string|false
    {
        return json_encode($data);
    }
}

if (!class_exists('WP_Error')) {
    /**
     * Minimal WP_Error stand-in sufficient for provider error handling tests.
     */
    class WP_Error
    {
        private string $code;
        private string $message;
        private mixed $data;

        public function __construct(string $code = '', string $message = '', mixed $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'draft';
        public string $post_title = '';
        public string $post_content = '';
        public string $post_excerpt = '';
    }
}

if (!function_exists('get_post')) {
    function get_post(int $post_id): ?WP_Post
    {
        return $GLOBALS['awpt_test_posts'][$post_id] ?? null;
    }
}

if (!function_exists('wp_attachment_is_image')) {
    function wp_attachment_is_image(int $attachment_id): bool
    {
        $attachment = get_post($attachment_id);

        return $attachment instanceof WP_Post
            && 'attachment' === $attachment->post_type
            && ($GLOBALS['awpt_test_attachment_is_image'][$attachment_id] ?? false);
    }
}

if (!function_exists('get_post_thumbnail_id')) {
    function get_post_thumbnail_id(int $post_id): int
    {
        $thumbnail_id = $GLOBALS['awpt_test_post_thumbnails'][$post_id] ?? 0;

        return is_int($thumbnail_id) ? $thumbnail_id : (int) $thumbnail_id;
    }
}

if (!function_exists('set_post_thumbnail')) {
    function set_post_thumbnail(int $post_id, int $attachment_id): bool
    {
        if (!($GLOBALS['awpt_test_set_post_thumbnail_result'] ?? true)) {
            return false;
        }

        $current = get_post_thumbnail_id($post_id);

        if ($current === $attachment_id) {
            // Mirror WordPress: update_post_meta() returns false when unchanged.
            return false;
        }

        $GLOBALS['awpt_test_post_thumbnails'][$post_id] = $attachment_id;

        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
    {
        unset($single);

        return $GLOBALS['awpt_test_post_meta_updates'][$post_id][$key] ?? '';
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $meta_key): bool
    {
        unset($GLOBALS['awpt_test_post_meta_updates'][$post_id][$meta_key]);

        return true;
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(int|WP_Post $post): string
    {
        if ($post instanceof WP_Post) {
            return $post->post_title;
        }

        $loaded = get_post($post);

        return $loaded instanceof WP_Post ? $loaded->post_title : '';
    }
}

if (!function_exists('get_preview_post_link')) {
    function get_preview_post_link(int|WP_Post $post): string
    {
        $post_id = $post instanceof WP_Post ? $post->ID : $post;

        return 'https://example.test/?p=' . $post_id . '&preview=true';
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(int|WP_Post $post): string
    {
        $post_id = $post instanceof WP_Post ? $post->ID : $post;

        return 'https://example.test/?p=' . $post_id;
    }
}

if (!function_exists('wp_trash_post')) {
    function wp_trash_post(int $post_id): int|false
    {
        $GLOBALS['awpt_test_trashed_posts'][] = $post_id;

        return $post_id;
    }
}
