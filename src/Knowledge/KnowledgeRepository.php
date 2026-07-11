<?php

/**
 * Knowledge source discovery.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Reads Core Knowledge and compatible legacy guideline sources.
 */
final class KnowledgeRepository {
    public const SITE_CONTENT_INDEX_CAP = 500;

    private const CORE_POST_TYPE = 'wp_knowledge';
    private const CORE_TAXONOMY = 'wp_knowledge_type';
    private const LEGACY_POST_TYPE = 'wp_guideline';
    private const LEGACY_TAXONOMY = 'wp_guideline_type';

    private KnowledgePostSourceMapper $mapper;
    private KnowledgeSiteContentTypes $site_content_types;

    public function __construct(
        ?KnowledgePostSourceMapper $mapper = null,
        ?KnowledgeSiteContentTypes $site_content_types = null,
    ) {
        $this->mapper = $mapper ?? new KnowledgePostSourceMapper();
        $this->site_content_types = $site_content_types ?? new KnowledgeSiteContentTypes();
    }

    /**
     * Return active Knowledge backend metadata.
     *
     * @return array{mode: string, label: string, core_available: bool, legacy_guidelines_available: bool}
     */
    public function status(): array {
        $core = post_type_exists(self::CORE_POST_TYPE);
        $legacy = post_type_exists(self::LEGACY_POST_TYPE);

        if ($core) {
            $mode = 'core';
            $label = __('Core Knowledge', 'agent-wordpress-terminal');
        } elseif ($legacy) {
            $mode = 'legacy_guidelines';
            $label = __('Legacy Guidelines', 'agent-wordpress-terminal');
        } else {
            $mode = 'fallback_index';
            $label = __('AWPT index only', 'agent-wordpress-terminal');
        }

        return [
            'mode' => $mode,
            'label' => $label,
            'core_available' => $core,
            'legacy_guidelines_available' => $legacy,
        ];
    }

    /**
     * List durable knowledge sources that should be indexed.
     *
     * @return list<array<string, mixed>>
     */
    public function list_sources(): array {
        if (post_type_exists(self::CORE_POST_TYPE)) {
            return $this->list_post_sources(self::CORE_POST_TYPE, self::CORE_TAXONOMY, 'core_knowledge');
        }

        if (post_type_exists(self::LEGACY_POST_TYPE)) {
            return $this->list_post_sources(self::LEGACY_POST_TYPE, self::LEGACY_TAXONOMY, 'legacy_guideline');
        }

        return [];
    }

    /**
     * List WordPress content sources that are useful for retrieval but not durable Knowledge.
     *
     * @return list<array<string, mixed>>
     */
    public function list_site_content_sources(): array {
        $post_types = $this->site_content_types->installed();

        if ([] === $post_types) {
            return [];
        }

        return $this->list_post_sources($post_types, '', 'wp_content');
    }

    /**
     * @return array{cap: int, eligible: int}
     */
    public function site_content_index_stats(): array {
        return $this->site_content_types->index_stats(self::SITE_CONTENT_INDEX_CAP);
    }

    /**
     * Read one Knowledge post by WordPress post ID.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function read_knowledge_post(int $post_id): array|\WP_Error {
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post || !current_user_can('read_post', $post_id)) {
            return new \WP_Error('awpt_knowledge_not_found', __(
                'Knowledge item not found.',
                'agent-wordpress-terminal',
            ));
        }

        if (!in_array($post->post_type, [self::CORE_POST_TYPE, self::LEGACY_POST_TYPE], true)) {
            return new \WP_Error('awpt_knowledge_wrong_type', __(
                'The requested item is not a Knowledge record.',
                'agent-wordpress-terminal',
            ));
        }

        $kind = self::CORE_POST_TYPE === $post->post_type ? 'core_knowledge' : 'legacy_guideline';

        return $this->mapper->from_post($post, $kind, $this->mapper->taxonomy_for_post_type($post->post_type));
    }

    /**
     * Format applicable guidelines for provider prompt injection.
     */
    public function format_guidelines_for_prompt(): string {
        $sources = array_values(array_filter(
            $this->list_sources(),
            static fn(array $source): bool => (
                in_array('guideline', (array) ($source['types'] ?? []), true)
                || 'legacy_guideline' === (string) ($source['kind'] ?? '')
            ),
        ));

        if ([] === $sources) {
            return '';
        }

        $parts = [];

        foreach (array_slice($sources, 0, 5) as $source) {
            $content = wp_strip_all_tags((string) ($source['content'] ?? ''));

            if ('' === $content) {
                continue;
            }

            $parts[] = sprintf(
                '<guideline source="%s">%s</guideline>',
                esc_attr((string) ($source['label'] ?? 'Knowledge guideline')),
                esc_html(mb_substr($content, 0, 5000, 'UTF-8')),
            );
        }

        return [] === $parts ? '' : "<knowledge-guidelines>\n" . implode("\n", $parts) . "\n</knowledge-guidelines>";
    }

    /**
     * List post-backed sources.
     *
     * @param string|list<string> $post_type Post type(s).
     * @return list<array<string, mixed>>
     */
    private function list_post_sources(string|array $post_type, string $taxonomy, string $kind): array {
        $statuses = ['publish', 'draft', 'pending', 'private'];

        // Media Library attachments use inherit; include them so PDFs are indexable.
        if (is_array($post_type) && in_array('attachment', $post_type, true)) {
            $statuses[] = 'inherit';
        } elseif ('attachment' === $post_type) {
            $statuses[] = 'inherit';
        }

        $query = new \WP_Query([
            'post_type' => $post_type,
            'post_status' => $statuses,
            'posts_per_page' => self::SITE_CONTENT_INDEX_CAP,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => '' !== $taxonomy,
        ]);

        $sources = [];

        foreach ($query->posts as $post) {
            if (!$post instanceof \WP_Post || !current_user_can('read_post', $post->ID)) {
                continue;
            }

            $sources[] = $this->mapper->from_post($post, $kind, $taxonomy);
        }

        return $sources;
    }
}
