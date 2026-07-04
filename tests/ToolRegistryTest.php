<?php

/**
 * Tests for AWPT\Agent\ToolRegistry.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\ToolRegistry;

function test_tool_registry_proposal_abilities(): void
{
    $names = ToolRegistry::proposal_ability_names();

    Assert::true(
        in_array('awpt/propose-new-post', $names, true),
        'new-post proposals must surface as staged action cards',
    );
    Assert::true(
        ToolRegistry::is_proposal_ability('awpt/propose-new-post'),
        'is_proposal_ability should recognize awpt/propose-new-post',
    );
    Assert::false(
        ToolRegistry::is_proposal_ability('awpt/sideload-media'),
        'non-proposal tools should not be treated as staged actions',
    );
}

test_tool_registry_proposal_abilities();
