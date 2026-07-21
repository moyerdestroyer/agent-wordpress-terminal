<?php

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Support\BlockTree;
use AWPT\Support\PatternCatalog;

if (!defined('ABSPATH')) {
    exit();
}

/** Reads a pattern's raw block composition before the agent reuses it. */
final class ReadPattern implements AbilityInterface {
    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-pattern',
            'label' => __('Read Pattern', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads one registered or reusable pattern with its Gutenberg block tree.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_read(array $input): bool {
        unset($input);
        return current_user_can('edit_posts');
    }

    /** @param array<string, mixed> $input @return array<string, mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error {
        $pattern = new PatternCatalog()->find((string) ($input['name'] ?? ''));

        if (null === $pattern) {
            return new \WP_Error('awpt_pattern_not_found', __('Pattern not found.', 'agent-wordpress-terminal'), [
                'status' => 404,
            ]);
        }

        $content = (string) ($pattern['content'] ?? '');
        $tree = BlockTree::from_content($content);

        return array_merge(new PatternCatalog()->summary($pattern), [
            'content' => $content,
            'blocks' => $tree->normalized(),
        ]);
    }
}
