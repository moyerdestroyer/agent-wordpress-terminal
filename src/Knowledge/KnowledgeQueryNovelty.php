<?php

/**
 * Equivalent-query detection for Knowledge refinement.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

final class KnowledgeQueryNovelty {
    /** @param list<string> $prior_queries */
    public function repeats(string $query, array $prior_queries): bool {
        $tokens = new KnowledgeSearchRanker()->tokens(new KnowledgeQueryNormalizer()->for_retrieval($query));

        if ([] === $tokens) {
            return false;
        }

        $current = array_fill_keys($tokens, true);

        foreach ($prior_queries as $prior_query) {
            $prior_tokens = new KnowledgeSearchRanker()->tokens(new KnowledgeQueryNormalizer()->for_retrieval(
                $prior_query,
            ));

            if ([] === $prior_tokens) {
                continue;
            }

            $prior = array_fill_keys($prior_tokens, true);
            $intersection = count(array_intersect_key($current, $prior));
            $union = count($current + $prior);

            if ($union > 0 && ($intersection / $union) >= 0.80) {
                return true;
            }
        }

        return false;
    }
}
