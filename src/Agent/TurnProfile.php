<?php

/**
 * Classifies a chat turn so prompts, tools, and retrieval stay proportional.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Support\ProposalAbilities;
use AWPT\Support\SiteDesignContext;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Lightweight turn intent used by the message builder, tool registry, and runtime.
 *
 * Prefer false-positive modules over missing ones: under-classifying is worse than
 * sending a slightly larger prompt on an ambiguous site question.
 */
final class TurnProfile {
    public const TOOL_CHAT = 'chat';

    public const TOOL_INVESTIGATE = 'investigate';

    public const TOOL_COMPOSE = 'compose';

    public const TOOL_EDIT = 'edit';

    public const TOOL_DIAGNOSE = 'diagnose';

    public readonly string $message;

    public readonly bool $content_turn;

    public readonly bool $content_edit_turn;

    public readonly string $design_level;

    public readonly string $tool_profile;

    public readonly bool $auto_retrieve_knowledge;

    public readonly int $history_limit;

    /**
     * Secondary classification flags used by prompt modules.
     *
     * @var array{
     *     site_data: bool,
     *     frontend: bool,
     *     diagnosis: bool,
     *     settings_or_theme: bool,
     *     template_or_styles: bool,
     *     has_open_proposals: bool,
     *     has_open_incidents: bool,
     *     has_focus: bool
     * }
     */
    private array $flags;

    /**
     * @param array{
     *     message: string,
     *     content_turn: bool,
     *     content_edit_turn: bool,
     *     design_level: string,
     *     tool_profile: string,
     *     auto_retrieve_knowledge: bool,
     *     history_limit: int,
     *     flags: array{
     *         site_data: bool,
     *         frontend: bool,
     *         diagnosis: bool,
     *         settings_or_theme: bool,
     *         template_or_styles: bool,
     *         has_open_proposals: bool,
     *         has_open_incidents: bool,
     *         has_focus: bool
     *     }
     * } $state
     */
    public function __construct(array $state) {
        $this->message = $state['message'];
        $this->content_turn = $state['content_turn'];
        $this->content_edit_turn = $state['content_edit_turn'];
        $this->design_level = $state['design_level'];
        $this->tool_profile = $state['tool_profile'];
        $this->auto_retrieve_knowledge = $state['auto_retrieve_knowledge'];
        $this->history_limit = $state['history_limit'];
        $this->flags = $state['flags'];
    }

    /**
     * @param array{
     *     prior_user_messages?: list<string>,
     *     has_open_new_post_proposal?: bool
     * } $budget_context
     * @param array{
     *     has_open_proposals?: bool,
     *     has_open_incidents?: bool,
     *     has_focus?: bool
     * } $session
     */
    public static function from_message(string $message, array $budget_context = [], array $session = []): self {
        $has_open_proposals = $session['has_open_proposals'] ?? false;
        $has_open_incidents = $session['has_open_incidents'] ?? false;
        $has_focus = $session['has_focus'] ?? false;

        $budget = new GenerationBudget();
        $content_turn = $budget->is_content_request($message, $budget_context);
        $content_edit_turn = $budget->is_content_edit_request($message, $budget_context);
        $design_level = new SiteDesignContext()->request_level($message);
        $signals = [
            'site_data' => self::looks_like_site_data($message),
            'frontend' => self::looks_like_frontend($message),
            'diagnosis' => self::looks_like_diagnosis($message) || $has_open_incidents,
            'settings_or_theme' => self::looks_like_settings_or_theme($message),
            'template_or_styles' =>
                self::looks_like_template_or_styles($message)
                    || in_array(
                        $design_level,
                        [
                            SiteDesignContext::LEVEL_TOKENS,
                            SiteDesignContext::LEVEL_SECTION,
                            SiteDesignContext::LEVEL_COMPOSITION,
                        ],
                        true,
                    ),
            'content_turn' => $content_turn,
            'content_edit_turn' => $content_edit_turn,
            'has_open_proposals' => $has_open_proposals,
            'has_focus' => $has_focus,
            'design_level' => $design_level,
        ];

        $tool_profile = self::resolve_tool_profile($message, $signals);
        $history_limit = match ($tool_profile) {
            self::TOOL_CHAT => 10,
            self::TOOL_COMPOSE, self::TOOL_EDIT => 30,
            self::TOOL_DIAGNOSE => 20,
            default => 16,
        };

        return new self([
            'message' => $message,
            'content_turn' => $content_turn,
            'content_edit_turn' => $content_edit_turn,
            'design_level' => $design_level,
            'tool_profile' => $tool_profile,
            'auto_retrieve_knowledge' => self::should_auto_retrieve_knowledge($signals),
            'history_limit' => $history_limit,
            'flags' => [
                'site_data' => $signals['site_data'],
                'frontend' => $signals['frontend'],
                'diagnosis' => $signals['diagnosis'],
                'settings_or_theme' => $signals['settings_or_theme'],
                'template_or_styles' => $signals['template_or_styles'],
                'has_open_proposals' => $has_open_proposals,
                'has_open_incidents' => $has_open_incidents,
                'has_focus' => $has_focus,
            ],
        ]);
    }

