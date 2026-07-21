<?php

/**
 * Checks whether a WordPress AI Client connector supports AWPT's tool calling.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Cost-free preflight for Settings UI: can the connector generate text with tools attached?
 */
final class ConnectorToolSupportChecker {
    public function is_available(): bool {
        return function_exists('wp_ai_client_prompt');
    }

    public function supports_tools(string $connector_id): bool {
        if (!$this->is_available()) {
            return false;
        }

        try {
            $raw = call_user_func('wp_ai_client_prompt', '');

            if ($raw instanceof \AWPT_AI_Prompt_Builder) {
                $builder = $raw;
            } elseif (is_object($raw)) {
                $builder = new AiPromptBuilderAdapter($raw);
            } else {
                return true;
            }

            $configured = $builder->using_provider($connector_id);
            $ability_names = new ToolRegistry()->get_auto_executable_ability_names();
            $declarations = WordPressAIClientProvider::build_function_declarations($ability_names);

            if ([] !== $declarations) {
                $configured = $configured->using_function_declarations(...$declarations);
            }

            return $configured->is_supported_for_text_generation();
        } catch (\Throwable) {
            return true;
        }
    }
}
