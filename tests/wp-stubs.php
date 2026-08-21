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

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
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
    $GLOBALS['awpt_test_attachment_urls'] = [];
    $GLOBALS['awpt_test_attachment_image_urls'] = [];
    $GLOBALS['awpt_test_attached_files'] = [];
    $GLOBALS['awpt_test_attachment_mime_types'] = [];
    $GLOBALS['awpt_test_trashed_posts'] = [];
    $GLOBALS['awpt_test_scheduled'] = [];
    $GLOBALS['awpt_test_users'] = [];
    $GLOBALS['awpt_test_filters'] = [];
    $GLOBALS['awpt_test_abilities'] = [];
    $GLOBALS['awpt_test_post_types'] = [
        'post',
        'page',
        'attachment',
        'wp_block',
        'wp_template',
        'wp_template_part',
    ];
    $GLOBALS['awpt_test_http_response'] = null;
    $GLOBALS['awpt_test_http_get_response'] = null;
    $GLOBALS['awpt_test_http_requests'] = [];
    $GLOBALS['awpt_test_stylesheet'] = 'civicpress';
    $GLOBALS['awpt_test_template'] = 'civicpress';
    $GLOBALS['awpt_test_theme_names'] = ['civicpress' => 'CivicPress'];
    $GLOBALS['awpt_test_page_templates'] = [];
    $GLOBALS['awpt_test_theme_json_data'] = [
        'settings' => [
            'color' => [
                'palette' => [
                    ['slug' => 'primary', 'name' => 'Primary', 'color' => '#14532d'],
                ],
            ],
        ],
    ];
    $GLOBALS['awpt_test_registered_patterns'] = [];
    $GLOBALS['awpt_test_transients'] = [];
    $GLOBALS['awpt_test_uuid_counter'] = 0;

    if (class_exists('WP_Block_Type_Registry', false)) {
        WP_Block_Type_Registry::get_instance()->reset();
    }
}

/** @return list<array<string, mixed>> */
function awpt_test_improve_tree_evidence(int $post_id = 828): array {
    return [[
        'tool' => 'awpt/read-block-tree',
        'status' => 'success',
        'input' => ['id' => $post_id],
        'output' => [
            'id' => $post_id,
            'top_level_sections' => [
                ['path' => '0', 'heading' => 'Page', 'role' => 'body'],
                ['path' => '1', 'heading' => 'Details', 'role' => 'body'],
                ['path' => '2', 'heading' => 'More', 'role' => 'body'],
                ['path' => '3', 'heading' => 'Additional', 'role' => 'body'],
                ['path' => '4', 'heading' => 'End', 'role' => 'body'],
            ],
        ],
    ]];
}

awpt_test_reset_state();

if (!class_exists('WP_Ability', false)) {
    class WP_Ability {
        public int $execute_count = 0;

        /** @param array<string, mixed> $config */
        public function __construct(
            private string $name,
            private array $config,
        ) {}

        public function get_name(): string {
            return $this->name;
        }

        public function get_label(): string {
            return (string) ($this->config['label'] ?? $this->name);
        }

        public function get_description(): string {
            return (string) ($this->config['description'] ?? $this->name);
        }

        public function get_category(): string {
            return (string) ($this->config['category'] ?? '');
        }

        public function get_input_schema(): mixed {
            return $this->config['input_schema'] ?? [];
        }

        public function get_output_schema(): mixed {
            return $this->config['output_schema'] ?? [];
        }

        /** @return array<string, mixed> */
        public function get_meta(): array {
            return is_array($this->config['meta'] ?? null) ? $this->config['meta'] : [];
        }

        public function execute(mixed $input): mixed {
            ++$this->execute_count;
            $callback = $this->config['execute_callback'] ?? null;

            return is_callable($callback) ? $callback($input) : $input;
        }
    }
}

if (!class_exists('WP_Block_Type', false)) {
    class WP_Block_Type {
        /** @var array<string, mixed>|null */
        public ?array $attributes;

        /** @param array<string, mixed> $args */
        public function __construct(
            public string $name,
            array $args = [],
        ) {
            $this->attributes = is_array($args['attributes'] ?? null) ? $args['attributes'] : null;
        }
    }
}

