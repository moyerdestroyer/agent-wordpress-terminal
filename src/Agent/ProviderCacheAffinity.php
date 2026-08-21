<?php

/**
 * Sticky prompt-cache affinity keys for chat-completions providers.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Provider routing and prompt-cache helpers. These are deliberately not used as
 * conversation state; AWPT owns the durable session event log.
 */
final class ProviderCacheAffinity {
    public const STABLE_BOUNDARY = "\n<!-- awpt:volatile-context -->\n";

    /**
     * Session-stable OpenRouter routing key. A turn-scoped value defeats sticky
     * routing on every follow-up, which is exactly where prefix reuse matters.
     *
     * @param array<string, mixed> $options Provider complete() options.
     */
    public static function key(array $options): string {
        $session = (int) ($options['session_id'] ?? 0);
        if ($session <= 0) {
            return '';
        }

        $site = function_exists('home_url') ? home_url('/') : 'awpt';

        return 'awpt-' . substr(hash('sha256', $site . "\0" . $session), 0, 32);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     * @return array{payload: array<string, mixed>, headers: array<string, string>}
     */
    public static function apply_openrouter(array $payload, array $headers, string $key): array {
        if ('' === $key) {
            return ['payload' => $payload, 'headers' => $headers];
        }

        $payload['session_id'] = $key;
        return ['payload' => $payload, 'headers' => $headers];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function apply_openai(array $payload, array $options = []): array {
        $model = strtolower(trim((string) ($payload['model'] ?? '')));

        if (!str_starts_with($model, 'gpt-5.6') && !str_contains($model, '/gpt-5.6')) {
            return $payload;
        }

        $messages = ArrayKey::list_of_maps($payload['messages'] ?? null);
        $stable = '';

        foreach ($messages as $index => $message) {
            if ('system' !== (string) ($message['role'] ?? '') || '' !== $stable) {
                continue;
            }

            $content = $message['content'] ?? '';

            if (!is_string($content) || '' === trim($content)) {
                continue;
            }

            [$stable_part, $dynamic_part] = array_pad(explode(self::STABLE_BOUNDARY, $content, 2), 2, '');
            $stable = $stable_part;
            $messages[$index]['content'] = [[
                'type' => 'text',
                'text' => $stable_part,
                'prompt_cache_breakpoint' => ['mode' => 'explicit'],
            ]];

            if ('' !== $dynamic_part) {
                $messages[$index]['content'][] = ['type' => 'text', 'text' => $dynamic_part];
            }
        }

        if ('' === $stable) {
            return $payload;
        }

        // The projector appends mutable state after the durable event history.
        // A second breakpoint there lets long tool loops reuse the complete
        // initial turn prefix while later assistant/tool messages accumulate.
        foreach (array_reverse(array_keys($messages)) as $index) {
            $message = $messages[$index];
            $content = $message['content'] ?? null;

            if (
                'system' !== (string) ($message['role'] ?? '')
                || !is_string($content)
                || !str_starts_with($content, 'Current mutable WordPress/session context')
            ) {
                continue;
            }

            $messages[$index]['content'] = [[
                'type' => 'text',
                'text' => $content,
                'prompt_cache_breakpoint' => ['mode' => 'explicit'],
            ]];
            break;
        }

        $session = max(0, (int) ($options['session_id'] ?? 0));
        $shard = $session % 4;
        $site = function_exists('home_url') ? home_url('/') : 'awpt';
        $fingerprint = substr(hash('sha256', $site . "\0" . $model . "\0" . $stable), 0, 40);
        $payload['messages'] = $messages;
        $payload['prompt_cache_key'] = sprintf('awpt:%s:%d', $fingerprint, $shard);
        $payload['prompt_cache_options'] = ['mode' => 'explicit'];

        return $payload;
    }

    /** @param array<int, array<string, mixed>> $messages @return array<int, array<string, mixed>> */
    public static function without_internal_boundary(array $messages): array {
        foreach ($messages as $index => $message) {
            if (!is_string($message['content'] ?? null)) {
                continue;
            }

            $messages[$index]['content'] = str_replace(self::STABLE_BOUNDARY, "\n", $message['content']);
        }

        return $messages;
    }

    /**
     * Extract cached token count from provider usage objects.
     *
     * @param array<array-key, mixed> $usage
     */
    public static function cached_tokens_from_usage(array $usage): int {
        $details = $usage['prompt_tokens_details'] ?? null;

        if (is_array($details) && isset($details['cached_tokens'])) {
            return max(0, (int) $details['cached_tokens']);
        }

        $input = $usage['input_tokens_details'] ?? null;

        if (is_array($input) && isset($input['cached_tokens'])) {
            return max(0, (int) $input['cached_tokens']);
        }

        if (isset($usage['cached_tokens'])) {
            return max(0, (int) $usage['cached_tokens']);
        }

        return 0;
    }

    /** @param array<array-key, mixed> $usage */
    public static function cache_write_tokens_from_usage(array $usage): int {
        foreach (['prompt_tokens_details', 'input_tokens_details'] as $key) {
            $details = $usage[$key] ?? null;

            if (is_array($details) && isset($details['cache_write_tokens'])) {
                return max(0, (int) $details['cache_write_tokens']);
            }
        }

        return isset($usage['cache_write_tokens']) ? max(0, (int) $usage['cache_write_tokens']) : 0;
    }
}
