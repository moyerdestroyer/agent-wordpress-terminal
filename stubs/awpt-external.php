<?php

/**
 * Optional plugin and package stubs for Mago static analysis.
 *
 * Symbols that are not part of WordPress core (connectors, AI Client, Vite).
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace {
    /**
     * WordPress AI Client plugin (optional connector).
     */
    final class WP_AI_Client_Ability_Function_Resolver
    {
        public static function ability_name_to_function_name(string $ability_name): string
        {
            return '';
        }

        public static function function_name_to_ability_name(string $function_name): string
        {
            return '';
        }
    }

    /**
     * Returns all registered AI connectors keyed by provider id.
     *
     * @return array<string, array<string, mixed>>
     */
    function wp_get_connectors(): array
    {
        return [];
    }

    /**
     * Returns the data for a single registered connector, or null when unknown.
     *
     * @return array<string, mixed>|null
     */
    function wp_get_connector(string $provider_id): ?array
    {
        return null;
    }

    function wp_is_connector_registered(string $provider_id): bool
    {
        return false;
    }

    /**
     * @return object Builder for AI client prompts.
     */
    function wp_ai_client_prompt(): object
    {
        return new class {
            public function with_model_preference(array $models): self
            {
                return $this;
            }

            public function with_instructions(string $instructions): self
            {
                return $this;
            }

            public function with_messages(array $messages): self
            {
                return $this;
            }

            public function with_tools(array $tools): self
            {
                return $this;
            }

            public function run(): mixed
            {
                return null;
            }
        };
    }
}

namespace WordPress\AiClient\Tools\DTO {
    final class FunctionDeclaration
    {
        public function __construct(
            string $name,
            string $description,
            array $parameters = [],
        ) {}
    }
}

namespace Kucrut\Vite {
    /**
     * @param array<string, mixed> $options
     */
    function enqueue_asset(string $manifest_dir, string $entry, array $options = []): void {}
}