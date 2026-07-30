<?php

/** Tests bounded generic resource payload sanitization. @package AWPT */

declare(strict_types=1);

use AWPT\Support\ResourceValueSanitizer;

function test_resource_value_sanitizer_preserves_agent_selected_object_fields_and_nested_lists(): void {
    $clean = new ResourceValueSanitizer()->sanitize_object([
        'menu_name' => 'Documentation',
        'items' => [
            ['title' => 'Overview', 'url' => '/docs/'],
            ['title' => 'API', 'url' => '/docs/api/'],
        ],
        2 => 'not an object field',
    ]);

    Assert::same('Documentation', $clean['menu_name'] ?? null, 'object keys should be normalized, not preselected');
    Assert::same(
        '/docs/api/',
        $clean['items'][1]['url'] ?? null,
        'nested operation-specific values should remain available to resource handlers',
    );
    Assert::true(!array_key_exists(2, $clean), 'numeric top-level keys should not enter a JSON object payload');
}

test_resource_value_sanitizer_preserves_agent_selected_object_fields_and_nested_lists();
