<?php

/**
 * Typed adapter around the optional WordPress AI Client prompt builder.
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
 * Wraps the real AI Client builder so AWPT can call a stable typed surface
 * without requiring the upstream object to implement our analysis interface.
 */
final class AiPromptBuilderAdapter implements \AWPT_AI_Prompt_Builder {
    public function __construct(
        private object $inner,
    ) {}

    public function using_max_tokens(int $tokens): self {
        return $this->forward('using_max_tokens', [$tokens]);
    }

    public function using_provider(string $provider_id): self {
        return $this->forward('using_provider', [$provider_id]);
    }

    public function using_system_instruction(string $instruction): self {
        return $this->forward('using_system_instruction', [$instruction]);
    }

    public function using_function_declarations(object ...$declarations): self {
        return $this->forward('using_function_declarations', array_values($declarations));
    }

    public function using_abilities(string ...$ability_names): self {
        return $this->forward('using_abilities', array_values($ability_names));
    }

    public function with_model_preference(array $models): self {
        return $this->forward('with_model_preference', [$models]);
    }

    public function with_instructions(string $instructions): self {
        return $this->forward('with_instructions', [$instructions]);
    }

    public function with_messages(array $messages): self {
        return $this->forward('with_messages', [$messages]);
    }

    public function with_tools(array $tools): self {
        return $this->forward('with_tools', [$tools]);
    }

    public function is_supported_for_text_generation(): bool {
        if (!method_exists($this->inner, 'is_supported_for_text_generation')) {
            return true;
        }

        return ArrayKey::rest_bool(call_user_func([$this->inner, 'is_supported_for_text_generation']));
    }

    public function generate_text_result(): object {
        if (!method_exists($this->inner, 'generate_text_result')) {
            throw new \RuntimeException('AI Client builder does not support generate_text_result().');
        }

        $result = ArrayKey::passthrough(call_user_func([$this->inner, 'generate_text_result']));

        if (!is_object($result)) {
            throw new \RuntimeException('AI Client generate_text_result() did not return an object.');
        }

        return $result;
    }

    public function generate_text(): string {
        if (!method_exists($this->inner, 'generate_text')) {
            throw new \RuntimeException('AI Client builder does not support generate_text().');
        }

        return (string) call_user_func([$this->inner, 'generate_text']);
    }

    public function run(): mixed {
        if (!method_exists($this->inner, 'run')) {
            return null;
        }

        return call_user_func([$this->inner, 'run']);
    }

    /**
     * @param list<mixed> $args
     */
    private function forward(string $method, array $args): self {
        if (!method_exists($this->inner, $method)) {
            return $this;
        }

        $result = ArrayKey::passthrough(call_user_func_array([$this->inner, $method], $args));

        if (is_object($result)) {
            $this->inner = $result;
        }

        return $this;
    }
}