    public function needs_compose_module(): bool {
        return (
            $this->content_turn
            || $this->flags['has_open_proposals']
            && (bool) preg_match('/\b(revise|update|change|improve|try\s+again|retry|finish)\b/i', $this->message)
        );
    }

    public function needs_edit_module(): bool {
        return (
            $this->content_edit_turn
            || $this->flags['has_focus']
            && (bool) preg_match('/\b(edit|update|change|fix|revise|rewrite|format|attrs?|block)\b/i', $this->message)
        );
    }

    public function needs_proposal_manifest_module(): bool {
        return (
            $this->needs_compose_module()
            || $this->needs_edit_module()
            || $this->content_turn
            || $this->content_edit_turn
        );
    }

    public function needs_site_data_module(): bool {
        return (
            $this->flags['site_data']
            || $this->needs_compose_module()
            || $this->needs_edit_module()
            || self::TOOL_CHAT !== $this->tool_profile
        );
    }

    public function needs_template_module(): bool {
        return $this->flags['template_or_styles'];
    }

    public function needs_frontend_module(): bool {
        return $this->flags['frontend'];
    }

    public function needs_diagnosis_module(): bool {
        return $this->flags['diagnosis'];
    }

    public function needs_settings_module(): bool {
        return $this->flags['settings_or_theme'];
    }

    public function needs_guidelines(): bool {
        return (
            $this->auto_retrieve_knowledge
            || $this->content_turn
            || $this->content_edit_turn
            || SiteDesignContext::LEVEL_NONE !== $this->design_level
        );
    }

    public function include_design_tokens(): bool {
        return SiteDesignContext::LEVEL_NONE !== $this->design_level || $this->content_turn || $this->content_edit_turn;
    }

    /**
     * Whether this turn uses the hard explore→compose phase machine.
     */
    public function uses_explore_compose_phases(): bool {
        return (
            self::TOOL_COMPOSE === $this->tool_profile
            || self::TOOL_EDIT === $this->tool_profile
            || $this->content_turn
            || $this->content_edit_turn
        );
    }

    /**
     * Ability names to offer for this profile. Empty list means "all enabled tools".
     *
     * @return list<string>
     */
    public function tool_allowlist(): array {
        return match ($this->tool_profile) {
            self::TOOL_CHAT => [
                'core/get-site-info',
                'core/get-user-info',
                'core/get-environment-info',
                'core/read-settings',
                'awpt/list-content',
                'awpt/search-content',
                'awpt/search-knowledge',
                'awpt/list-knowledge-sources',
                'awpt/read-knowledge',
                'awpt/read-themes',
                'awpt/preview-post',
                'awpt/read-proposal',
            ],
            self::TOOL_INVESTIGATE => [
                'core/get-site-info',
                'core/get-user-info',
                'core/get-environment-info',
                'core/read-settings',
                'awpt/list-content',
                'awpt/search-content',
                'awpt/read-content',
                'awpt/read-attachment-document',
                'awpt/search-knowledge',
                'awpt/list-knowledge-sources',
                'awpt/read-knowledge',
                'awpt/read-themes',
                'awpt/read-theme-json',
                'awpt/read-theme-file',
                'awpt/list-patterns',
                'awpt/read-pattern',
                'awpt/list-templates',
                'awpt/read-template',
                'awpt/read-global-styles',
                'awpt/read-block-tree',
                'awpt/list-blocks',
                'awpt/get-block',
                'awpt/render-block',
                'awpt/inspect-frontend',
                'awpt/analyze-page',
                'awpt/preview-post',
                'awpt/propose-custom-css-update',
                'awpt/propose-site-settings-update',
                'awpt/propose-theme-switch',
                'awpt/propose-template-update',
                'awpt/propose-global-styles-update',
                'awpt/propose-resource-change',
                'awpt/read-proposal',
            ],
            self::TOOL_COMPOSE => [
                ...$this->explore_allowlist(),
                'awpt/propose-new-post',
            ],
            self::TOOL_EDIT => [
                ...$this->explore_allowlist_for_edit(),
                'awpt/propose-content-update',
                'awpt/propose-block-attrs-update',
                'awpt/propose-block-insert',
                'awpt/propose-block-remove',
                'awpt/propose-pattern-insert',
                'awpt/propose-new-post',
            ],
            self::TOOL_DIAGNOSE => [
                'core/get-site-info',
                'core/get-environment-info',
                'core/read-settings',
                'awpt/read-error-log',
                'awpt/read-plugins',
                'awpt/read-site-health',
                'awpt/probe-url',
                'awpt/diagnose-error',
                'awpt/inspect-frontend',
                'awpt/search-knowledge',
                'awpt/list-knowledge-sources',
                'awpt/read-theme-file',
                'awpt/propose-custom-css-update',
                'awpt/propose-plugin-deactivate',
                'awpt/propose-site-settings-update',
            ],
            default => [],
        };
    }

