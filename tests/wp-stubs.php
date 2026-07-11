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

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

/**
 * Resets all in-memory WordPress test state. Call at the start of every test case.
 */
function awpt_test_reset_state(): void {
    $GLOBALS['awpt_test_options'] = [];
    $GLOBALS['awpt_test_connectors'] = [];
    $GLOBALS['awpt_test_active_plugins'] = [];
    $GLOBALS['awpt_test_plugins'] = [];
    $GLOBALS['awpt_test_deactivated_plugins'] = [];
    $GLOBALS['awpt_test_env'] = [];
    $GLOBALS['awpt_test_current_user_can'] = null;
    $GLOBALS['awpt_test_post_meta_updates'] = [];
    $GLOBALS['awpt_test_next_post_id'] = 42;
    $GLOBALS['awpt_test_current_user_id'] = 1;
    $GLOBALS['awpt_test_posts'] = [];
    $GLOBALS['awpt_test_url_to_postid'] = [];
    $GLOBALS['awpt_test_post_thumbnails'] = [];
    $GLOBALS['awpt_test_set_post_thumbnail_result'] = true;
    $GLOBALS['awpt_test_attachment_is_image'] = [];
    $GLOBALS['awpt_test_trashed_posts'] = [];
    $GLOBALS['awpt_test_users'] = [];
    $GLOBALS['awpt_test_filters'] = [];
}

