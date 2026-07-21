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
     * Fluent prompt builder used by the optional WP AI Client path.
     *
     * Method names match WordPress AI Client's public builder API as used by AWPT.
     */
    interface AWPT_AI_Prompt_Builder
    {
        public function using_max_tokens(int $tokens): self;

        public function using_provider(string $provider_id): self;

        public function using_system_instruction(string $instruction): self;

        public function using_function_declarations(object ...$declarations): self;

        public function using_abilities(string ...$ability_names): self;

        public function with_model_preference(array $models): self;

        public function with_instructions(string $instructions): self;

        public function with_messages(array $messages): self;

        public function with_tools(array $tools): self;

        public function is_supported_for_text_generation(): bool;

        public function generate_text_result(): object;

        public function generate_text(): string;

        public function run(): mixed;
    }

    /**
     * Normalized generation result shape accessed reflectively by AWPT.
     */
    interface AWPT_AI_Generation_Result
    {
        /** @return iterable<object> */
        public function getCandidates(): iterable;

        public function toText(): string;

        public function get_text(): string;

        public function getModelMetadata(): object;
    }

    interface AWPT_AI_Candidate
    {
        public function getMessage(): object;
    }

    interface AWPT_AI_Message
    {
        /** @return iterable<object> */
        public function getParts(): iterable;
    }

    interface AWPT_AI_Part
    {
        public function getFunctionCall(): ?object;
    }

    interface AWPT_AI_Function_Call
    {
        public function getName(): string;

        public function getId(): string;

        /** @return array<array-key, mixed>|null */
        public function getArgs(): ?array;
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

    function wp_ai_client_prompt(string $prompt = ''): AWPT_AI_Prompt_Builder
    {
        return new class implements AWPT_AI_Prompt_Builder {
            public function using_max_tokens(int $tokens): self
            {
                return $this;
            }

            public function using_provider(string $provider_id): self
            {
                return $this;
            }

            public function using_system_instruction(string $instruction): self
            {
                return $this;
            }

            public function using_function_declarations(object ...$declarations): self
            {
                return $this;
            }

            public function using_abilities(string ...$ability_names): self
            {
                return $this;
            }

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

            public function is_supported_for_text_generation(): bool
            {
                return true;
            }

            public function generate_text_result(): object
            {
                return new stdClass();
            }

            public function generate_text(): string
            {
                return '';
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