    /**
     * Read-only tools for the explore phase (no propose-*).
     *
     * @return list<string>
     */
    public function explore_allowlist(): array {
        if (self::TOOL_EDIT === $this->tool_profile || $this->content_edit_turn) {
            return $this->explore_allowlist_for_edit();
        }

        return [
            'core/get-site-info',
            'awpt/list-content',
            'awpt/search-content',
            'awpt/read-content',
            'awpt/read-attachment-document',
            'awpt/search-knowledge',
            'awpt/list-knowledge-sources',
            'awpt/read-knowledge',
            'awpt/read-themes',
            'awpt/read-theme-json',
            'awpt/read-theme-file',
            'awpt/list-patterns',
            'awpt/read-pattern',
            'awpt/list-templates',
            'awpt/read-template',
            'awpt/read-global-styles',
            'awpt/preview-post',
            'awpt/read-proposal',
        ];
    }

    /**
     * @return list<string>
     */
    public function explore_allowlist_for_edit(): array {
        return [
            'core/get-site-info',
            'awpt/list-content',
            'awpt/search-content',
            'awpt/read-content',
            'awpt/read-attachment-document',
            'awpt/search-knowledge',
            'awpt/list-knowledge-sources',
            'awpt/read-knowledge',
            'awpt/read-block-tree',
            'awpt/list-blocks',
            'awpt/get-block',
            'awpt/render-block',
            'awpt/list-patterns',
            'awpt/read-pattern',
            'awpt/read-theme-file',
            'awpt/read-theme-json',
            'awpt/preview-post',
            'awpt/read-proposal',
        ];
    }

    /**
     * Proposal tools for the compose phase.
     *
     * @return list<string>
     */
    public function compose_allowlist(): array {
        // Finalization is approval-gated, but it is intentionally not operation
        // forced. Discovery evidence and the user's request determine the correct
        // proposal type.
        return ProposalAbilities::names();
    }

    /**
     * Compatibility hint for providers that cannot express "any required tool".
     * This must never narrow the proposal tools offered to the model.
     */
    public function compose_primary_ability(): string {
        if (self::TOOL_EDIT === $this->tool_profile || $this->content_edit_turn && !$this->content_turn) {
            return 'awpt/propose-content-update';
        }

        return 'awpt/propose-new-post';
    }

    /**
     * @return array<string, bool|int|string>
     */
    public function diagnostics(): array {
        return [
            'tool_profile' => $this->tool_profile,
            'design_level' => $this->design_level,
            'content_turn' => $this->content_turn,
            'content_edit_turn' => $this->content_edit_turn,
            'auto_retrieve_knowledge' => $this->auto_retrieve_knowledge,
            'history_limit' => $this->history_limit,
            'tool_allowlist_count' => count($this->tool_allowlist()),
        ];
    }

