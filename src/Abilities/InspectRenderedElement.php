<?php

/**
 * awpt/inspect-rendered-element ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Database\ActionRepository;
use AWPT\Support\Diagnostics\RenderedVisualInspector;

if (!defined('ABSPATH')) {
    exit();
}

/** Renders a same-site page and reports screenshot, geometry, and computed CSS. */
final class InspectRenderedElement implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/inspect-rendered-element',
            'label' => __('Inspect Rendered Element', 'agent-wordpress-terminal'),
            'description' => __(
                'Uses a headless browser when available to inspect a live page or staged action preview, returning a screenshot plus element geometry and computed styles. Selector is optional.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string'],
                    'post_id' => ['type' => 'integer'],
                    'action_id' => [
                        'type' => 'integer',
                        'description' => __(
                            'Optional staged action whose preview should be inspected.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'selector' => [
                        'type' => 'string',
                        'description' => __(
                            'Optional CSS selector. Omit to inventory prominent content and visual elements.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                    'include_screenshot' => ['type' => 'boolean'],
                ],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
            ],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_read(array $input): bool {
        unset($input);

        return current_user_can('edit_posts');
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $url = esc_url_raw((string) ($input['url'] ?? ''));
        $action_id = (int) ($input['action_id'] ?? 0);
        $post_id = (int) ($input['post_id'] ?? 0);

        if ($action_id > 0) {
            $action = new ActionRepository()->get_accessible_row($action_id);

            if (null === $action) {
                return new \WP_Error('awpt_action_not_found', __('Action not found.', 'agent-wordpress-terminal'), [
                    'status' => 404,
                ]);
            }

            $payload = new ActionRepository()->decode_payload($action);
            $url = esc_url_raw((string) ($payload['preview_url'] ?? ''));
        }

        if ('' === $url && $post_id > 0 && current_user_can('read_post', $post_id)) {
            $permalink = get_permalink($post_id);
            $url = is_string($permalink) ? esc_url_raw($permalink) : '';
        }

        if ('' === $url) {
            return new \WP_Error(
                'awpt_rendered_inspection_target',
                __('Provide a same-site URL, readable post_id, or staged action_id.', 'agent-wordpress-terminal'),
                ['status' => 400],
            );
        }

        return new RenderedVisualInspector()->inspect(
            $url,
            sanitize_text_field((string) ($input['selector'] ?? '')),
            !array_key_exists('include_screenshot', $input)
            || filter_var($input['include_screenshot'], FILTER_VALIDATE_BOOLEAN),
        );
    }
}
