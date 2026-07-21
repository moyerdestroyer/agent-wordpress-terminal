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
     * @return array{mode: string, label: string, core_available: bool, legacy_guidelines_available: bool, post_type: string}
     */
    public function status(): array {
        $backends = $this->backends();
        $active = $this->active_backend($backends);
        $core = $this->backend_family_available($backends, 'core');
        $legacy = $this->backend_family_available($backends, 'legacy');

        $mode = null !== $active ? $active['mode'] : 'fallback_index';
        $label = null !== $active ? $active['label'] : __('AWPT index only', 'agent-wordpress-terminal');

        return [
            'mode' => $mode,
            'label' => $label,
            'core_available' => $core,
            'legacy_guidelines_available' => $legacy,
            'post_type' => null !== $active ? $active['post_type'] : '',
        ];
    }

    /**
     * List durable knowledge sources that should be indexed.
     *
     * @return list<array<string, mixed>>
     */
    public function list_sources(): array {
        $backend = $this->active_backend($this->backends());

        if (null === $backend) {
            return [];
        }

        return $this->list_post_sources($backend['post_type'], $backend['taxonomy'], $backend['kind']);
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

        $backend = $this->backend_for_post_type($post->post_type);

        if (null === $backend) {
            return new \WP_Error('awpt_knowledge_wrong_type', __(
                'The requested item is not a Knowledge record.',
                'agent-wordpress-terminal',
            ));
        }

        return $this->mapper->from_post($post, $backend['kind'], $backend['taxonomy']);
    }

    /**
     * Knowledge storage backends supported across the 7.1 rollout.
     *
     * Plugins testing a renamed or companion-provided backend can add a definition
     * without replacing AWPT's repository implementation.
     *
     * @return list<array{post_type: string, taxonomy: string, kind: string, mode: string, label: string, family: string}>
     */
    private function backends(): array {
        $defaults = [
            [
                'post_type' => 'wp_knowledge',
                'taxonomy' => 'wp_knowledge_type',
                'kind' => 'core_knowledge',
                'mode' => 'core',
                'label' => __('Core Knowledge', 'agent-wordpress-terminal'),
                'family' => 'core',
            ],
            [
                'post_type' => 'wp_guideline',
                'taxonomy' => 'wp_guideline_type',
                'kind' => 'legacy_guideline',
                'mode' => 'legacy_guidelines',
                'label' => __('Legacy Guidelines', 'agent-wordpress-terminal'),
                'family' => 'legacy',
            ],
        ];

        /**
         * Filters Knowledge post-type backends AWPT can ingest.
         *
         * @param array<int, array<string, string>> $defaults Backend definitions.
         */
        /** @var mixed $filtered */
        $filtered = apply_filters('awpt_knowledge_backends', $defaults);

        if (!is_array($filtered)) {
            return $defaults;
        }

        $backends = [];
        $array_backends = array_filter($filtered, 'is_array');

        foreach ($array_backends as $backend) {
            $post_type = sanitize_key((string) ($backend['post_type'] ?? ''));
            $kind = sanitize_key((string) ($backend['kind'] ?? ''));

            if ('' === $post_type || '' === $kind) {
                continue;
            }

            $backends[] = [
                'post_type' => $post_type,
                'taxonomy' => sanitize_key((string) ($backend['taxonomy'] ?? '')),
                'kind' => $kind,
                'mode' => sanitize_key((string) ($backend['mode'] ?? $kind)),
                'label' => sanitize_text_field((string) ($backend['label'] ?? $post_type)),
                'family' => sanitize_key((string) ($backend['family'] ?? 'extension')),
            ];
        }

        return [] !== $backends ? $backends : $defaults;
    }

    /**
     * @param list<array{post_type: string, taxonomy: string, kind: string, mode: string, label: string, family: string}> $backends
     * @return array{post_type: string, taxonomy: string, kind: string, mode: string, label: string, family: string}|null
     */
    private function active_backend(array $backends): ?array {
        foreach ($backends as $backend) {
            if (post_type_exists($backend['post_type'])) {
                return $backend;
            }
        }

        return null;
    }

    /**
     * @return array{post_type: string, taxonomy: string, kind: string, mode: string, label: string, family: string}|null
     */
    private function backend_for_post_type(string $post_type): ?array {
        foreach ($this->backends() as $backend) {
            if ($backend['post_type'] === $post_type && post_type_exists($post_type)) {
                return $backend;
            }
        }

        return null;
    }

    /**
     * @param list<array{post_type: string, taxonomy: string, kind: string, mode: string, label: string, family: string}> $backends
     */
    private function backend_family_available(array $backends, string $family): bool {
        foreach ($backends as $backend) {
            if ($backend['family'] === $family && post_type_exists($backend['post_type'])) {
                return true;
            }
        }

        return false;
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
