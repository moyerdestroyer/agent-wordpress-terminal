<?php

/**
 * Development-time AI request/response logger.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Database\AiLogRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Always-on logger for provider calls, tool I/O, and embeddings.
 *
 * Writes NDJSON under uploads/awpt-logs/ and mirrors rows into awpt_ai_logs.
 * Secrets and base64 image payloads are redacted before persistence.
 */
final class AiLogger {
    public const EVENT_PROVIDER_COMPLETE = 'provider.complete';
    public const EVENT_TOOL_EXECUTE = 'tool.execute';
    public const EVENT_EMBEDDING = 'embedding.request';

    private const RETENTION_DAYS = 7;
    private const MAX_ENCODED_BYTES = 1_500_000;
    private const MAX_STRING_CHARS = 120_000;
    private const PRUNE_TRANSIENT = 'awpt_ai_log_prune_at';

    /**
     * @param array<string, mixed> $payload
     */
    public static function log(string $event, array $payload): void {
        $event = strtolower(trim($event));
        $event = (string) preg_replace('/[^a-z0-9._-]/', '', $event);

        if ('' === $event) {
            $event = 'unknown';
        }

        $entry = [
            'ts' => gmdate('c'),
            'event' => $event,
            'session_id' => max(0, (int) ($payload['session_id'] ?? 0)),
            'turn_id' => sanitize_key((string) ($payload['turn_id'] ?? '')),
            'tool_round' => max(0, (int) ($payload['tool_round'] ?? 0)),
            'provider' => (string) ($payload['provider'] ?? ''),
            'model' => (string) ($payload['model'] ?? ''),
            'outcome' => sanitize_key((string) ($payload['outcome'] ?? 'success')),
            'error_code' => (string) ($payload['error_code'] ?? ''),
            'duration_ms' => max(0, (int) ($payload['duration_ms'] ?? 0)),
            'request' => self::sanitize_value($payload['request'] ?? null),
            'response' => self::sanitize_value($payload['response'] ?? null),
            'meta' => self::sanitize_value(is_array($payload['meta'] ?? null) ? $payload['meta'] : []),
        ];

        /**
         * Fires after an AI log entry is normalized (secrets/images redacted).
         *
         * @param array<string, mixed> $entry Normalized log entry.
         */
        if (function_exists('do_action')) {
            do_action('awpt_ai_log', $entry);
        }

        self::write_file($entry);
        new AiLogRepository()->store($entry);
        self::maybe_prune();
    }

    /**
     * Log a provider complete() hop (request messages + response/error).
     *
     * @param array{
     *     provider: string,
     *     messages: array<int, array<string, mixed>>,
     *     tools?: array<int, array<string, mixed>>,
     *     options?: array<string, mixed>,
     *     result: array<string, mixed>|\WP_Error,
     *     started_at: float,
     *     meta?: array<string, mixed>
     * } $context
     */
    public static function log_provider_complete(array $context): void {
        $messages = is_array($context['messages'] ?? null) ? $context['messages'] : [];
        $tools = is_array($context['tools'] ?? null) ? $context['tools'] : [];
        $options = is_array($context['options'] ?? null) ? $context['options'] : [];
        $result = $context['result'] ?? null;
        $started_at = is_float($context['started_at'] ?? null) ? $context['started_at'] : microtime(true);
        $meta = is_array($context['meta'] ?? null) ? $context['meta'] : [];
        $error = is_wp_error($result) ? $result : null;
        $success = is_array($result) ? $result : [];
        $log_options = $options;
        unset($log_options['api_key'], $log_options['authorization']);

        self::log(self::EVENT_PROVIDER_COMPLETE, [
            'session_id' => (int) ($options['session_id'] ?? 0),
            'turn_id' => (string) ($options['turn_id'] ?? ''),
            'tool_round' => (int) ($options['tool_round'] ?? 0),
            'provider' => (string) ($context['provider'] ?? ''),
            'model' => (string) ($success['model'] ?? $meta['model'] ?? ''),
            'outcome' => null !== $error ? 'error' : 'success',
            'error_code' => null !== $error ? $error->get_error_code() : '',
            'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
            'request' => [
                'messages' => $messages,
                'tools' => $tools,
                'options' => $log_options,
            ],
            'response' => null !== $error
                ? [
                    'error_code' => $error->get_error_code(),
                    'error_message' => $error->get_error_message(),
                    'error_data' => $error->get_error_data(),
                ]
                : [
                    'content' => $success['content'] ?? '',
                    'finish_reason' => $success['finish_reason'] ?? '',
                    'raw_tool_calls' => $success['raw_tool_calls'] ?? [],
                    'message' => $success['message'] ?? null,
                    'usage' => $success['usage'] ?? [],
                ],
            'meta' => $meta,
        ]);
    }

