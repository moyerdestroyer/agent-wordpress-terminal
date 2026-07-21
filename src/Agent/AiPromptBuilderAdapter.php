<?php

/**
 * Typed adapter around the optional WordPress AI Client prompt builder.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

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
        if (!$this->has_method('is_supported_for_text_generation')) {
            return true;
        }

        try {
            return true === $this->invoke('is_supported_for_text_generation', []);
        } catch (\Throwable) {
            return true;
        }
    }

    public function generate_text_result(): object {
        if (!$this->has_method('generate_text_result')) {
            throw new \RuntimeException('AI Client builder does not support generate_text_result().');
        }

        $object = $this->as_object($this->invoke('generate_text_result', []));

        if (null === $object) {
            throw new \RuntimeException('AI Client generate_text_result() did not return an object.');
        }

        return $object;
    }

    public function generate_text(): string {
        if (!$this->has_method('generate_text')) {
            throw new \RuntimeException('AI Client builder does not support generate_text().');
        }

        return (string) $this->invoke('generate_text', []);
    }

    public function run(): mixed {
        if (!$this->has_method('run')) {
            return null;
        }

        return $this->invoke('run', []);
    }

    /**
     * @param list<mixed> $args
     */
    private function forward(string $method, array $args): self {
        if (!$this->has_method($method)) {
            return $this;
        }

        try {
            $object = $this->as_object($this->invoke($method, $args));
        } catch (\Throwable) {
            return $this;
        }

        if (null !== $object) {
            $this->inner = $object;
        }

        return $this;
    }

    private function has_method(string $method): bool {
        return method_exists($this->inner, $method);
    }

    /**
     * @param list<mixed> $args
     */
    private function invoke(string $method, array $args): mixed {
        return new \ReflectionMethod($this->inner, $method)->invokeArgs($this->inner, $args);
    }

    private function as_object(mixed $value): ?object {
        return is_object($value) ? $value : null;
    }
}
