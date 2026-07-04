<?php

/**
 * awpt/sideload-media ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\MessageRepository;
use AWPT\Support\MessageUrlExtractor;
use AWPT\Support\UrlIntegrityResolver;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Downloads a remote file and imports it into the WordPress Media Library.
 *
 * This only ever creates a new, unattached Media Library item — it never modifies
 * existing content, so it is safe to auto-execute. Actually embedding the resulting
 * attachment into a post still goes through the normal
 * propose-content-update → approve → apply flow.
 */
final class SideloadMedia
{
    private MediaSideloadValidator $validator;
    private OpenGraphMediaUrlExtractor $og_extractor;
    private MessageUrlExtractor $message_url_extractor;
    private UrlIntegrityResolver $url_integrity_resolver;
    private MessageRepository $messages;

    public function __construct(
        ?MediaSideloadValidator $validator = null,
        ?OpenGraphMediaUrlExtractor $og_extractor = null,
        ?MessageUrlExtractor $message_url_extractor = null,
        ?UrlIntegrityResolver $url_integrity_resolver = null,
        ?MessageRepository $messages = null,
    ) {
        $this->validator = $validator ?? new MediaSideloadValidator();
        $this->og_extractor = $og_extractor ?? new OpenGraphMediaUrlExtractor();
        $this->message_url_extractor = $message_url_extractor ?? new MessageUrlExtractor();
        $this->url_integrity_resolver = $url_integrity_resolver ?? new UrlIntegrityResolver();
        $this->messages = $messages ?? new MessageRepository();
    }