    /**
     * @param array{
     *     content_turn: bool,
     *     content_edit_turn: bool,
     *     diagnosis: bool,
     *     has_open_proposals: bool,
     *     has_focus: bool,
     *     design_level: string,
     *     frontend: bool,
     *     template_or_styles: bool,
     *     settings_or_theme: bool,
     *     site_data: bool
     * } $signals
     */
    private static function resolve_tool_profile(string $message, array $signals): string {
        if ($signals['diagnosis'] && !$signals['content_turn'] && !$signals['content_edit_turn']) {
            return self::TOOL_DIAGNOSE;
        }

        if ($signals['content_turn']) {
            return self::TOOL_COMPOSE;
        }

        if (
            $signals['has_open_proposals']
            && (bool) preg_match(
                '/\b(revise|update|change|improve|add|section|retry|try\s+again|finish|keep\s+going)\b/i',
                $message,
            )
        ) {
            return self::TOOL_COMPOSE;
        }

        if ($signals['content_edit_turn']) {
            return self::TOOL_EDIT;
        }

        if (
            $signals['has_focus']
            && (bool) preg_match(
                '/\b(edit|update|change|fix|revise|rewrite|format|improve|replace|attrs?|block|title|content)\b/i',
                $message,
            )
        ) {
            return self::TOOL_EDIT;
        }

        if (
            $signals['frontend']
            || $signals['template_or_styles']
            || $signals['settings_or_theme']
            || SiteDesignContext::LEVEL_NONE !== $signals['design_level']
            || $signals['site_data']
        ) {
            return self::TOOL_INVESTIGATE;
        }

        return self::TOOL_CHAT;
    }

    /**
     * @param array{
     *     content_turn: bool,
     *     content_edit_turn: bool,
     *     has_open_proposals: bool,
     *     has_focus: bool,
     *     design_level: string,
     *     site_data: bool,
     *     frontend: bool,
     *     diagnosis: bool,
     *     settings_or_theme: bool,
     *     template_or_styles: bool
     * } $signals
     */
    private static function should_auto_retrieve_knowledge(array $signals): bool {
        if ($signals['content_turn'] || $signals['content_edit_turn'] || $signals['has_open_proposals']) {
            return true;
        }

        if (SiteDesignContext::LEVEL_NONE !== $signals['design_level']) {
            return true;
        }

        return (
            $signals['site_data']
            || $signals['frontend']
            || $signals['diagnosis']
            || $signals['settings_or_theme']
            || $signals['template_or_styles']
        );
    }

    private static function looks_like_site_data(string $message): bool {
        return (bool) preg_match(
            '/\b('
            . 'theme|plugin|post|page|content|draft|publish|media|attachment|image|pattern|'
            . 'template|block|gutenberg|knowledge|site|wordpress|wp-|stylesheet|menus?|navigation|'
            . 'authors?|users?|roles?|comments?|taxonom(?:y|ies)|terms?|categor(?:y|ies)|tags?|'
            . 'widgets?|sidebars?|custom fields?|metadata|meta|plugin settings?|registered settings?|'
            . 'slug|permalink|url|homepage|landing'
            . ')\b/i',
            $message,
        );
    }

    private static function looks_like_frontend(string $message): bool {
        return (bool) preg_match(
            '/\b('
            . 'frontend|front-end|css|scss|sticky|spacing|layout|toc|sidenav|overlap|'
            . 'editor\s+vs|live\s+site|rendered|inspect|additional\s+css|custom\s+css'
            . ')\b/i',
            $message,
        );
    }

    private static function looks_like_diagnosis(string $message): bool {
        return (bool) preg_match(
            '/\b('
            . 'error|fatal|warning|exception|debug\.log|white\s+screen|wsod|timeout|'
            . 'broken|crash|diagnose|site\s+health|stack\s+trace|php'
            . ')\b/i',
            $message,
        );
    }

    private static function looks_like_settings_or_theme(string $message): bool {
        return (bool) preg_match(
            '/\b('
            . 'settings?|options?|switch\s+theme|activate\s+theme|permalinks?|'
            . 'site\s+title|tagline|timezone|date\s+format'
            . ')\b/i',
            $message,
        );
    }

    private static function looks_like_template_or_styles(string $message): bool {
        return (bool) preg_match(
            '/\b('
            . 'template\s+part|fse|site\s+editor|global\s+styles?|theme\.json|'
            . 'design\s+tokens?|color\s+palette|typography'
            . ')\b/i',
            $message,
        );
    }
}