awpt_test_reset_state();

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed {
        return $GLOBALS['awpt_test_options'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value): bool {
        $GLOBALS['awpt_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        unset($domain);

        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string {
        return __($text, $domain);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        $key = strtolower($key);

        return (string) preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string {
        return trim($value);
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string {
        return trim($value);
    }
}

if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class(string $value): string {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $value);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        return 'https://example.test/wp-admin/' . $path;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return false;
    }
}

if (!function_exists('is_plugin_active')) {
    function is_plugin_active(string $plugin_file): bool {
        return in_array($plugin_file, $GLOBALS['awpt_test_active_plugins'], true);
    }
}

if (!function_exists('is_plugin_active_for_network')) {
    function is_plugin_active_for_network(string $plugin_file): bool {
        unset($plugin_file);

        return false;
    }
}

if (!function_exists('is_plugin_active_for_network')) {
    function is_plugin_active_for_network(string $plugin_file): bool {
        unset($plugin_file);

        return false;
    }
}

if (!function_exists('get_plugins')) {
    /**
     * @return array<string, array<string, string>>
     */
    function get_plugins(): array {
        return $GLOBALS['awpt_test_plugins'];
    }
}

if (!function_exists('deactivate_plugins')) {
    /**
     * @param list<string>|string $plugins
     */
    function deactivate_plugins(array|string $plugins): void {
        $plugins = is_array($plugins) ? $plugins : [$plugins];

        foreach ($plugins as $plugin) {
            $GLOBALS['awpt_test_deactivated_plugins'][] = $plugin;
            $GLOBALS['awpt_test_active_plugins'] = array_values(array_filter(
                $GLOBALS['awpt_test_active_plugins'],
                static fn(string $active): bool => $active !== $plugin,
            ));
        }
    }
}

if (!function_exists('wp_get_connectors')) {
    /**
     * @return array<string, array<string, mixed>>
     */
    function wp_get_connectors(): array {
        return $GLOBALS['awpt_test_connectors'];
    }
}

if (!function_exists('wp_get_connector')) {
    /**
     * @return array<string, mixed>|null
     */
    function wp_get_connector(string $provider_id): ?array {
        return $GLOBALS['awpt_test_connectors'][$provider_id] ?? null;
    }
}

if (!function_exists('wp_is_connector_registered')) {
    function wp_is_connector_registered(string $provider_id): bool {
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
    function current_user_can(string $capability, mixed ...$args): bool {
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
    function wp_update_post(array $postarr, bool $wp_error = false): int|WP_Error {
        unset($wp_error);

        $post_id = (int) ($postarr['ID'] ?? 0);

        if ($post_id > 0) {
            $post = $GLOBALS['awpt_test_posts'][$post_id] ?? new WP_Post();
            $post->ID = $post_id;

            foreach (['post_type', 'post_status', 'post_title', 'post_content', 'post_excerpt'] as $key) {
                if (array_key_exists($key, $postarr) && is_string($postarr[$key])) {
                    $post->{$key} = $postarr[$key];
                }
            }

            $GLOBALS['awpt_test_posts'][$post_id] = $post;
        }

        return $post_id;
    }
}

if (!function_exists('wp_insert_post')) {
    /**
     * @param array<string, mixed> $postarr
     */
    function wp_insert_post(array $postarr, bool $wp_error = false): int|WP_Error {
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
    function get_edit_post_link(int $post_id, string $context = 'display'): string {
        unset($context);

        return 'https://example.test/wp-admin/post.php?post=' . $post_id . '&action=edit';
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int {
        return $GLOBALS['awpt_test_current_user_id'] ?? 1;
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string {
        return $content;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string {
        return trim(strip_tags($text));
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title): string {
        $title = strtolower(trim($title));
        $title = (string) preg_replace('/[^a-z0-9]+/', '-', $title);

        return trim($title, '-');
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed {
        return parse_url($url, $component);
    }
}

if (!function_exists('url_to_postid')) {
    function url_to_postid(string $url): int {
        return (int) ($GLOBALS['awpt_test_url_to_postid'][$url] ?? 0);
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists(string $post_type): bool {
        return in_array(
            $post_type,
            ['post', 'page', 'attachment', 'wp_block', 'wp_template', 'wp_template_part'],
            true,
        );
    }
}

if (!function_exists('get_page_by_path')) {
    /**
     * @param list<string>|string $post_type
     */
    function get_page_by_path(string $page_path, string $output = OBJECT, array|string $post_type = 'page'): ?WP_Post {
        unset($output);

        $post_types = is_array($post_type) ? $post_type : [$post_type];

        foreach ($GLOBALS['awpt_test_posts'] as $post) {
            if (
                $post instanceof WP_Post
                && in_array($post->post_type, $post_types, true)
                && ($post->post_name ?? '') === $page_path
            ) {
                return $post;
            }
        }

        return null;
    }
}

if (!function_exists('parse_blocks')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function parse_blocks(string $content): array {
        $pattern = '/<!--\s+wp:([a-z0-9_\/-]+)(?:\s+(\{.*?\}))?\s+-->(.*?)<!--\s+\/wp:\1\s+-->/s';

        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return (
                '' === trim($content)
                    ? []
                    : [[
                        'blockName' => null,
                        'attrs' => [],
                        'innerBlocks' => [],
                        'innerHTML' => $content,
                    ]]
            );
        }

        $blocks = [];

        foreach ($matches as $match) {
            $name = (string) $match[1];
            $attrs = [] !== ($decoded = json_decode((string) ($match[2] ?? ''), true)) && is_array($decoded)
                ? $decoded
                : [];

            if (!str_contains($name, '/')) {
                $name = 'core/' . $name;
            }

            $blocks[] = [
                'blockName' => $name,
                'attrs' => $attrs,
                'innerBlocks' => parse_blocks((string) $match[3]),
                'innerHTML' => (string) $match[3],
            ];
        }

        return $blocks;
    }
}

if (!function_exists('serialize_blocks')) {
    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    function serialize_blocks(array $blocks): string {
        $content = '';

        foreach ($blocks as $block) {
            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';

            if ('' === $name) {
                $content .= (string) ($block['innerHTML'] ?? '');
                continue;
            }

            $comment_name = str_starts_with($name, 'core/') ? substr($name, 5) : $name;
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            $attrs_json = [] === $attrs ? '' : ' ' . (string) json_encode($attrs, JSON_UNESCAPED_SLASHES);
            $inner = (string) ($block['innerHTML'] ?? '');

            $content .= sprintf('<!-- wp:%s%s -->%s<!-- /wp:%s -->', $comment_name, $attrs_json, $inner, $comment_name);
        }

        return $content;
    }
}

if (!function_exists('get_post_statuses')) {
    /**
     * @return array<string, string>
     */
    function get_post_statuses(): array {
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
    function update_post_meta(int $post_id, string $meta_key, mixed $meta_value): bool {
        $GLOBALS['awpt_test_post_meta_updates'][$post_id][$meta_key] = $meta_value;

        return true;
    }
}

if (!function_exists('add_filter')) {
    /**
     * @param callable $callback Filter callback.
     */
    function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
        unset($accepted_args);
        $GLOBALS['awpt_test_filters'][$hook_name][$priority][] = $callback;

        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed {
        $by_priority = $GLOBALS['awpt_test_filters'][$hook_name] ?? [];

        if ([] === $by_priority) {
            return $value;
        }

        ksort($by_priority);

        foreach ($by_priority as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }

        return $value;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data): string|false {
        return json_encode($data);
    }
}

if (!class_exists('WP_Error')) {
    /**
     * Minimal WP_Error stand-in sufficient for provider error handling tests.
     */
    class WP_Error {
        private string $code;
        private string $message;
        private mixed $data;

        public function __construct(string $code = '', string $message = '', mixed $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data(): mixed {
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'draft';
        public string $post_title = '';
        public string $post_name = '';
        public string $post_content = '';
        public string $post_excerpt = '';
        public string $post_modified_gmt = '';
        public string $post_date_gmt = '';
        public int $post_author = 1;
    }
}

if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID = 0;
        public string $user_login = '';
        public string $user_nicename = '';
        public string $display_name = '';
        public string $user_email = '';
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata(int $user_id): ?WP_User {
        $user = $GLOBALS['awpt_test_users'][$user_id] ?? null;

        return $user instanceof WP_User ? $user : null;
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by(string $field, string $value): ?WP_User {
        foreach ($GLOBALS['awpt_test_users'] as $user) {
            if (!$user instanceof WP_User) {
                continue;
            }

            $candidate = match ($field) {
                'login', 'slug' => $user->user_login,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
                default => null,
            };

            if (null !== $candidate && $candidate === $value) {
                return $user;
            }
        }

        return null;
    }
}

if (!function_exists('wp_trim_words')) {
    function wp_trim_words(string $text, int $num_words = 55, string $more = '…'): string {
        $words = preg_split('/\s+/', trim($text)) ?: [];

        if (count($words) <= $num_words) {
            return trim($text);
        }

        return implode(' ', array_slice($words, 0, $num_words)) . $more;
    }
}

if (!function_exists('get_post')) {
    function get_post(int $post_id): ?WP_Post {
        return $GLOBALS['awpt_test_posts'][$post_id] ?? null;
    }
}

if (!function_exists('wp_attachment_is_image')) {
    function wp_attachment_is_image(int $attachment_id): bool {
        $attachment = get_post($attachment_id);

        return (
            $attachment instanceof WP_Post
            && 'attachment' === $attachment->post_type
            && ($GLOBALS['awpt_test_attachment_is_image'][$attachment_id] ?? false)
        );
    }
}

if (!function_exists('get_post_thumbnail_id')) {
    function get_post_thumbnail_id(int $post_id): int {
        $thumbnail_id = $GLOBALS['awpt_test_post_thumbnails'][$post_id] ?? 0;

        return is_int($thumbnail_id) ? $thumbnail_id : (int) $thumbnail_id;
    }
}

if (!function_exists('set_post_thumbnail')) {
    function set_post_thumbnail(int $post_id, int $attachment_id): bool {
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
    function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed {
        unset($single);

        return $GLOBALS['awpt_test_post_meta_updates'][$post_id][$key] ?? '';
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $meta_key): bool {
        unset($GLOBALS['awpt_test_post_meta_updates'][$post_id][$meta_key]);

        return true;
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(int|WP_Post $post): string {
        if ($post instanceof WP_Post) {
            return $post->post_title;
        }

        $loaded = get_post($post);

        return $loaded instanceof WP_Post ? $loaded->post_title : '';
    }
}

if (!function_exists('get_preview_post_link')) {
    function get_preview_post_link(int|WP_Post $post): string {
        $post_id = $post instanceof WP_Post ? $post->ID : $post;

        return 'https://example.test/?p=' . $post_id . '&preview=true';
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(int|WP_Post $post): string {
        $post_id = $post instanceof WP_Post ? $post->ID : $post;

        return 'https://example.test/?p=' . $post_id;
    }
}

if (!function_exists('wp_count_posts')) {
    function wp_count_posts(string $post_type = 'post', string $perm = ''): object {
        unset($perm);

        $counts = (object) [
            'publish' => 0,
            'draft' => 0,
            'pending' => 0,
            'private' => 0,
            'future' => 0,
            'trash' => 0,
            'auto-draft' => 0,
        ];

        foreach ($GLOBALS['awpt_test_posts'] as $post) {
            if (!$post instanceof WP_Post || $post->post_type !== $post_type) {
                continue;
            }

            $status = $post->post_status;

            if (property_exists($counts, $status)) {
                ++$counts->{$status};
            }
        }

        return $counts;
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query {
        /** @var list<WP_Post> */
        public array $posts = [];

        public int $found_posts = 0;

        /**
         * @param array<string, mixed> $args
         */
        public function __construct(array $args = []) {
            if ([] === $args) {
                return;
            }

            $post_types = $args['post_type'] ?? 'post';
            $post_types = is_array($post_types) ? $post_types : [$post_types];
            $statuses = $args['post_status'] ?? ['publish'];
            $statuses = is_array($statuses) ? $statuses : [$statuses];
            $limit = max(1, (int) ($args['posts_per_page'] ?? 10));
            $offset = max(0, (int) ($args['offset'] ?? 0));
            $author_id = (int) ($args['author'] ?? 0);
            $search = trim((string) ($args['s'] ?? ''));
            $orderby = sanitize_key((string) ($args['orderby'] ?? 'modified'));
            $order = 'ASC' === strtoupper((string) ($args['order'] ?? 'DESC')) ? 'ASC' : 'DESC';
            $matches = [];

            foreach ($GLOBALS['awpt_test_posts'] as $post) {
                if (
                    !$post instanceof WP_Post
                    || !in_array($post->post_type, $post_types, true)
                    || !in_array($post->post_status, $statuses, true)
                ) {
                    continue;
                }

                if ($author_id > 0 && (int) $post->post_author !== $author_id) {
                    continue;
                }

                if (
                    '' !== $search
                    && !str_contains(strtolower($post->post_title), strtolower($search))
                    && !str_contains(strtolower($post->post_content), strtolower($search))
                ) {
                    continue;
                }

                $matches[] = $post;
            }

            usort($matches, static function (WP_Post $left, WP_Post $right) use ($orderby, $order): int {
                $value = match ($orderby) {
                    'date' => strcmp($left->post_date_gmt, $right->post_date_gmt),
                    'title' => strcmp($left->post_title, $right->post_title),
                    'author' => $left->post_author <=> $right->post_author,
                    'type' => strcmp($left->post_type, $right->post_type),
                    default => strcmp($left->post_modified_gmt, $right->post_modified_gmt),
                };

                return 'ASC' === $order ? $value : -$value;
            });

            $this->found_posts = count($matches);
            $sliced = array_slice($matches, $offset, $limit);

            if (($args['fields'] ?? '') === 'ids') {
                $this->posts = array_map(static fn(WP_Post $post): int => $post->ID, $sliced);

                return;
            }

            $this->posts = $sliced;
        }
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string {
        if ('version' === $show) {
            return '6.9';
        }

        return '';
    }
}

if (!function_exists('wp_trash_post')) {
    function wp_trash_post(int $post_id): int|false {
        $GLOBALS['awpt_test_trashed_posts'][] = $post_id;

        return $post_id;
    }
}
