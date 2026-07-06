<?php

/**
 * Tests for AWPT\Support\SessionTitleSuggester.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Support\SessionTitleSuggester;

function test_session_title_suggester(): void
{
    $suggester = new SessionTitleSuggester();

    awpt_test_reset_state();
    Assert::same(
        'Make The SEM Icon Bigger On The About Page',
        $suggester->suggest('Can you make the SEM icon bigger on the about page?', ['title' => 'New session']),
        'default sessions should be titled from the first useful prompt',
    );

    Assert::same(
        null,
        $suggester->suggest('Make the SEM icon bigger', ['title' => 'Already renamed']),
        'manual session titles should not be overwritten',
    );

    Assert::same(
        null,
        $suggester->suggest('/focus 42', ['title' => 'New session']),
        'slash commands should not title sessions',
    );

    awpt_test_reset_state();
    $post = new WP_Post();
    $post->ID = 42;
    $post->post_title = 'About';
    $GLOBALS['awpt_test_posts'][42] = $post;
    Assert::same(
        'About: Make The SEM Icon Bigger',
        $suggester->suggest('Make the SEM icon bigger', ['title' => 'New session', 'focus_post_id' => 42]),
        'focused post title should prefix ambiguous prompts',
    );
}

test_session_title_suggester();
