<?php

/**
 * Optional embedding generation for hybrid Knowledge retrieval.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Talks to OpenRouter or OpenAI embeddings endpoints when a key is available.
 */
final class EmbeddingService {
    public const OPTION_ENABLED = 'awpt_knowledge_embeddings_enabled';
    public const OPTION_MODEL = 'awpt_knowledge_embedding_model';
    public const DEFAULT_MODEL = 'openai/text-embedding-3-small';

    private EmbeddingApiClient $client;

    public function __construct(?EmbeddingApiClient $client = null) {
        $this->client = $client ?? new EmbeddingApiClient();
    }

    public function is_enabled(): bool {
        return '1' === (string) get_option(self::OPTION_ENABLED, '1') && $this->is_available();
    }

    public function is_available(): bool {
        return '' !== $this->client->resolve_api_key();
    }

    public function model(): string {
        $model = trim((string) get_option(self::OPTION_MODEL, self::DEFAULT_MODEL));

        return '' !== $model ? $model : self::DEFAULT_MODEL;
    }

    public function provider_label(): string {
        return $this->client->provider_label();
    }

    /**
     * @return list<float>|null
     */
    public function embed(string $text): ?array {
        $vectors = $this->embed_many([$text]);

        return $vectors[0] ?? null;
    }

    /**
     * @param list<string> $texts
     * @return list<?list<float>>
     */
    public function embed_many(array $texts): array {
        if (!$this->is_enabled() || [] === $texts) {
            return array_fill(0, count($texts), null);
        }

        return $this->client->request($this->model(), $texts);
    }

    /**
     * Cosine similarity in [0, 1] after shifting from [-1, 1].
     *
     * @param list<float> $left
     * @param list<float> $right
     */
    public function cosine_similarity(array $left, array $right): float {
        $n = min(count($left), count($right));

        if ($n <= 0) {
            return 0.0;
        }

        $dot = 0.0;
        $left_norm = 0.0;
        $right_norm = 0.0;

        for ($i = 0; $i < $n; ++$i) {
            $dot += $left[$i] * $right[$i];
            $left_norm += $left[$i] * $left[$i];
            $right_norm += $right[$i] * $right[$i];
        }

        if ($left_norm <= 0.0 || $right_norm <= 0.0) {
            return 0.0;
        }

        $cosine = $dot / (sqrt($left_norm) * sqrt($right_norm));

        return max(0.0, min(1.0, ($cosine + 1.0) / 2.0));
    }
}