if (!class_exists('WP_Block_Type_Registry', false)) {
    final class WP_Block_Type_Registry {
        private static ?self $instance = null;

        /** @var array<string, WP_Block_Type> */
        private array $types = [];

        public static function get_instance(): self {
            self::$instance ??= new self();

            return self::$instance;
        }

        /** @param array<string, mixed> $args */
        public function register(string $name, array $args = []): WP_Block_Type {
            $type = new WP_Block_Type($name, $args);
            $this->types[$name] = $type;

            return $type;
        }

        public function get_registered(string $name): ?WP_Block_Type {
            return $this->types[$name] ?? null;
        }

        public function is_registered(string $name): bool {
            return [] === $this->types || array_key_exists($name, $this->types);
        }

        public function reset(): void {
            $this->types = [];
        }
    }
}

if (!function_exists('wp_register_ability')) {
    /** @param array<string, mixed> $config */
    function wp_register_ability(string $name, array $config): WP_Ability {
        $ability = new WP_Ability($name, $config);
        $GLOBALS['awpt_test_abilities'][$name] = $ability;

        return $ability;
    }
}

if (!function_exists('wp_get_ability')) {
    function wp_get_ability(string $name): ?WP_Ability {
        $ability = $GLOBALS['awpt_test_abilities'][$name] ?? null;

        return $ability instanceof WP_Ability ? $ability : null;
    }
}

if (!function_exists('wp_get_abilities')) {
    /** @return array<string, WP_Ability> */
    function wp_get_abilities(array $args = []): array {
        unset($args);

        return $GLOBALS['awpt_test_abilities'];
    }
}

if (!function_exists('wp_has_ability')) {
    function wp_has_ability(string $name): bool {
        return null !== wp_get_ability($name);
    }
}

if (!function_exists('wp_unregister_ability')) {
    function wp_unregister_ability(string $name): bool {
        $exists = array_key_exists($name, $GLOBALS['awpt_test_abilities']);
        unset($GLOBALS['awpt_test_abilities'][$name]);

        return $exists;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!class_exists('wpdb', false)) {
    /**
     * Minimal in-memory wpdb stand-in for repository unit tests.
     */
    class wpdb {
        public string $prefix = 'wp_';

        public int $insert_id = 1;

        public function prepare(string $query, mixed ...$args): string {
            unset($args);

            return $query;
        }

        public function get_var(string $query): string|int|null {
            unset($query);

            return null;
        }

        public function get_row(string $query, string $output = ARRAY_A): ?array {
            unset($query, $output);

            return null;
        }

        /** @return list<array<string, mixed>>|list<string> */
        public function get_results(string $query, string $output = ARRAY_A): array {
            unset($query, $output);

            return [];
        }

        /** @return list<string> */
        public function get_col(string $query): array {
            unset($query);

            return [];
        }

        /**
         * @param array<string, mixed> $data
         * @param array<int, string>|string|null $format
         */
        public function insert(string $table, array $data, array|string|null $format = null): int|false {
            unset($table, $data, $format);

            return 1;
        }

        /**
         * @param array<string, mixed> $data
         * @param array<string, mixed> $where
         */
        public function update(
            string $table,
            array $data,
            array $where,
            array|string|null $format = null,
            array|string|null $where_format = null,
        ): int|false {
            unset($table, $data, $where, $format, $where_format);

            return 1;
        }

        public function query(string $query): int|false {
            unset($query);

            return 0;
        }
    }
}

if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
    $GLOBALS['wpdb'] = new wpdb();
}

