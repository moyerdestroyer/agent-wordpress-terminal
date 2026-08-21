<?php

/**
 * Admin page registration.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Admin;

use AWPT\Agent\ConnectorToolSupportChecker;
use AWPT\Agent\OpenAIProvider;
use AWPT\Support\ConnectorCatalog;
use AWPT\Support\ConnectorInspector;
use AWPT\Support\ConnectorSelection;
use AWPT\Support\Environment;
use AWPT\Support\ProposalAbilities;
use Kucrut\Vite;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Registers the AWPT admin terminal page.
 */
final class Page {
    /**
     * Admin page slug.
     */
    public const SLUG = 'awpt-terminal';

    /**
     * Direct-key providers rendered with a simple "API key" field.
     *
     * Keyed by provider ID; value is [label, description, key option name, key field label].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const API_KEY_PROVIDERS = [
        'openrouter' => [
            'OpenRouter',
            'Routes to virtually any major model through a single API key. No other setup required.',
            'awpt_openrouter_api_key',
            'OpenRouter API key',
        ],
        'openai' => [
            'OpenAI',
            'Connect directly to OpenAI. Model selection is automatic. Leave the key blank to reuse an OpenAI key already configured under Settings > Connectors, if you have one.',
            'awpt_openai_api_key',
            'OpenAI API key',
        ],
    ];

    /**
     * Review bridge protocol version (must match assets/reviewBridge.tsx).
     */
    public const REVIEW_BRIDGE_VERSION = 2;

    /**
     * Hook admin integration.
     */
    public function init(): void {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        // Preferred Dufresne contract; slug-based enqueue remains for one release as fallback.
        add_action('dufresne_wp_plugin_enqueue_review_assets', [$this, 'enqueue_review_bridge'], 10, 1);
    }

    /**
     * Register the settings submenu page.
     */
    public function register_menu(): void {
        add_options_page(
            __('Agent Terminal', 'agent-wordpress-terminal'),
            __('Agent Terminal', 'agent-wordpress-terminal'),
            'manage_options',
            self::SLUG,
            [$this, 'render_page'],
        );
    }

    /**
     * Register AI connection settings.
     */
    public function register_settings(): void {
        register_setting('awpt_settings', 'awpt_provider', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_provider'],
            'default' => '',
        ]);

        foreach (self::API_KEY_PROVIDERS as [, , $key_option]) {
            $this->register_secret_setting($key_option);
        }

