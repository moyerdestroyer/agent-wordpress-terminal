<?php

/**
 * Tests text-only provider vision preprocessing.
 *
 * @package AWPT
 */

declare(strict_types=1);

use AWPT\Agent\ProviderInterface;
use AWPT\Agent\VisionEvidencePreprocessor;

final class AwptVisionTestProvider implements ProviderInterface {
    public int $completions = 0;

    public function __construct(
        private bool $accepts_images,
        private array|WP_Error $result,
        private string $name = 'Vision test',
    ) {}

    public function complete(array $messages, array $tools = [], array $options = []): array|WP_Error {
        unset($messages, $tools, $options);
        ++$this->completions;

        return $this->result;
    }

    public function accepts_image_input(): bool {
        return $this->accepts_images;
    }

    public function get_name(): string {
        return $this->name;
    }
}

/** @return array<int, array<string, mixed>> */
function awpt_vision_test_messages(): array {
    return [[
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'Use attachment #126 in the softball page hero.'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,softball']],
        ],
    ]];
}

function test_vision_preprocessor_describes_images_once_for_text_only_primary(): void {
    $sidecar = new AwptVisionTestProvider(false, [
        'content' => wp_json_encode([
            'items' => [[
                'ref' => 'attachment:126',
                'description' => 'A softball player batting under stadium lights.',
                'visible_text' => '',
                'design_notes' => 'Wide action crop with a dark blue background.',
            ]],
        ]),
        'raw_tool_calls' => [],
        'message' => ['role' => 'assistant', 'content' => ''],
        'model' => 'vision/selected',
        'usage' => [],
    ]);
    $primary = new AwptVisionTestProvider(false, []);
    $preprocessor = new VisionEvidencePreprocessor($sidecar);
    $first = $preprocessor->prepare(awpt_vision_test_messages(), $primary, 4, 'turn-vision');
    $encoded = (string) wp_json_encode($first['messages']);

    Assert::same(1, $sidecar->completions, 'text-only primary should invoke the vision sidecar once');
    Assert::false(str_contains($encoded, '"type":"image_url"'), 'raw image parts must not reach text-only primary');
    Assert::true(
        str_contains($encoded, 'softball player batting'),
        'task-relevant visual description should be injected for the primary',
    );
    Assert::true(str_contains($encoded, 'attachment:126'), 'visual analysis must retain the stable attachment ref');
    Assert::same('', $preprocessor->notice(), 'successful analysis should not produce a degradation notice');

    $preprocessor->prepare(awpt_vision_test_messages(), $primary, 4, 'turn-vision');
    Assert::same(1, $sidecar->completions, 'the same image must not be analyzed twice in one turn');
}

function test_vision_preprocessor_degrades_with_warning_after_bounded_retry(): void {
    $sidecar = new AwptVisionTestProvider(false, new WP_Error('awpt_provider_request_failed', 'Vision unavailable.', [
        'status' => 503,
    ]));
    $primary = new AwptVisionTestProvider(false, []);
    $preprocessor = new VisionEvidencePreprocessor($sidecar);
    $prepared = $preprocessor->prepare(awpt_vision_test_messages(), $primary);
    $encoded = (string) wp_json_encode($prepared['messages']);

    Assert::same(2, $sidecar->completions, 'transient vision failures should receive one bounded retry');
    Assert::false(str_contains($encoded, '"type":"image_url"'), 'failed vision must still protect text-only primary');
    Assert::true(
        str_contains($encoded, 'could not be analyzed'),
        'primary should be constrained from making unsupported image claims',
    );
    Assert::true(
        str_contains($preprocessor->notice(), 'Visual analysis was unavailable'),
        'admin should receive a clear non-blocking warning',
    );
}

function test_vision_preprocessor_bypasses_vision_capable_primary(): void {
    $sidecar = new AwptVisionTestProvider(false, []);
    $primary = new AwptVisionTestProvider(true, []);
    $preprocessor = new VisionEvidencePreprocessor($sidecar);
    $prepared = $preprocessor->prepare(awpt_vision_test_messages(), $primary);
    $encoded = (string) wp_json_encode($prepared['messages']);

    Assert::same(0, $sidecar->completions, 'vision-capable primary should not invoke the sidecar');
    Assert::true(str_contains($encoded, '"type":"image_url"'), 'vision-capable primary should retain image parts');
}

function test_vision_preprocessor_accepts_plain_text_fallback(): void {
    $sidecar = new AwptVisionTestProvider(false, [
        'content' => 'Attachment 126 shows a jubilant softball team in blue uniforms.',
        'raw_tool_calls' => [],
        'message' => ['role' => 'assistant', 'content' => ''],
        'model' => 'vision/selected',
        'usage' => [],
    ]);
    $primary = new AwptVisionTestProvider(false, []);
    $preprocessor = new VisionEvidencePreprocessor($sidecar);
    $prepared = $preprocessor->prepare(awpt_vision_test_messages(), $primary);

    Assert::true(
        str_contains((string) wp_json_encode($prepared['messages']), 'jubilant softball team'),
        'non-JSON vision output should remain usable as bounded evidence',
    );
    Assert::same('', $preprocessor->notice(), 'usable plain-text analysis should not be treated as a failure');
}

function test_vision_preprocessor_does_not_retry_configuration_errors(): void {
    $sidecar = new AwptVisionTestProvider(
        false,
        new WP_Error('awpt_provider_not_configured', 'OpenRouter key missing.'),
    );
    $primary = new AwptVisionTestProvider(false, []);
    $preprocessor = new VisionEvidencePreprocessor($sidecar);
    $preprocessor->prepare(awpt_vision_test_messages(), $primary);

    Assert::same(1, $sidecar->completions, 'configuration errors should degrade immediately without a futile retry');
    Assert::true(
        str_contains($preprocessor->notice(), 'Visual analysis was unavailable'),
        'configuration failures should retain the user-facing degradation notice',
    );
}

test_vision_preprocessor_describes_images_once_for_text_only_primary();
test_vision_preprocessor_degrades_with_warning_after_bounded_retry();
test_vision_preprocessor_bypasses_vision_capable_primary();
test_vision_preprocessor_accepts_plain_text_fallback();
test_vision_preprocessor_does_not_retry_configuration_errors();