if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed {
        return $GLOBALS['awpt_test_transients'][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool {
        unset($expiration);
        $GLOBALS['awpt_test_transients'][$key] = $value;

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool {
        unset($GLOBALS['awpt_test_transients'][$key]);

        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string|int {
        unset($gmt);

        return 'timestamp' === $type ? 1_750_000_000 : '2026-07-20 12:00:00';
    }
}

if (!function_exists('get_stylesheet')) {
    function get_stylesheet(): string {
        return (string) $GLOBALS['awpt_test_stylesheet'];
    }
}

if (!function_exists('get_template')) {
    function get_template(): string {
        return (string) $GLOBALS['awpt_test_template'];
    }
}

if (!class_exists('WP_Theme')) {
    class WP_Theme {
        public function __construct(
            private string $stylesheet,
        ) {}

        public function exists(): bool {
            return '' !== $this->stylesheet;
        }

        public function get(string $field): string {
            return 'Name' === $field
                ? (string) ($GLOBALS['awpt_test_theme_names'][$this->stylesheet] ?? $this->stylesheet)
                : '';
        }

        public function get_stylesheet_directory(): string {
            return ABSPATH . 'themes/' . $this->stylesheet;
        }

        /** @return array<string, string> */
        public function get_page_templates(mixed $post = null, string $post_type = 'page'): array {
            unset($post, $post_type);

            return is_array($GLOBALS['awpt_test_page_templates'] ?? null) ? $GLOBALS['awpt_test_page_templates'] : [];
        }
    }
}

if (!function_exists('wp_get_theme')) {
    function wp_get_theme(string $stylesheet = ''): WP_Theme {
        return new WP_Theme('' !== $stylesheet ? $stylesheet : get_stylesheet());
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string {
        return rtrim($value, '/\\') . '/';
    }
}

if (!function_exists('wp_upload_dir')) {
    /**
     * @return array{basedir: string, baseurl: string, error: false|string}
     */
    function wp_upload_dir(): array {
        $basedir = is_string($GLOBALS['awpt_test_upload_basedir'] ?? null)
            ? (string) $GLOBALS['awpt_test_upload_basedir']
            : sys_get_temp_dir() . '/awpt-test-uploads';

        return [
            'basedir' => $basedir,
            'baseurl' => 'https://example.test/uploads',
            'error' => false,
        ];
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool {
        if (is_dir($target)) {
            return true;
        }

        return mkdir($target, 0755, true) || is_dir($target);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook_name, mixed ...$args): void {
        unset($hook_name, $args);
    }
}

if (!class_exists('WP_Theme_JSON')) {
    class WP_Theme_JSON {
        /** @param array<string, mixed> $data */
        public function __construct(
            private array $data = [],
        ) {}

        /** @return array<string, mixed> */
        public function get_raw_data(): array {
            return $this->data;
        }
    }
}

if (!class_exists('WP_Theme_JSON_Resolver')) {
    class WP_Theme_JSON_Resolver {
        public static function get_merged_data(string $origin = 'custom'): WP_Theme_JSON {
            unset($origin);

            return new WP_Theme_JSON(
                is_array($GLOBALS['awpt_test_theme_json_data'] ?? null) ? $GLOBALS['awpt_test_theme_json_data'] : [],
            );
        }
    }
}

if (!class_exists('WP_Block_Patterns_Registry')) {
    class WP_Block_Patterns_Registry {
        private static ?self $instance = null;

        public static function get_instance(): self {
            self::$instance ??= new self();

            return self::$instance;
        }

        /** @return list<array<string, mixed>> */
        public function get_all_registered(): array {
            return (
                is_array($GLOBALS['awpt_test_registered_patterns'] ?? null)
                    ? array_values($GLOBALS['awpt_test_registered_patterns'])
                    : []
            );
        }
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed {
        return $GLOBALS['awpt_test_options'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value, ?bool $autoload = null): bool {
        unset($autoload);
        $GLOBALS['awpt_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $name, mixed $value, string $deprecated = '', bool $autoload = true): bool {
        unset($deprecated, $autoload);

        if (array_key_exists($name, $GLOBALS['awpt_test_options'])) {
            return false;
        }

        $GLOBALS['awpt_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool {
        $existed = array_key_exists($name, $GLOBALS['awpt_test_options']);
        unset($GLOBALS['awpt_test_options'][$name]);

        return $existed;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = [], bool $wp_error = false): int {
        unset($args, $wp_error);
        $before = count($GLOBALS['awpt_test_scheduled'] ?? []);
        $GLOBALS['awpt_test_scheduled'] = array_values(array_filter(
            $GLOBALS['awpt_test_scheduled'] ?? [],
            static fn(array $event): bool => ($event['hook'] ?? '') !== $hook,
        ));

        return max(0, $before - count($GLOBALS['awpt_test_scheduled']));
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = [], bool $wp_error = false): bool {
        unset($wp_error);
        $GLOBALS['awpt_test_scheduled'][] = [
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
        ];

        return true;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        unset($domain);

        return $text;
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string {
        unset($domain);

        return 1 === $number ? $single : $plural;
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

if (!function_exists('rest_sanitize_boolean')) {
    function rest_sanitize_boolean(bool|int|string $value): bool {
        if (is_string($value)) {
            return !in_array(strtolower($value), ['false', '0'], true);
        }

        return (bool) $value;
    }
}

if (!function_exists('absint')) {
    function absint(mixed $value): int {
        return abs((int) $value);
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

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string {
        return 'https://example.test/' . ltrim($path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(string $key, string $value, string $url): string {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . rawurlencode($key) . '=' . rawurlencode($value);
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(
        int $length = 12,
        bool $special_chars = true,
        bool $extra_special_chars = false,
    ): string {
        unset($special_chars, $extra_special_chars);
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';

        for ($index = 0; $index < $length; ++$index) {
            $password .= $alphabet[$index % strlen($alphabet)];
        }

        return $password;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string {
        $counter = ++$GLOBALS['awpt_test_uuid_counter'];

        return sprintf('00000000-0000-4000-8000-%012d', $counter);
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

if (!function_exists('wp_http_validate_url')) {
    function wp_http_validate_url(string $url): string|false {
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
    }
}

if (!function_exists('url_to_postid')) {
    function url_to_postid(string $url): int {
        return (int) ($GLOBALS['awpt_test_url_to_postid'][$url] ?? 0);
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists(string $post_type): bool {
        return in_array($post_type, $GLOBALS['awpt_test_post_types'], true);
    }
}

if (!function_exists('wp_remote_post')) {
    /**
     * @param array<string, mixed> $args
     */
    function wp_remote_post(string $url, array $args = []): array|WP_Error {
        $GLOBALS['awpt_test_http_requests'][] = ['url' => $url, 'args' => $args];
        $response = $GLOBALS['awpt_test_http_response'];

        return is_array($response) || $response instanceof WP_Error
            ? $response
            : new WP_Error('http_request_failed', 'No fake HTTP response configured.');
    }
}

if (!function_exists('wp_remote_get')) {
    /**
     * @param array<string, mixed> $args
     */
    function wp_remote_get(string $url, array $args = []): array|WP_Error {
        $GLOBALS['awpt_test_http_requests'][] = ['url' => $url, 'args' => $args, 'method' => 'GET'];
        $response = $GLOBALS['awpt_test_http_get_response'];

        if (is_callable($response)) {
            $response = $response($url, $args);
        }

        return is_array($response) || $response instanceof WP_Error
            ? $response
            : new WP_Error('http_request_failed', 'No fake HTTP GET response configured.');
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    /**
     * @param array<string, mixed>|WP_Error $response
     */
    function wp_remote_retrieve_response_code(array|WP_Error $response): int {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    /**
     * @param array<string, mixed>|WP_Error $response
     */
    function wp_remote_retrieve_body(array|WP_Error $response): string {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
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
        $pattern = '/<!--\s*(\/)?wp:([a-z0-9_\/-]+)(?:\s+(\{.*?\}))?\s*(\/)?-->/s';

        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return (
                '' === trim($content)
                    ? []
                    : [[
                        'blockName' => null,
                        'attrs' => [],
                        'innerBlocks' => [],
                        'innerHTML' => $content,
                        'innerContent' => [$content],
                    ]]
            );
        }

        $blocks = [];
        $stack = [];
        $cursor = 0;
        $append_text = static function (array &$frame, string $text): void {
            $frame['block']['innerHTML'] .= $text;
            $last = array_key_last($frame['block']['innerContent']);

            if (null !== $last && is_string($frame['block']['innerContent'][$last])) {
                $frame['block']['innerContent'][$last] .= $text;
            } else {
                $frame['block']['innerContent'][] = $text;
            }
        };
        $append_block = static function (array $block) use (&$blocks, &$stack): void {
            if ([] === $stack) {
                $blocks[] = $block;

                return;
            }

            $parent = array_key_last($stack);
            $stack[$parent]['block']['innerBlocks'][] = $block;
            $stack[$parent]['block']['innerContent'][] = null;
        };
        $append_freeform = static function (string $text) use (&$blocks): void {
            if ('' === $text) {
                return;
            }

            $blocks[] = [
                'blockName' => null,
                'attrs' => [],
                'innerBlocks' => [],
                'innerHTML' => $text,
                'innerContent' => [$text],
            ];
        };

        foreach ($matches as $match) {
            $token = (string) $match[0][0];
            $offset = (int) $match[0][1];
            $text = substr($content, $cursor, $offset - $cursor);

            if ([] === $stack) {
                $append_freeform($text);
            } else {
                $current = array_key_last($stack);
                $append_text($stack[$current], $text);
            }

            $is_closing = '/' === (string) ($match[1][0] ?? '');
            $is_self_closing = '/' === (string) ($match[4][0] ?? '');
            $name = (string) $match[2][0];

            if ($is_closing) {
                $frame = array_pop($stack);

                if (is_array($frame)) {
                    $append_block($frame['block']);
                }
            } else {
                $decoded = json_decode((string) ($match[3][0] ?? ''), true);
                $block = [
                    'blockName' => str_contains($name, '/') ? $name : 'core/' . $name,
                    'attrs' => is_array($decoded) ? $decoded : [],
                    'innerBlocks' => [],
                    'innerHTML' => '',
                    'innerContent' => [],
                ];

                if ($is_self_closing) {
                    $append_block($block);
                } else {
                    $stack[] = ['name' => $name, 'block' => $block];
                }
            }

            $cursor = $offset + strlen($token);
        }

        $tail = substr($content, $cursor);

        if ([] === $stack) {
            $append_freeform($tail);
        } else {
            $current = array_key_last($stack);
            $append_text($stack[$current], $tail);

            while ([] !== $stack) {
                $frame = array_pop($stack);

                if (is_array($frame)) {
                    $append_block($frame['block']);
                }
            }
        }

        return $blocks;
    }
}

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachment_id): string|false {
        return (
            $GLOBALS['awpt_test_attachment_urls'][$attachment_id]
            ?? ($attachment_id > 0 ? 'https://example.test/uploads/image-' . $attachment_id . '.jpg' : false)
        );
    }
}

if (!function_exists('wp_get_attachment_image_url')) {
    function wp_get_attachment_image_url(int $attachment_id, string|array $size = 'thumbnail'): string|false {
        unset($size);

        return $GLOBALS['awpt_test_attachment_image_urls'][$attachment_id] ?? wp_get_attachment_url($attachment_id);
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string {
        return rtrim($value, '/\\');
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string {
        return esc_url_raw($url);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
            $inner_blocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
            $inner_content = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [];

            if ([] === $inner_content) {
                $inner = (string) ($block['innerHTML'] ?? '') . serialize_blocks($inner_blocks);
            } else {
                $inner = '';
                $child_index = 0;

                foreach ($inner_content as $part) {
                    if (null !== $part) {
                        $inner .= (string) $part;
                        continue;
                    }

                    if (array_key_exists($child_index, $inner_blocks)) {
                        $inner .= serialize_blocks([$inner_blocks[$child_index]]);
                    }

                    ++$child_index;
                }
            }

            $content .= sprintf('<!-- wp:%s%s -->%s<!-- /wp:%s -->', $comment_name, $attrs_json, $inner, $comment_name);
        }

        return $content;
    }
}

if (!function_exists('serialize_block')) {
    /**
     * @param array<string, mixed> $block
     */
    function serialize_block(array $block): string {
        return serialize_blocks([$block]);
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

if (!function_exists('get_posts')) {
    /**
     * @param array<string, mixed> $args
     * @return list<WP_Post>
     */
    function get_posts(array $args = []): array {
        $post_types = is_array($args['post_type'] ?? null)
            ? array_map('strval', $args['post_type'])
            : [(string) ($args['post_type'] ?? 'post')];
        $statuses = is_array($args['post_status'] ?? null)
            ? array_map('strval', $args['post_status'])
            : [(string) ($args['post_status'] ?? 'publish')];

        return array_values(array_filter(
            $GLOBALS['awpt_test_posts'],
            static fn(mixed $post): bool => (
                $post instanceof WP_Post
                && in_array($post->post_type, $post_types, true)
                && in_array($post->post_status, $statuses, true)
            ),
        ));
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

if (!function_exists('attachment_url_to_postid')) {
    function attachment_url_to_postid(string $url): int {
        foreach ($GLOBALS['awpt_test_attachment_urls'] ?? [] as $attachment_id => $attachment_url) {
            if ($url === $attachment_url) {
                return (int) $attachment_id;
            }
        }

        return 0;
    }
}

if (!function_exists('get_attached_file')) {
    function get_attached_file(int $attachment_id): string|false {
        $path = $GLOBALS['awpt_test_attached_files'][$attachment_id] ?? false;

        return is_string($path) ? $path : false;
    }
}

if (!function_exists('get_post_mime_type')) {
    function get_post_mime_type(int|WP_Post $post): string|false {
        $id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;

        return $GLOBALS['awpt_test_attachment_mime_types'][$id] ?? false;
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

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string {
        return 'nonce-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $action);
    }
}

if (!function_exists('wp_nonce_tick')) {
    function wp_nonce_tick(string|int $action = -1): float {
        unset($action);

        return 123.0;
    }
}

if (!function_exists('wp_hash')) {
    function wp_hash(string $data, string $scheme = 'auth'): string {
        return hash('sha256', $scheme . '|' . $data);
    }
}

if (!function_exists('get_preview_post_link')) {
    /**
     * @param int|WP_Post $post
     * @param array<string, mixed> $query_args
     */
    function get_preview_post_link(int|WP_Post $post, array $query_args = [], string $preview_link = ''): string {
        $post_id = $post instanceof WP_Post ? $post->ID : $post;
        $url = '' !== $preview_link ? $preview_link : 'https://example.test/?p=' . $post_id;
        $query_args['preview'] = 'true';

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query_args);
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
