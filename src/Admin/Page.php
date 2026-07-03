<?php

/**
 * Admin page registration.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Admin;

use AWPT\Support\ConnectorCatalog;
use AWPT\Support\ConnectorSelection;
use AWPT\Support\Environment;
use Kucrut\Vite;

defined('ABSPATH') || exit();

/**
 * Registers the AWPT admin terminal page.
 */
final class Page
{
    /**
     * Admin page slug.
     */
    public const SLUG = 'awpt-terminal';

    /**
     * Hook admin integration.
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Register the settings submenu page.
     */
    public function register_menu(): void
    {
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
    public function register_settings(): void
    {
        register_setting('awpt_settings', 'awpt_provider', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_provider'],
            'default' => '',
        ]);

        register_setting('awpt_settings', 'awpt_openrouter_api_key', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_openrouter_api_key'],
            'default' => '',
        ]);
    }

    /**
     * Enqueue admin assets on the terminal page.
     *
     * @param string $hook_suffix Current admin page hook suffix.
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        if ('settings_page_' . self::SLUG !== $hook_suffix) {
            return;
        }

        Vite\enqueue_asset(AWPT_PLUGIN_DIR . 'build', 'assets/admin.tsx', [
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
        ]);
    }

    /**
     * Render the admin mount point.
     */
    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'agent-wordpress-terminal'));
        }

        $catalog = new ConnectorCatalog();
        $selection = new ConnectorSelection($catalog);
        $connectors = $catalog->list_installed_connectors();
        $selected_provider = $selection->normalize_provider_option((string) get_option('awpt_provider', ''));
        $connectors_url = $catalog->connectors_admin_url();
        $openrouter_ready = '' !== (string) get_option('awpt_openrouter_api_key', '');

        ?>
        <div class="wrap awpt-admin-page">
            <div id="awpt-root"></div>
            <details class="awpt-settings-panel">
                <summary><?php echo esc_html(__('AI connection', 'agent-wordpress-terminal')); ?></summary>
                <form method="post" action="options.php">
                    <?php settings_fields('awpt_settings'); ?>
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
                                                    'No AI connector plugins are installed yet. Install a connector plugin, configure it under Settings > Connectors, or use OpenRouter below.',
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
                            <tr>
                                <th scope="row">
                                    <?php echo esc_html(__('OpenRouter', 'agent-wordpress-terminal')); ?>
                                </th>
                                <td>
                                    <label class="awpt-connector-option" for="awpt_provider_openrouter">
                                        <input
                                            id="awpt_provider_openrouter"
                                            type="radio"
                                            name="awpt_provider"
                                            value="openrouter"
                                            <?php checked($selected_provider, 'openrouter'); ?>
                                        />
                                        <span class="awpt-connector-option__body">
                                            <span class="awpt-connector-option__title">
                                                <?php echo esc_html(__('OpenRouter', 'agent-wordpress-terminal')); ?>
                                            </span>
                                            <span class="awpt-connector-option__description">
                                                <?php echo
                                                    esc_html(__(
                                                        'Use your own OpenRouter API key when a WordPress connector is not available.',
                                                        'agent-wordpress-terminal',
                                                    ))
                                                ; ?>
                                            </span>
                                        </span>
                                        <span class="awpt-connector-status <?php echo
                                            esc_attr(
                                                $openrouter_ready
                                                    ? 'awpt-connector-status--ready'
                                                    : 'awpt-connector-status--not_configured',
                                            )
                                        ; ?>">
                                            <?php echo
                                                esc_html(
                                                    $openrouter_ready
                                                        ? __('Ready', 'agent-wordpress-terminal')
                                                        : __('Key not configured', 'agent-wordpress-terminal'),
                                                )
                                            ; ?>
                                        </span>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="awpt_openrouter_api_key"><?php echo
                                        esc_html(__('OpenRouter API key', 'agent-wordpress-terminal'))
                                    ; ?></label>
                                </th>
                                <td>
                                    <input
                                        id="awpt_openrouter_api_key"
                                        class="regular-text"
                                        type="password"
                                        name="awpt_openrouter_api_key"
                                        value=""
                                        autocomplete="off"
                                        placeholder="<?php echo
                                            esc_attr($this->secret_placeholder('awpt_openrouter_api_key'))
                                        ; ?>"
                                    />
                                    <?php if ('' !== (string) get_option('awpt_openrouter_api_key', '')): ?>
                                        <label>
                                            <input type="checkbox" name="awpt_clear_openrouter_api_key" value="1" />
                                            <?php echo esc_html(__('Clear saved key', 'agent-wordpress-terminal')); ?>
                                        </label>
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
     * Sanitize provider option.
     *
     * @param mixed $value Raw value.
     */
    public function sanitize_provider(mixed $value): string
    {
        $provider = sanitize_key((string) $value);
        $catalog = new ConnectorCatalog();

        if ($catalog->is_valid_provider($provider)) {
            return $provider;
        }

        return $catalog->resolve_default_provider();
    }

    /**
     * Sanitize OpenRouter API key input.
     *
     * @param mixed $value Raw value.
     */
    public function sanitize_openrouter_api_key(mixed $value): string
    {
        return $this->sanitize_secret($value, 'awpt_openrouter_api_key', 'awpt_clear_openrouter_api_key');
    }

    /**
     * Return placeholder text for secret inputs without exposing the stored value.
     */
    private function secret_placeholder(string $option): string
    {
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
    private function sanitize_secret(mixed $value, string $option, string $clear_field): string
    {
        $raw_clear = $_POST[$clear_field] ?? '';
        $raw_clear = is_array($raw_clear) ? '' : (string) $raw_clear;
        $clear = '1' === sanitize_text_field(wp_unslash($raw_clear));

        if ($clear) {
            return '';
        }

        $secret = trim(sanitize_text_field((string) $value));

        return '' === $secret ? (string) get_option($option, '') : $secret;
    }
}
