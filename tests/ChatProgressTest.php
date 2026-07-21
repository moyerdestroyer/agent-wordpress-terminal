<?php

/** Tests live chat progress state. @package AWPT */

declare(strict_types=1);

use AWPT\Agent\ChatProgress;

function test_chat_progress_tracks_ordered_turn_phases(): void {
    awpt_test_reset_state();
    $progress = new ChatProgress();
    $started = $progress->begin(49, 'turn-abc');
    $tools = $progress->update(49, 'turn-abc', [
        'phase' => 'tools',
        'label' => 'Searching patterns',
        'detail' => 'Tool 1 of 3',
        'completed' => 0,
        'total' => 3,
    ]);
    $complete = $progress->complete(49, 'turn-abc');

    Assert::same('starting', $started['phase'] ?? null, 'a turn should begin with a concrete starting phase');
    Assert::same(2, $tools['sequence'] ?? null, 'each update should advance the progress sequence');
    Assert::same(3, $tools['total'] ?? null, 'known tool batch size should be available to the UI');
    Assert::same('complete', $complete['state'] ?? null, 'the terminal state should be explicit');
    Assert::same($complete, $progress->read(49, 'turn-abc'), 'the latest state should be pollable');
}

function test_chat_progress_isolated_by_session_and_turn(): void {
    awpt_test_reset_state();
    $progress = new ChatProgress();
    $progress->begin(49, 'turn-one');

    Assert::same('pending', $progress->read(49, 'turn-two')['state'] ?? null, 'turns must not share progress');
    Assert::same('pending', $progress->read(50, 'turn-one')['state'] ?? null, 'sessions must not share progress');
}

test_chat_progress_tracks_ordered_turn_phases();
test_chat_progress_isolated_by_session_and_turn();