    /**
     * @param array{
     *     session_id: int,
     *     turn_id?: string,
     *     tool_name: string,
     *     input: array<array-key, mixed>,
     *     status: string,
     *     output: mixed,
     *     started_at: float,
     *     meta?: array<string, mixed>
     * } $context
     */
    public static function log_tool_execute(array $context): void {
        $status = (string) ($context['status'] ?? '');
        $meta = is_array($context['meta'] ?? null) ? $context['meta'] : [];
        $started_at = is_float($context['started_at'] ?? null) ? $context['started_at'] : microtime(true);
        $input = is_array($context['input'] ?? null) ? $context['input'] : [];

        self::log(self::EVENT_TOOL_EXECUTE, [
            'session_id' => (int) ($context['session_id'] ?? 0),
            'turn_id' => (string) ($context['turn_id'] ?? ''),
            'tool_round' => (int) ($meta['tool_round'] ?? 0),
            'provider' => '',
            'model' => '',
            'outcome' => 'success' === $status ? 'success' : 'error',
            'error_code' => 'success' === $status ? '' : sanitize_key($status),
            'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
            'request' => [
                'tool_name' => (string) ($context['tool_name'] ?? ''),
                'input' => $input,
            ],
            'response' => [
                'status' => $status,
                'output' => $context['output'] ?? null,
            ],
            'meta' => $meta,
        ]);
    }

    /**
     * @param array{
     *     provider: string,
     *     model: string,
     *     texts: list<string>,
     *     outcome: string,
     *     error_code?: string,
     *     started_at: float,
     *     meta?: array<string, mixed>
     * } $context
     */
    public static function log_embedding(array $context): void {
        $texts = [];

        if (is_array($context['texts'] ?? null)) {
            foreach ($context['texts'] as $text) {
                if (is_string($text)) {
                    $texts[] = $text;
                }
            }
        }

        $meta = is_array($context['meta'] ?? null) ? $context['meta'] : [];
        $started_at = is_float($context['started_at'] ?? null) ? $context['started_at'] : microtime(true);

        self::log(self::EVENT_EMBEDDING, [
            'session_id' => 0,
            'turn_id' => '',
            'tool_round' => 0,
            'provider' => (string) ($context['provider'] ?? ''),
            'model' => (string) ($context['model'] ?? ''),
            'outcome' => sanitize_key((string) ($context['outcome'] ?? 'success')),
            'error_code' => (string) ($context['error_code'] ?? ''),
            'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
            'request' => [
                'input_count' => count($texts),
                'input_previews' => array_map(
                    static fn(string $text): string => self::truncate_string($text, 500),
                    array_slice($texts, 0, 20),
                ),
            ],
            'response' => [
                'vector_count' => (int) ($meta['vector_count'] ?? 0),
                'vector_dims' => (int) ($meta['vector_dims'] ?? 0),
            ],
            'meta' => $meta,
        ]);
    }

    /**
     * Recursively redact secrets and oversized image payloads.
     */
    public static function sanitize_value(mixed $value, int $depth = 0): mixed {
        if ($depth > 24) {
            return '[max depth]';
        }

        if (is_string($value)) {
            return self::sanitize_string($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            $key_string = is_string($key) ? strtolower($key) : (string) $key;

            if (self::is_secret_key($key_string)) {
                $out[$key] = '[redacted]';
                continue;
            }

            if (
                is_array($item)
                && (
                    'image_url' === ($item['type'] ?? null)
                    || (
                        isset($item['url'])
                        && is_string($item['url'])
                        && self::looks_like_data_url($item['url'])
                    )
                )
            ) {
                $out[$key] = self::sanitize_image_part($item);
                continue;
            }

            if ('image_url' === $key_string && is_array($item)) {
                $out[$key] = self::sanitize_image_part(['image_url' => $item]);
                continue;
            }

            $out[$key] = self::sanitize_value($item, $depth + 1);
        }

        return $out;
    }

    /** @param array<array-key, mixed> $part */
    private static function sanitize_image_part(array $part): array {
        $url = '';

        if (is_array($part['image_url'] ?? null)) {
            $url = (string) ($part['image_url']['url'] ?? '');
            $part['image_url']['url'] = self::redact_data_url($url);
        } elseif (is_string($part['image_url'] ?? null)) {
            $url = (string) $part['image_url'];
            $part['image_url'] = self::redact_data_url($url);
        } elseif (isset($part['url'])) {
            $url = (string) $part['url'];
            $part['url'] = self::redact_data_url($url);
        }

        $part['_awpt_omitted_bytes'] = strlen($url);

        return $part;
    }

    private static function sanitize_string(string $value): string {
        if (self::looks_like_data_url($value)) {
            return self::redact_data_url($value);
        }

        if (preg_match('/Bearer\s+[A-Za-z0-9._\-]+/i', $value) === 1) {
            $value = (string) preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $value);
        }

        return self::truncate_string($value, self::MAX_STRING_CHARS);
    }

