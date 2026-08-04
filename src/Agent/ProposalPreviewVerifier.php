<?php

/**
 * Same-turn rendered verification for staged proposals.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

use AWPT\Abilities\InspectRenderedElement;

if (!defined('ABSPATH')) {
    exit();
}

final class ProposalPreviewVerifier {
    /**
     * @param array<int, array<string, mixed>> $proposal_calls
     * @return array{tool_call: array<string, mixed>, message: array<string, mixed>}|null
     */
    public function verify(array $proposal_calls): ?array {
        $proposal = end($proposal_calls);

        if (!is_array($proposal)) {
            return null;
        }

        $output = is_array($proposal['output'] ?? null) ? $proposal['output'] : [];
        $action_id = (int) ($output['id'] ?? 0);
        $payload = is_array($output['payload'] ?? null) ? $output['payload'] : [];
        /** @var array<string, mixed> $payload */
        $preview_url = (string) ($payload['preview_url'] ?? '');

        if ($action_id <= 0 || '' === $preview_url) {
            return null;
        }

        $input = [
            'action_id' => $action_id,
            'selector' => $this->selector_for_payload($payload),
            'include_screenshot' => true,
        ];
        $result = new InspectRenderedElement()->execute($input);

        if (is_wp_error($result)) {
            return [
                'tool_call' => [
                    'tool' => 'awpt/inspect-rendered-element',
                    'input' => $input,
                    'output' => ['error' => $result->get_error_message()],
                    'status' => 'failed',
                    'provider_call_id' => 'awpt_visual_verify_' . wp_generate_password(8, false),
                ],
                'message' => [
                    'role' => 'user',
                    'content' =>
                        'The staged preview could not be rendered automatically: '
                            . $result->get_error_message()
                            . ' Keep the proposal available for human preview and do not claim visual verification.',
                ],
            ];
        }

        $provider_output = new ToolResultTruncator()->for_provider('awpt/inspect-rendered-element', $result);
        $verification_instruction = true === ($result['rendered'] ?? false)
            ? "Review this rendered evidence against the user's exact request."
            : 'A headless browser was unavailable, so this is static fallback evidence only. Do not claim that the preview was visually verified. The reported main_heading_outline and main_h1_count are authoritative semantic evidence; revise the proposal if they contradict the requested hierarchy.';
        $text =
            "Automatic staged-preview inspection result:\n"
            . (string) wp_json_encode($provider_output)
            . "\n"
            . $verification_instruction
            . ' If the evidence is not satisfactory, revise with the most targeted proposal tool. If it is satisfactory, respond without another tool call.';
        $visual = new RenderedInspectionVisualEvidence()->build($result);
        $content = is_array($visual['content'] ?? null)
            ? [
                ['type' => 'text', 'text' => $text],
                ...$visual['content'],
            ]
            : $text;
        $provider_call_id = 'awpt_visual_verify_' . wp_generate_password(8, false);

        return [
            'tool_call' => [
                'tool' => 'awpt/inspect-rendered-element',
                'input' => $input,
                'output' => new ToolResultTruncator()->for_storage('awpt/inspect-rendered-element', $result),
                'status' => 'success',
                'provider_call_id' => $provider_call_id,
            ],
            'message' => [
                'role' => 'user',
                'content' => $content,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function selector_for_payload(array $payload): string {
        $block_name = trim((string) ($payload['block_name'] ?? ''));

        if ('' === $block_name || !str_contains($block_name, '/')) {
            return '';
        }

        [$namespace, $name] = array_pad(explode('/', $block_name, 2), 2, '');
        $prefix = 'core' === $namespace ? 'wp-block' : 'wp-block-' . sanitize_html_class($namespace);
        $class = trim($prefix . '-' . sanitize_html_class($name), '-');

        return '' !== $class ? '.' . $class : '';
    }
}
