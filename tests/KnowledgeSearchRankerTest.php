<?php

/**
 * Tests for Knowledge search tokenization.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Knowledge\KnowledgeSearchRanker;

function test_knowledge_tokens_drop_instruction_stopwords(): void {
    $ranker = new KnowledgeSearchRanker();
    $tokens = $ranker->tokens('Please write up a 1000ish word post about sharks.');

    Assert::true(in_array('sharks', $tokens, true), 'content topic token kept');
    Assert::false(in_array('write', $tokens, true), 'write is a stopword');
    Assert::false(in_array('post', $tokens, true), 'post is a stopword');
    Assert::false(in_array('about', $tokens, true), 'about is a stopword');
    Assert::false(in_array('please', $tokens, true), 'please is a stopword');
    Assert::false(in_array('word', $tokens, true), 'word is a stopword');
    Assert::false(in_array('1000ish', $tokens, true), 'numeric length cues dropped');
}

test_knowledge_tokens_drop_instruction_stopwords();
