<?php

declare(strict_types=1);

namespace AWPT\Abilities;

if (!defined('ABSPATH')) {
    exit();
}

/** Stages a bounded partial update to the active theme's saved Global Styles. */
final class ProposeGlobalStylesPatch implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-global-styles-patch',
            'label' => __('Propose Global Styles Patch', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads the active saved Global Styles revision, recursively merges a partial settings/styles patch, and stages the normalized result for approval. Use awpt/read-global-styles first and send only intended keys.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'patch' => ['type' => 'object', 'additionalProperties' => true],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'affected' => ['type' => 'string'],
                ],
                'required' => ['session_id', 'patch', 'title', 'description'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => static fn(array $input): bool => current_user_can('edit_theme_options'),
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => false, 'destructive' => false, 'requires_approval' => true],
        ]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        $patch = is_array($input['patch'] ?? null) ? $input['patch'] : [];
        $unsupported = array_diff(array_keys($patch), ['settings', 'styles']);

        if ([] !== $unsupported || [] === $patch) {
            return new \WP_Error(
                'awpt_global_styles_patch_invalid',
                __(
                    'A Global Styles patch must contain only non-empty settings and/or styles objects.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 400, 'unsupported' => array_values($unsupported)],
            );
        }

        $current = new ReadGlobalStyles()->execute([]);

        if (is_wp_error($current)) {
            return $current;
        }

        $base = json_decode((string) ($current['content'] ?? ''), true);
        $base = is_array($base) ? $base : [];
        $merged = $this->merge($base, $patch);
        $content = wp_json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($content)) {
            return new \WP_Error('awpt_global_styles_patch_encode', __(
                'Could not encode Global Styles.',
                'agent-wordpress-terminal',
            ));
        }

        return new ProposeGlobalStylesUpdate()->execute([
            'session_id' => (int) ($input['session_id'] ?? 0),
            'global_styles_id' => (int) ($current['id'] ?? 0),
            'content' => $content,
            'title' => (string) ($input['title'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
            'affected' => (string) ($input['affected'] ?? __('Global Styles', 'agent-wordpress-terminal')),
        ]);
    }

    /** @param array<array-key, mixed> $base @param array<array-key, mixed> $patch @return array<array-key, mixed> */
    private function merge(array $base, array $patch): array {
        foreach ($patch as $key => $value) {
            if (
                is_array($value)
                && !array_is_list($value)
                && is_array($base[$key] ?? null)
                && !array_is_list($base[$key])
            ) {
                $base[$key] = $this->merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
