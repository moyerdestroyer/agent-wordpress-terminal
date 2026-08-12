<?php

/**
 * Scoped prompt guidance from active domain packs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Domain;

use AWPT\Agent\TurnProfile;
use AWPT\Knowledge\KnowledgeRepository;
use AWPT\Support\ArrayKey;

if (!defined('ABSPATH')) {
    exit();
}

final class DomainGuidanceResolver {
    private DomainPackRegistry $registry;

    public function __construct(?DomainPackRegistry $registry = null) {
        $this->registry = $registry ?? DomainPackRegistry::instance();
    }

    public function format_for_prompt(TurnProfile $profile, int $max_chars = 8_000): string {
        $scope = $this->scope($profile);
        $entries = [];
        $overrides = $this->site_overrides();

        foreach ($this->registry->active() as $pack) {
            foreach (ArrayKey::list_of_maps($pack['guidance'] ?? null) as $guidance) {
                $applies = ArrayKey::list_of_strings($guidance['applies_to'] ?? null);
                $audience = (string) ($guidance['audience'] ?? 'editor');

                if (
                    empty($guidance['hard'])
                    || 'developer' === $audience
                    || !in_array('all', $applies, true) && !in_array($scope, $applies, true)
                    || !$this->trigger_matches($guidance, $profile->message)
                ) {
                    continue;
                }

                $content = $this->content($pack, $guidance, $overrides);

                if ('' === trim($content)) {
                    continue;
                }

                $entries[] = [
                    'priority' => (int) ($guidance['priority'] ?? 50),
                    'text' => sprintf(
                        '<domain-guidance pack="%s" version="%s" id="%s" scope="%s" hard="%s">%s</domain-guidance>',
                        esc_attr((string) $pack['id']),
                        esc_attr((string) $pack['version']),
                        esc_attr((string) $guidance['id']),
                        esc_attr($scope),
                        'true',
                        esc_html($content),
                    ),
                ];
            }
        }

        if ([] === $entries) {
            return '';
        }

        usort($entries, static fn(array $left, array $right): int => $right['priority'] <=> $left['priority']);
        $header = 'Hard constraints from active Domain Packs follow. Other relevant modules are references in awpt-work-context and must be read with awpt/read-domain-guidance only when needed. Theme guidance cannot override AWPT safety, permissions, evidence, staging, or human approval requirements.';
        $remaining = max(1_000, $max_chars - mb_strlen($header));
        $parts = [];

        foreach ($entries as $entry) {
            $text = $entry['text'];

            if ($remaining <= 0) {
                break;
            }

            $parts[] = mb_substr($text, 0, $remaining, 'UTF-8');
            $remaining -= mb_strlen(end($parts) ?: '', 'UTF-8');
        }

        return "<active-domain-packs>\n{$header}\n" . implode("\n", $parts) . "\n</active-domain-packs>";
    }

    /**
     * Read one active guidance module with any site Knowledge override applied.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function read(string $pack_id, string $guidance_id): array|\WP_Error {
        $pack_id = sanitize_key($pack_id);
        $guidance_id = sanitize_key($guidance_id);
        $overrides = $this->site_overrides();

        foreach ($this->registry->active() as $pack) {
            if ($pack_id !== (string) $pack['id']) {
                continue;
            }

            foreach (ArrayKey::list_of_maps($pack['guidance'] ?? null) as $guidance) {
                if ($guidance_id !== (string) $guidance['id']) {
                    continue;
                }

                $content = $this->content($pack, $guidance, $overrides);

                if ('' === trim($content)) {
                    break;
                }

                return [
                    'pack_id' => $pack_id,
                    'pack_version' => (string) $pack['version'],
                    'guidance_id' => $guidance_id,
                    'label' => (string) $guidance['label'],
                    'hard' => (bool) $guidance['hard'],
                    'applies_to' => ArrayKey::list_of_strings($guidance['applies_to'] ?? null),
                    'content' => $content,
                ];
            }
        }

        return new \WP_Error(
            'awpt_domain_guidance_not_found',
            __('The requested active Domain Pack guidance module was not found.', 'agent-wordpress-terminal'),
            ['status' => 404],
        );
    }

    private function scope(TurnProfile $profile): string {
        return match (true) {
            (bool) preg_match('/\b(navigation|menu|submenu|nav)\b/i', $profile->message) => 'navigation',
            (bool) preg_match(
                '/\b(global styles?|theme\.json|palette|site-wide|font family|design tokens?)\b/i',
                $profile->message,
            )
                => 'global_styles',
            $profile->needs_compose_module() => 'compose',
            $profile->needs_edit_module() => 'edit',
            $profile->needs_template_module() => 'template',
            $profile->needs_settings_module() => 'settings',
            $profile->needs_diagnosis_module() => 'diagnose',
            default => 'investigate',
        };
    }

    /**
     * @param array<string, mixed> $guidance
     */
    private function trigger_matches(array $guidance, string $message): bool {
        $triggers = ArrayKey::list_of_strings($guidance['triggers'] ?? null);

        if ([] === $triggers) {
            return true;
        }

        $message = mb_strtolower($message);

        return (
            [] !== array_filter($triggers, static fn(string $trigger): bool => str_contains(
                $message,
                mb_strtolower($trigger),
            ))
        );
    }

    /**
     * @return array<string, array{content: string, mode: string}>
     */
    private function site_overrides(): array {
        $overrides = [];

        foreach (new KnowledgeRepository()->list_sources() as $source) {
            $metadata = is_array($source['metadata'] ?? null) ? $source['metadata'] : [];
            $pack_id = sanitize_key((string) ($metadata['awpt_pack_id'] ?? ''));
            $guidance_id = sanitize_key((string) ($metadata['awpt_guidance_id'] ?? ''));

            if (
                '' === $pack_id
                || '' === $guidance_id
                || 'publish' !== (string) ($metadata['status'] ?? '')
                || !in_array('guideline', ArrayKey::list_of_strings($source['types'] ?? null), true)
            ) {
                continue;
            }

            $overrides[$pack_id . ':' . $guidance_id] = [
                'content' => (string) ($source['content'] ?? ''),
                'mode' => 'replace' === (string) ($metadata['awpt_override_mode'] ?? '') ? 'replace' : 'extend',
            ];
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $pack
     * @param array<string, mixed> $guidance
     * @param array<string, array{content: string, mode: string}> $overrides
     */
    private function content(array $pack, array $guidance, array $overrides): string {
        $guidance_pack = $pack;
        $guidance_pack['_root'] = (string) ($guidance['_root'] ?? $pack['_root'] ?? '');
        $content = wp_strip_all_tags($this->registry->read_pack_file(
            $guidance_pack,
            (string) ($guidance['path'] ?? ''),
            32_768,
        ));
        $override_key = (string) $pack['id'] . ':' . (string) $guidance['id'];

        if (array_key_exists($override_key, $overrides)) {
            $override = $overrides[$override_key];
            $override_content = wp_strip_all_tags($override['content']);
            $content = 'replace' === $override['mode']
                ? $override_content
                : trim($content . "\n\nSite-specific extension:\n" . $override_content);
        }

        return $content;
    }
}
