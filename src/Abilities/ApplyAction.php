<?php

/**
 * awpt/apply-action ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Abilities\ActionAppliers\ContentUpdateActionApplier;
use AWPT\Abilities\ActionAppliers\CustomCssActionApplier;
use AWPT\Abilities\ActionAppliers\GlobalStylesCreateActionApplier;
use AWPT\Abilities\ActionAppliers\NewPostActionApplier;
use AWPT\Abilities\ActionAppliers\PluginDeactivateActionApplier;
use AWPT\Abilities\ActionAppliers\SiteSettingsActionApplier;
use AWPT\Abilities\ActionAppliers\ThemeSwitchActionApplier;
use AWPT\Agent\AgentFeedback;
use AWPT\Database\ActionRepository;
use AWPT\Domain\CompositionGate;
use AWPT\Domain\CompositionProposalGuard;
use AWPT\Domain\DomainPackRegistry;
use AWPT\Domain\DomainProposalManager;
use AWPT\Support\ActionOperations;
use AWPT\Support\ArrayKey;
use AWPT\Support\ResourceChangeManager;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Applies an approved staged action.
 */
final class ApplyAction implements AbilityInterface {
    private ActionRepository $actions;
    private ContentUpdateActionApplier $content_updates;
    private NewPostActionApplier $new_posts;
    private SiteSettingsActionApplier $site_settings;
    private ThemeSwitchActionApplier $theme_switches;

    public function __construct(
        ?ActionRepository $actions = null,
        ?ContentUpdateActionApplier $content_updates = null,
        ?NewPostActionApplier $new_posts = null,
        ?SiteSettingsActionApplier $site_settings = null,
        ?ThemeSwitchActionApplier $theme_switches = null,
    ) {
        $this->actions = $actions ?? new ActionRepository();
        $this->content_updates = $content_updates ?? new ContentUpdateActionApplier();
        $this->new_posts = $new_posts ?? new NewPostActionApplier();
        $this->site_settings = $site_settings ?? new SiteSettingsActionApplier();
        $this->theme_switches = $theme_switches ?? new ThemeSwitchActionApplier();
    }

