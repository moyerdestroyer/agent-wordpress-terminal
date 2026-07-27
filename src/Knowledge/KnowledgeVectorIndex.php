<?php

/**
 * Vector index resolution.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

final class KnowledgeVectorIndex {
    public static function resolve(): KnowledgeVectorIndexInterface {
        $local = new LocalKnowledgeVectorIndex();

        /**
         * Filters the vector index used for Knowledge embeddings.
         *
         * Adapters must use AWPT chunk IDs and leave the MySQL chunk corpus authoritative.
         *
         * @param KnowledgeVectorIndexInterface $local Built-in local vector index.
         */
        $filtered = apply_filters('awpt_knowledge_vector_index', $local);

        return $filtered instanceof KnowledgeVectorIndexInterface ? $filtered : $local;
    }
}
