<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Agent\AgentFeedback;
use AWPT\Database\ActionRepository;
use AWPT\Database\SessionRepository;
use AWPT\Domain\CompositionGate;
use AWPT\Support\ActionOperations;
use AWPT\Support\PostContentSanitizer;

if (!defined('ABSPATH')) {
    exit();
}

/** Semantic approval-gated wrapper for block and classic navigation changes. */
final class ProposeNavigationChange implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/propose-navigation-change',
            'label' => __('Propose Navigation Change', 'agent-wordpress-terminal'),
            'description' => __(
                'Stages a navigation change for approval. For block navigation, provide navigation_id and complete block content. For classic navigation, provide resource_type (menu, menu_item, or menu_location), operation, resource_id, and data. Always use awpt/read-navigation first.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'session_id' => ['type' => 'integer'],
                    'mode' => ['type' => 'string', 'enum' => ['block', 'classic']],
                    'navigation_id' => ['type' => 'integer'],
                    'content' => ['type' => 'string'],
                    'resource_type' => ['type' => 'string', 'enum' => ['menu', 'menu_item', 'menu_location']],
                    'operation' => ['type' => 'string'],
                    'resource_id' => ['type' => 'string'],
                    'data' => ['type' => 'object', 'additionalProperties' => true],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['session_id', 'mode', 'title', 'description'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => static fn(array $input): bool => current_user_can('edit_theme_options'),
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => false, 'destructive' => false, 'requires_approval' => true],
        ]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        if ('block' === sanitize_key((string) ($input['mode'] ?? ''))) {
            $navigation_id = (int) ($input['navigation_id'] ?? 0);
            $post = get_post($navigation_id);

            if (!$post instanceof \WP_Post || 'wp_navigation' !== $post->post_type) {
                return new \WP_Error('awpt_navigation_not_found', __(
                    'Navigation entity not found.',
                    'agent-wordpress-terminal',
                ));
            }

            $session_id = (int) ($input['session_id'] ?? 0);

            if (!new SessionRepository()->exists($session_id)) {
                return new \WP_Error('awpt_session_not_found', __('Session not found.', 'agent-wordpress-terminal'));
            }

            $content = PostContentSanitizer::for_staged_update((string) ($input['content'] ?? ''));

            if ('' === trim($content)) {
                return new \WP_Error('awpt_navigation_empty', __(
                    'Block navigation content cannot be empty.',
                    'agent-wordpress-terminal',
                ));
            }

            $validation = new CompositionGate();
            $validated = $validation->evaluate(
                $content,
                [
                    'operation' => ActionOperations::NAVIGATION_UPDATE,
                    'work_type' => 'navigation',
                    'post_type' => 'wp_navigation',
                    'phase' => 'stage',
                ],
                true,
            );
            $error = $validation->blocking_error($validated['findings']);

            if (null !== $error) {
                return $error;
            }

            $payload = [
                'operation' => ActionOperations::NAVIGATION_UPDATE,
                'post_id' => $post->ID,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status,
                'post_title' => $post->post_title,
                'original_post_title' => $post->post_title,
                'original_post_content' => $post->post_content,
                'original_post_status' => $post->post_status,
                'post_content' => $validated['content'],
                'ruleset_hash' => $validated['ruleset_hash'],
                'validation_findings' => $validated['findings'],
                'safe_fixes' => $validated['fixes'],
                'agent_feedback' => AgentFeedback::validation($validated['findings'], $validated['fixes'], true),
                'affected' => __('Block navigation', 'agent-wordpress-terminal'),
            ];
            $action_id = new ActionRepository()->create(
                $session_id,
                sanitize_text_field((string) ($input['title'] ?? '')),
                sanitize_textarea_field((string) ($input['description'] ?? '')),
                $payload,
            );

            if (null === $action_id) {
                return new \WP_Error('awpt_action_create_failed', __(
                    'Could not create the navigation proposal.',
                    'agent-wordpress-terminal',
                ));
            }

            return new ActionRepository()->format_action($action_id) ?? [];
        }

        $resource_type = sanitize_key((string) ($input['resource_type'] ?? ''));

        if (!in_array($resource_type, ['menu', 'menu_item', 'menu_location'], true)) {
            return new \WP_Error('awpt_navigation_resource_invalid', __(
                'Classic navigation changes require menu, menu_item, or menu_location.',
                'agent-wordpress-terminal',
            ));
        }

        return new ProposeResourceChange()->execute([
            'session_id' => (int) ($input['session_id'] ?? 0),
            'resource_type' => $resource_type,
            'operation' => (string) ($input['operation'] ?? ''),
            'resource_id' => (string) ($input['resource_id'] ?? ''),
            'data' => is_array($input['data'] ?? null) ? $input['data'] : [],
            'title' => (string) ($input['title'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
        ]);
    }
}