    private static function redact_data_url(string $url): string {
        if (!self::looks_like_data_url($url)) {
            return self::truncate_string($url, 2_000);
        }

        $comma = strpos($url, ',');
        $header = false === $comma ? 'data:' : substr($url, 0, $comma);

        return sprintf('%s,[omitted %d bytes]', $header, strlen($url));
    }

    private static function looks_like_data_url(string $value): bool {
        return str_starts_with($value, 'data:image') || str_starts_with($value, 'data:application/octet-stream');
    }

    private static function is_secret_key(string $key): bool {
        return (
            in_array(
                $key,
                [
                    'authorization',
                    'api_key',
                    'apikey',
                    'api-key',
                    'openai_api_key',
                    'openrouter_api_key',
                    'password',
                    'secret',
                    'token',
                    'access_token',
                ],
                true,
            )
            || str_contains($key, 'api_key')
            || str_contains($key, 'secret')
        );
    }

    private static function truncate_string(string $value, int $max): string {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . sprintf('…[truncated %d chars]', strlen($value) - $max);
    }

    /** @param array<string, mixed> $entry */
    private static function write_file(array $entry): void {
        $dir = self::log_directory();

        if ('' === $dir) {
            return;
        }

        if (!is_dir($dir)) {
            if (function_exists('wp_mkdir_p')) {
                if (!wp_mkdir_p($dir)) {
                    return;
                }
            } elseif (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return;
            }
        }

        $path = $dir . '/ai-' . gmdate('Y-m-d') . '.log';
        $encoded = wp_json_encode($entry);

        if (!is_string($encoded)) {
            $encoded = '{"event":"encode_failed","ts":"' . gmdate('c') . '"}';
        }

        if (strlen($encoded) > self::MAX_ENCODED_BYTES) {
            $encoded = wp_json_encode([
                'ts' => $entry['ts'] ?? gmdate('c'),
                'event' => $entry['event'] ?? 'unknown',
                'session_id' => $entry['session_id'] ?? 0,
                'turn_id' => $entry['turn_id'] ?? '',
                'outcome' => 'truncated',
                'error_code' => 'awpt_ai_log_too_large',
                'meta' => [
                    'original_bytes' => strlen($encoded),
                    'provider' => $entry['provider'] ?? '',
                    'model' => $entry['model'] ?? '',
                ],
            ]);

            if (!is_string($encoded)) {
                return;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($path, $encoded . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function log_directory(): string {
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();

            if (
                is_array($uploads)
                && (!isset($uploads['error']) || false === $uploads['error'] || '' === $uploads['error'])
                && is_string($uploads['basedir'] ?? null)
            ) {
                return trailingslashit((string) $uploads['basedir']) . 'awpt-logs';
            }
        }

        if (defined('WP_CONTENT_DIR')) {
            return trailingslashit((string) WP_CONTENT_DIR) . 'uploads/awpt-logs';
        }

        return '';
    }

    private static function maybe_prune(): void {
        $next = (int) get_transient(self::PRUNE_TRANSIENT);

        if ($next > time()) {
            return;
        }

        set_transient(self::PRUNE_TRANSIENT, time() + 3600, 3600);
        new AiLogRepository()->prune_older_than(self::RETENTION_DAYS);
        self::prune_files(self::RETENTION_DAYS);
    }

    private static function prune_files(int $days): void {
        $dir = self::log_directory();

        if ('' === $dir || !is_dir($dir)) {
            return;
        }

        $cutoff = time() - ($days * 86_400);
        $files = glob($dir . '/ai-*.log');

        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (!is_string($file) || !is_file($file)) {
                continue;
            }

            $mtime = filemtime($file);

            if (false !== $mtime && $mtime < $cutoff) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink($file);
            }
        }
    }
}
