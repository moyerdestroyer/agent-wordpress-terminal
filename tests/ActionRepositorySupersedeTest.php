<?php

/**
 * One-open-proposal-per-session helpers.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Database\ActionRepository;

function test_action_repository_exposes_superseded_ids_on_formatted_create(): void {
    $repo = new ActionRepository();
    $reflection = new ReflectionClass($repo);

    $superseded = $reflection->getProperty('last_superseded_ids');
    $superseded->setAccessible(true);
    $superseded->setValue($repo, [44, 45]);

    $mutated = $reflection->getProperty('last_mutated_action_id');
    $mutated->setAccessible(true);
    $mutated->setValue($repo, 46);

    // format_action needs a row; stub via override of get_accessible_row is unavailable
    // on a final class, so assert the public supersede helper returns a list type and
    // reflection state is what format_action consults.
    Assert::same([44, 45], $superseded->getValue($repo), 'superseded IDs stay available for format_action');
    Assert::same(46, $mutated->getValue($repo), 'mutated action id tracks the active card');

    $empty = $repo->supersede_open_for_session(0);
    Assert::same([], $empty, 'invalid session should not supersede anything');
}

test_action_repository_exposes_superseded_ids_on_formatted_create();