        register_setting('awpt_settings', 'awpt_openrouter_model', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_openrouter_model'],
            'default' => 'deepseek/deepseek-v4-pro',
        ]);
        register_setting('awpt_settings', 'awpt_openai_model', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_openai_model'],
            'default' => OpenAIProvider::DEFAULT_MODEL,
        ]);
    }

    /**
     * Register a secret (API key) option using the shared clear/keep sanitize contract.
     */
    private function register_secret_setting(string $option): void {
        register_setting('awpt_settings', $option, [
            'type' => 'string',
            'sanitize_callback' => fn(mixed $value): string => $this->sanitize_secret(
                $value,
                $option,
                'awpt_clear_' . $option,
            ),
            'default' => '',
        ]);
    }

    /**
     * Enqueue admin assets on the terminal page.
     *
     * Legacy fallback: also load the review bridge when the Dufresne Review
     * admin hook matches the historical slug. Prefer
     * `dufresne_wp_plugin_enqueue_review_assets` once both plugins ship that action.
     *
     * @param string $hook_suffix Current admin page hook suffix.
     */
    public function enqueue_assets(string $hook_suffix): void {
        $is_terminal_page = 'settings_page_' . self::SLUG === $hook_suffix;
        $is_legacy_dufresne_review = 'dufresne-wp-plugin_page_dufresne-wp-plugin-review' === $hook_suffix;

        if ($is_legacy_dufresne_review && !did_action('dufresne_wp_plugin_enqueue_review_assets')) {
            $this->enqueue_review_bridge($hook_suffix);

            return;
        }

        if (!$is_terminal_page) {
            return;
        }

        Vite\enqueue_asset((string) AWPT_PLUGIN_DIR . 'build', 'assets/admin.tsx', [
            'handle' => 'awpt-admin',
            'dependencies' => ['wp-components', 'wp-element', 'wp-data', 'wp-api-fetch', 'wp-i18n'],
            'in-footer' => true,
        ]);

        $selection = new ConnectorSelection();

        wp_localize_script('awpt-admin', 'awptSettings', [
            'apiNamespace' => AWPT_REST_NAMESPACE,
            'pluginUrl' => AWPT_PLUGIN_URL,
            'version' => AWPT_VERSION,
            'nonce' => wp_create_nonce('wp_rest'),
            'environment' => Environment::status(),
            'connection' => $selection->active_connection_summary(),
            'proposalTools' => ProposalAbilities::names(),
            // Same Improve briefs as the review bridge so terminal Plan/Improve stay aligned.
            'improvePagePrompt' => \AWPT\Support\ImprovePagePrompt::text(),
            'improvePageEvaluatePrompt' => \AWPT\Support\ImprovePagePrompt::evaluate_text(),
            'improvePageActPrompt' => \AWPT\Support\ImprovePagePrompt::act_text(),
            'improvePageReviewBrief' => \AWPT\Support\ImprovePagePrompt::review_brief(),
        ]);
    }

    /**
     * Enqueue the focused-post review bridge (Dufresne Review and compatible hosts).
     *
     * @param string $hook_suffix Current admin page hook suffix (unused; reserved for hosts).
     */
    public function enqueue_review_bridge(string $hook_suffix = ''): void {
        unset($hook_suffix);

        if (wp_script_is('awpt-review-bridge', 'enqueued') || wp_script_is('awpt-review-bridge', 'registered')) {
            // Still allow localize if Vite registered the handle without our data.
            if (!wp_scripts()->get_data('awpt-review-bridge', 'data')) {
                $this->localize_review_bridge();
            }

            return;
        }

        Vite\enqueue_asset(AWPT_PLUGIN_DIR . 'build', 'assets/reviewBridge.tsx', [
            'handle' => 'awpt-review-bridge',
            'dependencies' => ['wp-components', 'wp-element', 'wp-api-fetch', 'wp-i18n'],
            'in-footer' => true,
        ]);
        $this->localize_review_bridge();
    }

    private function localize_review_bridge(): void {
        $selection = new ConnectorSelection();
        wp_localize_script('awpt-review-bridge', 'awptSettings', [
            'apiNamespace' => AWPT_REST_NAMESPACE,
            'pluginUrl' => AWPT_PLUGIN_URL,
            'version' => AWPT_VERSION,
            'nonce' => wp_create_nonce('wp_rest'),
            'connection' => $selection->active_connection_summary(),
            'reviewBridgeVersion' => self::REVIEW_BRIDGE_VERSION,
            'improvePagePrompt' => \AWPT\Support\ImprovePagePrompt::text(),
            'improvePageEvaluatePrompt' => \AWPT\Support\ImprovePagePrompt::evaluate_text(),
            'improvePageActPrompt' => \AWPT\Support\ImprovePagePrompt::act_text(),
            'improvePageReviewBrief' => \AWPT\Support\ImprovePagePrompt::review_brief(),
            'hasActiveDomainPack' => [] !== \AWPT\Domain\DomainPackRegistry::instance()->active(),
        ]);
    }

    /**
     * Render the admin mount point.
     */
    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'agent-wordpress-terminal'));
        }

        $catalog = new ConnectorCatalog();
        $selection = new ConnectorSelection($catalog);
        // Connectors already offered as a direct-key provider above (e.g. "openai") are
        // excluded here so the same provider is never presented as two separate radio
        // options — AWPT already reuses that connector's key automatically.
        $connectors = array_values(array_filter(
            $catalog->list_installed_connectors(),
            static fn(array $connector): bool => !in_array(
                $connector['id'],
                ConnectorCatalog::DIRECT_PROVIDER_IDS,
                true,
            ),
        ));
        $selected_provider = $selection->normalize_provider_option((string) get_option('awpt_provider', ''));
        $connectors_url = $catalog->connectors_admin_url();
        $tool_support_checker = new ConnectorToolSupportChecker();

        ?>
        <div class="wrap awpt-admin-page">
            <div id="awpt-root"></div>
            <details class="awpt-settings-panel">
                <summary><?php echo esc_html(__('AI connection', 'agent-wordpress-terminal')); ?></summary>
                <form method="post" action="options.php">
                    <?php settings_fields('awpt_settings'); ?>
                    <h3><?php echo esc_html(__('Direct API providers', 'agent-wordpress-terminal')); ?></h3>
                    <p class="description">
                        <?php echo
                            esc_html(__(
                                'These providers work on every WordPress version with just your own API key — no connector plugin or WordPress AI Client required. They are the guaranteed baseline for AWPT.',
                                'agent-wordpress-terminal',
                            ))
                        ; ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php foreach (self::API_KEY_PROVIDERS as $provider_id => [
                                $label,
                                $description,
                                $key_option,
                                $key_label,
                            ]): ?>
                                <?php $this->render_direct_provider_rows(
                                    $provider_id,
                                    [
                                        'label' => __($label, 'agent-wordpress-terminal'),
                                        'description' => __($description, 'agent-wordpress-terminal'),
                                        'key_option' => $key_option,
                                        'key_label' => __($key_label, 'agent-wordpress-terminal'),
                                    ],
                                    $selected_provider,
                                ); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h3><?php echo esc_html(__('WordPress Connectors (optional)', 'agent-wordpress-terminal')); ?></h3>
                    <p class="description">
                        <?php echo
                            esc_html(__(
                                'If this site has WordPress Core Connectors (WordPress 7.0+) or a connector-enabling companion plugin with an AI provider configured, you can select it here as an accelerator. This is never required — the direct providers above always work.',
                                'agent-wordpress-terminal',
                            ))
                        ; ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <?php echo esc_html(__('AI connector', 'agent-wordpress-terminal')); ?>
                                </th>
                                <td>
                                    <?php if ([] === $connectors): ?>
                                        <p class="description">
                                            <?php echo
                                                esc_html(__(
                                                    'No AI connector plugins are installed yet. This is fine — use one of the direct providers above instead, or install a connector plugin and configure it under Settings > Connectors.',
                                                    'agent-wordpress-terminal',
                                                ))
                                            ; ?>
                                        </p>
                                    <?php else: ?>
                                        <fieldset class="awpt-connector-list">
                                            <legend class="screen-reader-text">
                                                <?php echo esc_html(__('AI connector', 'agent-wordpress-terminal')); ?>
                                            </legend>
                                            <?php foreach ($connectors as $connector): ?>
                                                <?php

                                                $input_id = 'awpt_provider_' . sanitize_html_class($connector['id']);
                                                $status_class =
                                                    'awpt-connector-status--'
                                                    . sanitize_html_class($connector['status']);
                                                $supports_tools =
                                                    !$connector['ready']
                                                    || $tool_support_checker->supports_tools($connector['id']);
                                                ?>
                                                <label class="awpt-connector-option" for="<?php echo
                                                    esc_attr($input_id)
                                                ; ?>">
                                                    <input
                                                        id="<?php echo esc_attr($input_id); ?>"
                                                        type="radio"
                                                        name="awpt_provider"
                                                        value="<?php echo esc_attr($connector['id']); ?>"
                                                        <?php checked($selected_provider, $connector['id']); ?>
                                                    />
                                                    <span class="awpt-connector-option__body">
                                                        <span class="awpt-connector-option__title">
                                                            <?php echo esc_html($connector['name']); ?>
                                                        </span>
                                                        <?php if ('' !== $connector['description']): ?>
                                                            <span class="awpt-connector-option__description">
                                                                <?php echo esc_html($connector['description']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (!$supports_tools): ?>
                                                            <span class="awpt-connector-option__description awpt-connector-option__warning">
                                                                <?php echo
                                                                    esc_html(__(
                                                                        'No model available here supports AWPT\'s tool calling — AWPT will automatically use OpenRouter instead when needed.',
                                                                        'agent-wordpress-terminal',
                                                                    ))
                                                                ; ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="awpt-connector-status <?php echo
                                                        esc_attr($status_class)
                                                    ; ?>">
                                                        <?php echo esc_html($connector['status_label']); ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                        <p class="description">
                                            <?php

                                            printf(
                                                /* translators: %s: Settings > Connectors admin URL. */
                                                wp_kses(
                                                    __(
                                                        'Connector credentials are managed in <a href="%s">Settings &gt; Connectors</a>. AWPT uses each connector\'s default model.',
                                                        'agent-wordpress-terminal',
                                                    ),
                                                    ['a' => ['href' => []]],
                                                ),
                                                esc_url($connectors_url),
                                            );
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('Save AI connection', 'agent-wordpress-terminal')); ?>
                </form>
            </details>
        </div>
        <?php
    }

    /**
     * Render the radio + API key rows for a direct-key provider.
     *
     * @param array{label: string, description: string, key_option: string, key_label: string} $meta
     */
    private function render_direct_provider_rows(string $provider_id, array $meta, string $selected_provider): void {
        $ready =
            '' !== (string) get_option($meta['key_option'], '')
            || '' !== new ConnectorInspector()->resolve_default_provider_api_key($provider_id);
        $input_id = 'awpt_provider_' . sanitize_html_class($provider_id);
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($meta['label']); ?></th>
            <td>
                <label class="awpt-connector-option" for="<?php echo esc_attr($input_id); ?>">
                    <input
                        id="<?php echo esc_attr($input_id); ?>"
                        type="radio"
                        name="awpt_provider"
                        value="<?php echo esc_attr($provider_id); ?>"
                        <?php checked($selected_provider, $provider_id); ?>
                    />
                    <span class="awpt-connector-option__body">
                        <span class="awpt-connector-option__title"><?php echo esc_html($meta['label']); ?></span>
                        <span class="awpt-connector-option__description"><?php echo
                            esc_html($meta['description'])
                        ; ?></span>
                    </span>
                    <span class="awpt-connector-status <?php echo
                        esc_attr($ready ? 'awpt-connector-status--ready' : 'awpt-connector-status--not_configured')
                    ; ?>">
                        <?php echo
                            esc_html(
                                $ready
                                    ? __('Ready', 'agent-wordpress-terminal')
                                    : __('Key not configured', 'agent-wordpress-terminal'),
                            )
                        ; ?>
                    </span>
                </label>
            </td>
        </tr>
        <?php $this->render_secret_field($meta['key_option'], $meta['key_label']); ?>
        <?php if ('openrouter' === $provider_id): ?>
            <?php $this->render_openrouter_model_field(); ?>
        <?php endif; ?>
        <?php if ('openai' === $provider_id): ?>
            <?php $this->render_openai_model_field(); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Render the explicit OpenRouter model setting.
     */
    private function render_openrouter_model_field(): void { ?>
        <tr>
            <th scope="row">
                <label for="awpt_openrouter_model">
                    <?php echo esc_html(__('OpenRouter chat model', 'agent-wordpress-terminal')); ?>
                </label>
            </th>
            <td>
                <input
                    id="awpt_openrouter_model"
                    class="regular-text"
                    type="text"
                    name="awpt_openrouter_model"
                    value="<?php echo
                        esc_attr($this->sanitize_openrouter_model(get_option(
                            'awpt_openrouter_model',
                            'deepseek/deepseek-v4-pro',
                        )))
                    ; ?>"
                    placeholder="deepseek/deepseek-v4-pro"
                />
                <p class="description">
                    <?php echo
                        esc_html(__(
                            'Defaults to DeepSeek V4 Pro (deepseek/deepseek-v4-pro) for multi-step tool loops and page composition. When this model cannot read images, AWPT automatically uses an OpenRouter vision model once to describe them, then returns control to DeepSeek. Image-bearing turns therefore include an additional, variably priced request. Override the primary model with deepseek/deepseek-v4-flash for lower cost, or any exact OpenRouter model ID.',
                            'agent-wordpress-terminal',
                        ))
                    ; ?>
                </p>
            </td>
        </tr>
        <?php }

    /**
     * Render the OpenAI chat model setting.
     */
    private function render_openai_model_field(): void { ?>
        <tr>
            <th scope="row">
                <label for="awpt_openai_model">
                    <?php echo esc_html(__('OpenAI chat model', 'agent-wordpress-terminal')); ?>
                </label>
            </th>
            <td>
                <input
                    id="awpt_openai_model"
                    class="regular-text"
                    type="text"
                    name="awpt_openai_model"
                    value="<?php echo
                        esc_attr($this->sanitize_openai_model(get_option(
                            'awpt_openai_model',
                            OpenAIProvider::DEFAULT_MODEL,
                        )))
                    ; ?>"
                    placeholder="<?php echo esc_attr(OpenAIProvider::DEFAULT_MODEL); ?>"
                />
                <p class="description">
                    <?php echo
                        esc_html(__(
                            'Defaults to gpt-5.6-luna for Improve / tool loops via Chat Completions. GPT-5.6 models require reasoning_effort=none when tools are present (handled automatically). Alternatives: gpt-5.6-terra, chat-latest.',
                            'agent-wordpress-terminal',
                        ))
                    ; ?>
                </p>
            </td>
        </tr>
        <?php }

    /**
     * Render a password field row with a "clear saved key" checkbox.
     */
    private function render_secret_field(string $option, string $label): void { ?>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr($option); ?>"><?php echo esc_html($label); ?></label>
            </th>
            <td>
                <input
                    id="<?php echo esc_attr($option); ?>"
                    class="regular-text"
                    type="password"
                    name="<?php echo esc_attr($option); ?>"
                    value=""
                    autocomplete="off"
                    placeholder="<?php echo esc_attr($this->secret_placeholder($option)); ?>"
                />
                <?php if ('' !== (string) get_option($option, '')): ?>
                    <label>
                        <input type="checkbox" name="awpt_clear_<?php echo esc_attr($option); ?>" value="1" />
                        <?php echo esc_html(__('Clear saved key', 'agent-wordpress-terminal')); ?>
                    </label>
                <?php endif; ?>
            </td>
        </tr>
        <?php }

    /**
     * Sanitize provider option.
     *
     * @param mixed $value Raw value.
     */
    public function sanitize_provider(mixed $value): string {
        $provider = sanitize_key((string) $value);
        $catalog = new ConnectorCatalog();

        if ($catalog->is_valid_provider($provider)) {
            return $provider;
        }

        return $catalog->resolve_default_provider();
    }

    /**
     * Sanitize an OpenRouter model ID without restricting valid provider namespaces.
     *
     * @param mixed $value Raw model ID.
     */
    public function sanitize_openrouter_model(mixed $value): string {
        $model = trim(sanitize_text_field((string) $value));

        if ('' === $model || in_array($model, ['openrouter/auto', 'openrouter/auto-beta'], true)) {
            return 'deepseek/deepseek-v4-pro';
        }

        return preg_match('/^[A-Za-z0-9._:\/-]{1,191}$/', $model) ? $model : 'deepseek/deepseek-v4-pro';
    }

    /**
     * Sanitize an OpenAI model ID.
     *
     * @param mixed $value Raw model ID.
     */
    public function sanitize_openai_model(mixed $value): string {
        $model = trim(sanitize_text_field((string) $value));

        if ('' === $model) {
            return OpenAIProvider::DEFAULT_MODEL;
        }

        return preg_match('/^[A-Za-z0-9._:\/-]{1,191}$/', $model) ? $model : OpenAIProvider::DEFAULT_MODEL;
    }

    /**
     * Return placeholder text for secret inputs without exposing the stored value.
     */
    private function secret_placeholder(string $option): string {
        return '' === (string) get_option($option, '')
            ? __('Enter API key', 'agent-wordpress-terminal')
            : __('Saved; leave blank to keep', 'agent-wordpress-terminal');
    }

    /**
     * Sanitize secret option values while preserving existing values on blank submit.
     *
     * @param mixed  $value Raw value.
     * @param string $option Option name.
     * @param string $clear_field Clear checkbox field name.
     */
    private function sanitize_secret(mixed $value, string $option, string $clear_field): string {
        $raw_clear = $_POST[$clear_field] ?? '';
        $raw_clear = is_array($raw_clear) ? '' : $raw_clear;
        $clear = '1' === sanitize_text_field(wp_unslash($raw_clear));

        if ($clear) {
            return '';
        }

        $secret = trim(sanitize_text_field((string) $value));

        return '' === $secret ? (string) get_option($option, '') : $secret;
    }
}
