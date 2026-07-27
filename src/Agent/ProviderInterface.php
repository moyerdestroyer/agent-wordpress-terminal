<?php

/**
 * Agent provider interface.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Contract for LLM providers.
 */
interface ProviderInterface {
    /**
     * Send a chat completion request.
     *
     * @param array<int, array<string, mixed>> $messages Conversation messages.
     * @param array<int, array<string, mixed>> $tools Available tools.
     * @param array<string, mixed>             $options Provider request options.
     * @return array<string, mixed>|\WP_Error
     */
    public function complete(array $messages, array $tools = [], array $options = []): array|\WP_Error;

    /**
     * Whether this provider's active model accepts image_url message parts.
     */
    public function accepts_image_input(): bool;

    /**
     * Provider identifier.
     */
    public function get_name(): string;
}