    /**
     * Register the ability.
     */
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/apply-action',
            'label' => __('Apply Action', 'agent-wordpress-terminal'),
            'description' => __('Applies an explicitly approved AWPT staged action.', 'agent-wordpress-terminal'),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action_id' => [
                        'type' => 'integer',
                        'description' => __('AWPT action ID.', 'agent-wordpress-terminal'),
                    ],
                ],
                'required' => ['action_id'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_apply'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => false,
                'destructive' => true,
                'requires_approval' => true,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function can_apply(array $input): bool {
        $action = $this->actions->get_accessible_row((int) ($input['action_id'] ?? 0));

        if (null === $action || 'approved' !== $action['status']) {
            return false;
        }

        $payload = $this->actions->decode_payload($action);
        $operation = (string) ($payload['operation'] ?? '');

        if (ActionOperations::CONTENT_UPDATE === $operation && $this->missing_required_content_h1($payload)) {
            return false;
        }

        if (ActionOperations::CONTENT_UPDATE === $operation && !$this->content_update_has_mutation($payload)) {
            return false;
        }

        if (null !== DomainPackRegistry::instance()->proposal_operation($operation)) {
            $registered = DomainPackRegistry::instance()->proposal_operation($operation);
            $permission = $registered['permission_callback'] ?? null;

            return (
                is_callable($permission)
                && true === $permission(ArrayKey::as_map($payload['domain_payload'] ?? null), 'apply')
            );
        }

        return match ($operation) {
            'content_update', 'block_attrs_update', 'block_insert', 'block_remove', 'pattern_insert' => (int) (
                $payload['post_id'] ?? 0
            ) > 0
                && current_user_can('edit_post', (int) ($payload['post_id'] ?? 0)),
            'template_update', 'global_styles_update', 'navigation_update' => (int) ($payload['post_id'] ?? 0) > 0
                && current_user_can('edit_theme_options')
                && current_user_can('edit_post', (int) ($payload['post_id'] ?? 0)),
            'global_styles_create' => current_user_can('edit_theme_options'),
            'new_post' => current_user_can('edit_posts'),
            'site_settings_update' => current_user_can('manage_options'),
            'theme_switch' => current_user_can('switch_themes'),
            'plugin_deactivate' => current_user_can('activate_plugins'),
            'custom_css_update' => current_user_can('edit_css') || current_user_can('edit_theme_options'),
            'resource_change' => new ResourceChangeManager()->can_apply($payload),
            default => false,
        };
    }

    /** @param array<string, mixed> $payload */
    private function content_update_has_mutation(array $payload): bool {
        foreach ([
            'post_title' => 'original_post_title',
            'post_content' => 'original_post_content',
            'post_status' => 'original_post_status',
        ] as $next => $original) {
            if (
                array_key_exists($next, $payload)
                && (string) $payload[$next] !== (string) ($payload[$original] ?? '')
            ) {
                return true;
            }
        }

        $meta = is_array($payload['post_meta'] ?? null) ? $payload['post_meta'] : [];
        $original_meta = is_array($payload['original_post_meta'] ?? null) ? $payload['original_post_meta'] : [];

        foreach ($meta as $key => $value) {
            if (!array_key_exists($key, $original_meta) || $value !== $original_meta[$key]) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function missing_required_content_h1(array $payload): bool {
        return true === ($payload['presentation_requires_h1'] ?? false) && !array_key_exists('post_content', $payload);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $action_id = (int) ($input['action_id'] ?? 0);
        $action = $this->actions->get_accessible_row($action_id);

        if (null === $action) {
            return new \WP_Error(
                code: 'awpt_action_not_found',
                message: __('Action not found.', 'agent-wordpress-terminal'),
                data: ['status' => 404],
            );
        }

        if ('approved' !== $action['status']) {
            return new \WP_Error(
                code: 'awpt_action_not_approved',
                message: __('Action must be approved before it can be applied.', 'agent-wordpress-terminal'),
                data: ['status' => 409],
            );
        }

        $payload = $this->actions->decode_payload($action);
        $operation = (string) ($payload['operation'] ?? '');

        if (ActionOperations::CONTENT_UPDATE === $operation && $this->missing_required_content_h1($payload)) {
            return new \WP_Error(
                'awpt_required_page_h1_missing',
                __(
                    'This staged update does not provide the content H1 required by rendered evidence.',
                    'agent-wordpress-terminal',
                ),
                ['status' => 409],
            );
        }

        if (
            ActionOperations::CONTENT_UPDATE === $operation
            && true === ($payload['presentation_requires_h1'] ?? false)
            && array_key_exists('post_content', $payload)
        ) {
            $outline_error = new CompositionProposalGuard()->heading_outline_error((string) $payload['post_content']);

            if ($outline_error instanceof \WP_Error) {
                return $outline_error;
            }
        }

        if (ActionOperations::CONTENT_UPDATE === $operation && !$this->content_update_has_mutation($payload)) {
            return new \WP_Error(
                'awpt_content_update_no_changes',
                __('This staged content update contains no effective post change.', 'agent-wordpress-terminal'),
                ['status' => 409],
            );
        }

        if (ActionOperations::is_review_safe_content($operation)) {
            $snapshot = $this->review_undo_snapshot($payload);
            if (is_wp_error($snapshot)) {
                return $snapshot;
            }
            $payload['review_undo_snapshot'] = $snapshot;
            $this->actions->update_payload($action_id, $payload);
        }

        if (array_key_exists('post_content', $payload)) {
            $validation = new CompositionGate();
            $validation_result = $validation->evaluate((string) $payload['post_content'], [
                'operation' => $operation,
                'work_type' => $this->work_type($operation),
                'post_type' => (string) ($payload['post_type'] ?? ''),
                'pattern_name' => (string) ($payload['pattern_name'] ?? ''),
                'phase' => 'apply',
            ]);
            $findings = $validation_result['findings'];
            $baseline_findings = [];
            $original_content = (string) ($payload['original_post_content'] ?? '');
            if ('' !== $original_content) {
                $baseline_result = $validation->evaluate($original_content, [
                    'operation' => $operation,
                    'work_type' => $this->work_type($operation),
                    'post_type' => (string) ($payload['post_type'] ?? ''),
                    'pattern_name' => (string) ($payload['pattern_name'] ?? ''),
                    'phase' => 'baseline',
                ]);
                $baseline_findings = $baseline_result['findings'];
            }
            $validation_error = $validation->blocking_error(CompositionProposalGuard::new_findings(
                $findings,
                $baseline_findings,
            ));

            if (null !== $validation_error) {
                return $validation_error;
            }

            if ([] !== $findings || (string) ($payload['ruleset_hash'] ?? '') !== $validation_result['ruleset_hash']) {
                $payload['validation_findings'] = $findings;
                $payload['ruleset_hash'] = $validation_result['ruleset_hash'];
                $payload['agent_feedback'] = AgentFeedback::validation($findings, [], true);
                $this->actions->update_payload($action_id, $payload);
            }
        }

        $result = null !== DomainPackRegistry::instance()->proposal_operation($operation)
            ? new DomainProposalManager()->apply($payload)
            : match ($operation) {
                'content_update',
                'block_attrs_update',
                'block_insert',
                'block_remove',
                'pattern_insert',
                'template_update',
                'global_styles_update',
                'navigation_update',
                    => $this->content_updates->apply($payload),
                'global_styles_create' => new GlobalStylesCreateActionApplier()->apply($payload),
                'new_post' => $this->new_posts->apply($payload),
                'site_settings_update' => $this->site_settings->apply($payload),
                'theme_switch' => $this->theme_switches->apply($payload),
                'plugin_deactivate' => new PluginDeactivateActionApplier()->apply($payload),
                'custom_css_update' => new CustomCssActionApplier()->apply($payload),
                'resource_change' => new ResourceChangeManager()->apply($payload),
                default => new \WP_Error(
                    code: 'awpt_unsupported_action',
                    message: __('Unsupported action operation.', 'agent-wordpress-terminal'),
                    data: ['status' => 400],
                ),
            };

        if (is_wp_error($result)) {
            return $result;
        }

        if (ActionOperations::is_review_safe_content($operation)) {
            $post = get_post((int) ($payload['post_id'] ?? 0));
            if ($post instanceof \WP_Post) {
                $payload['review_applied_fingerprint'] = $this->review_fingerprint($post, $payload);
                $this->actions->update_payload($action_id, $payload);
            }
        }

        if (null !== DomainPackRegistry::instance()->proposal_operation($operation)) {
            $payload = array_merge($payload, ArrayKey::string_map($result));
            $this->actions->update_payload($action_id, $payload);
        }

        $this->actions->mark_applied($action_id);

        return ArrayKey::string_map(array_merge([
            'action_id' => $action_id,
            'status' => 'applied',
        ], $result));
    }

    private function work_type(string $operation): string {
        return match ($operation) {
            'new_post' => 'compose',
            'template_update' => 'template',
            'global_styles_update', 'global_styles_create' => 'global_styles',
            'navigation_update' => 'navigation',
            'resource_change' => 'navigation',
            default => 'edit',
        };
    }

    /** @param array<string, mixed> $payload */
    private function review_undo_snapshot(array $payload): array|\WP_Error {
        $post_id = (int) ($payload['post_id'] ?? 0);
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post instanceof \WP_Post) {
            return new \WP_Error('awpt_post_not_found', __('The page no longer exists.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $meta = [];
        $post_meta = $payload['post_meta'] ?? [];
        foreach (array_keys(is_array($post_meta) ? $post_meta : []) as $key) {
            $key = sanitize_key((string) $key);
            if ($key !== '') {
                $meta[$key] = [
                    'exists' => metadata_exists('post', $post_id, $key),
                    'value' => get_post_meta($post_id, $key, true),
                ];
            }
        }

        return [
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_status' => $post->post_status,
            'meta' => $meta,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function review_fingerprint(\WP_Post $post, array $payload): string {
        $meta = [];
        $post_meta = $payload['post_meta'] ?? [];
        foreach (array_keys(is_array($post_meta) ? $post_meta : []) as $key) {
            $key = sanitize_key((string) $key);
            if ($key !== '') {
                $meta[$key] = get_post_meta($post->ID, $key, true);
            }
        }

        $encoded = wp_json_encode([
            'title' => $post->post_title,
            'content' => $post->post_content,
            'status' => $post->post_status,
            'meta' => $meta,
        ]);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }
}