    /**
     * Register the ability.
     */
    public function register(): void
    {
        AbilityRegistrar::register([
            'name' => 'awpt/sideload-media',
            'label' => __('Import Media from URL', 'agent-wordpress-terminal'),
            'description' => __(
                'Downloads a remote image or video URL and adds it to the WordPress Media Library, '
                . 'returning its attachment ID and hosted URL. Automatically resolves share/preview '
                . 'page links (e.g. Tenor, Giphy) to their underlying direct media file.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => [
                        'type' => 'integer',
                        'description' => __('AWPT session ID.', 'agent-wordpress-terminal'),
                    ],
                    'url' => [
                        'type' => 'string',
                        'description' => __(
                            'Direct URL to the image or video file to import (e.g. ending in .gif, .png, .jpg, .webp, .mp4).',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional title/caption for the Media Library item.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'alt_text' => [
                        'type' => 'string',
                        'description' => __('Optional alt text for accessibility.', 'agent-wordpress-terminal'),
                    ],
                ],
                'required' => ['url'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_sideload'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => false,
                'destructive' => false,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function can_sideload(array $input): bool
    {
        unset($input);

        return current_user_can('upload_files') && current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error
    {
        $url = trim((string) ($input['url'] ?? ''));
        $url = $this->recover_url_if_corrupted($url, (int) ($input['session_id'] ?? 0));
        $validation_error = $this->validator->validate_url($url);

        if (null !== $validation_error) {
            return new \WP_Error('awpt_invalid_media_url', $validation_error, ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $download = $this->download_media($url);

        if (is_wp_error($download)) {
            return $download;
        }

        [$tmp_file, $resolved_url] = $download;

        $file_array = [
            'name' => $this->sideload_file_name($resolved_url),
            'tmp_name' => $tmp_file,
        ];

        $description = sanitize_text_field((string) ($input['description'] ?? ''));
        $attachment_id = media_handle_sideload($file_array, 0, $description);

        if (is_wp_error($attachment_id)) {
            if (file_exists($tmp_file)) {
                wp_delete_file($tmp_file);
            }

            return new \WP_Error('awpt_media_sideload_failed', $attachment_id->get_error_message(), ['status' => 502]);
        }

        $alt_text = trim((string) ($input['alt_text'] ?? ''));

        if ('' !== $alt_text) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        }

        return [
            'id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'mime_type' => get_post_mime_type($attachment_id),
            'source_url' => $resolved_url,
        ];
    }

    /**
     * Download a URL, transparently resolving share/preview pages (Tenor, Giphy, and
     * similar "GIF link" pages) to their underlying direct media file via Open Graph /
     * Twitter Card meta tags when the URL doesn't point straight at a media file.
     *
     * @return array{0: string, 1: string}|\WP_Error Tuple of [temp file path, resolved URL].
     */
    private function download_media(string $url): array|\WP_Error
    {
        // download_url() uses wp_safe_remote_get() internally, which already rejects
        // requests to private/reserved IP ranges (basic SSRF protection) for us.
        $tmp_file = download_url($url, 30);

        if (is_wp_error($tmp_file)) {
            return new \WP_Error(
                'awpt_media_download_failed',
                sprintf(
                    /* translators: 1: attempted URL, 2: underlying download error message */
                    __('Could not download %1$s: %2$s', 'agent-wordpress-terminal'),
                    $url,
                    $tmp_file->get_error_message(),
                ),
                ['status' => 502],
            );
        }

        $head = (string) file_get_contents($tmp_file, false, null, 0, 8192);

        if ($this->og_extractor->looks_like_html($head)) {
            $resolved = $this->resolve_share_page($tmp_file, $url);

            if (is_wp_error($resolved)) {
                return $resolved;
            }

            [$tmp_file, $url] = $resolved;
        } else {
            $extension_error = $this->validator->validate_direct_media_url($url);

            if (null !== $extension_error) {
                wp_delete_file($tmp_file);

                return new \WP_Error('awpt_invalid_media_url', $extension_error, ['status' => 400]);
            }
        }

        if (!$this->validator->is_size_allowed((int) filesize($tmp_file))) {
            wp_delete_file($tmp_file);

            return new \WP_Error(
                'awpt_media_too_large',
                sprintf(
                    /* translators: %s: maximum allowed size in megabytes */
                    __('The media file exceeds the %s MB import limit.', 'agent-wordpress-terminal'),
                    number_format_i18n((int) round((MediaSideloadValidator::MAX_BYTES / 1024) / 1024)),
                ),
                ['status' => 413],
            );
        }

        return [$tmp_file, $url];
    }

    /**
     * The requested URL turned out to be an HTML share/preview page. Extract the real
     * media URL from it and re-download that instead.
     *
     * @return array{0: string, 1: string}|\WP_Error Tuple of [temp file path, resolved URL].
     */
    private function resolve_share_page(string $html_tmp_file, string $original_url): array|\WP_Error
    {
        $html = (string) file_get_contents($html_tmp_file, false, null, 0, 500_000);
        wp_delete_file($html_tmp_file);

        $resolved_url = $this->og_extractor->extract($html);

        if (null === $resolved_url || $resolved_url === $original_url) {
            return new \WP_Error(
                'awpt_media_not_direct_link',
                __(
                    'That URL points to a share or preview page, not a direct media file, and no embedded media '
                    . 'link could be found on it. Try a link that points straight at the image/GIF/video file '
                    . '(often on a "media." subdomain), or right-click the media itself and choose '
                    . '"Copy image address" / "Copy video address".',
                    'agent-wordpress-terminal',
                ),
                ['status' => 422],
            );
        }

        $validation_error = $this->validator->validate_direct_media_url($resolved_url);

        if (null !== $validation_error) {
            return new \WP_Error('awpt_invalid_media_url', $validation_error, ['status' => 400]);
        }

        $tmp_file = download_url($resolved_url, 30);

        if (is_wp_error($tmp_file)) {
            return new \WP_Error(
                'awpt_media_download_failed',
                sprintf(
                    /* translators: 1: resolved media URL, 2: underlying download error message */
                    __(
                        'Found a media link (%1$s) on that page but could not download it: %2$s',
                        'agent-wordpress-terminal',
                    ),
                    $resolved_url,
                    $tmp_file->get_error_message(),
                ),
                ['status' => 502],
            );
        }

        return [$tmp_file, $resolved_url];
    }

    /**
     * Guard against a common LLM failure mode: dropping punctuation (most often
     * parentheses) when retyping a long URL into a tool call, even within the same
     * turn it was given in. Recovers the byte-exact original from recent user
     * messages when the model's URL is a punctuation-corrupted match for one of them,
     * rather than trusting the model's regenerated copy.
     */
    private function recover_url_if_corrupted(string $url, int $session_id): string
    {
        if ('' === $url || $session_id <= 0) {
            return $url;
        }

        $known_urls = [];

        foreach ($this->messages->recent_user_message_contents($session_id) as $message) {
            $known_urls = array_merge($known_urls, $this->message_url_extractor->extract($message));
        }

        if ([] === $known_urls) {
            return $url;
        }

        return $this->url_integrity_resolver->resolve($url, $known_urls);
    }

    /**
     * Derive a safe local file name for the sideloaded file.
     */
    private function sideload_file_name(string $url): string
    {
        $name = wp_basename((string) parse_url($url, PHP_URL_PATH));

        if ('' === $name) {
            $name = 'awpt-media.' . $this->validator->extension_from_url($url);
        }

        return sanitize_file_name($name);
    }
}
