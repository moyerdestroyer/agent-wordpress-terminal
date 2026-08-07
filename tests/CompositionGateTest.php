<?php

/**
 * CompositionGate façade smoke tests.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Domain\CompositionGate;

function test_composition_gate_evaluate_returns_contract_shape(): void {
    awpt_test_reset_state();

    $gate = new CompositionGate();
    $result = $gate->evaluate(
        '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
        ['work_type' => 'edit', 'phase' => 'propose'],
    );

    Assert::true(is_array($result['findings'] ?? null), 'findings list present');
    Assert::true(is_string($result['content'] ?? null), 'content present');
    Assert::true(is_string($result['ruleset_hash'] ?? null) && '' !== $result['ruleset_hash'], 'ruleset hash present');
    Assert::true(is_array($result['agent_feedback'] ?? null), 'agent_feedback present');
    Assert::true(null === $gate->blocking_error([]), 'no findings means no blocking error');
}

test_composition_gate_evaluate_returns_contract_shape();
