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
     * @return array<string, mixed>|\WP_Error
     */
    public function complete(array $messages, array $tools = []): array|\WP_Error;

    /**
     * Provider identifier.
     */
    public function get_name(): string;
}
