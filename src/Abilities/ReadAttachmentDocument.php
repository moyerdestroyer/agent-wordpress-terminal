<?php

/**
 * awpt/read-attachment-document ability.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Abilities;

use AWPT\Knowledge\DocumentTextExtractor;

if (!defined('ABSPATH')) {
    exit();
}

/** Reads an exact Media Library document by attachment ID with bounded pagination. */
final class ReadAttachmentDocument implements AbilityInterface {
    private const PAGE_CHARS_DEFAULT = 16_000;
    private const PAGE_CHARS_MAX = 40_000;

    public function register(): void {
        AbilityRegistrar::register([
            'name' => 'awpt/read-attachment-document',
            'label' => __('Read Attachment Document', 'agent-wordpress-terminal'),
            'description' => __(
                'Reads extracted text from an exact Media Library PDF or document attachment by ID. Use this for source-grounded document tasks instead of relying on semantic search.',
                'agent-wordpress-terminal',
            ),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'attachment_id' => [
                        'type' => 'integer',
                        'description' => __('Media Library attachment ID.', 'agent-wordpress-terminal'),
                    ],
                    'page' => [
                        'type' => 'integer',
                        'description' => __('One-based output page. Defaults to 1.', 'agent-wordpress-terminal'),
                    ],
                    'page_chars' => [
                        'type' => 'integer',
                        'description' => __(
                            'Characters per output page, from 2,000 to 40,000.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ],
                'required' => ['attachment_id'],
            ],
            'output_schema' => ['type' => 'object'],
            'permission_callback' => [$this, 'can_read'],
            'execute_callback' => [$this, 'execute'],
            'annotations' => [
                'readonly' => true,
                'destructive' => false,
            ],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function can_read(array $input): bool {
        $id = (int) ($input['attachment_id'] ?? 0);

        return $id > 0 && current_user_can('read_post', $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(array $input): array|\WP_Error {
        $id = (int) ($input['attachment_id'] ?? 0);
        $attachment = get_post($id);

        if (!$attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type) {
            return new \WP_Error(
                'awpt_attachment_document_not_found',
                __('The requested Media Library attachment was not found.', 'agent-wordpress-terminal'),
                ['status' => 404],
            );
        }

        $path = get_attached_file($id);

        if (!is_string($path) || !is_readable($path)) {
            return new \WP_Error(
                'awpt_attachment_file_unreadable',
                __('The attachment file is not readable.', 'agent-wordpress-terminal'),
                ['status' => 422],
            );
        }

        $mime = (string) get_post_mime_type($id);
        $extracted = new DocumentTextExtractor()->extract($path, $mime, basename($path));
        $text = $extracted['text'];

        if ('' === trim($text)) {
            return new \WP_Error(
                'awpt_attachment_text_unavailable',
                '' !== $extracted['warning']
                    ? $extracted['warning']
                    : __('No readable text could be extracted from the attachment.', 'agent-wordpress-terminal'),
                [
                    'status' => 422,
                    'attachment_id' => $id,
                    'mime_type' => $mime,
                    'extraction_method' => $extracted['method'],
                ],
            );
        }

        $page_chars = max(2_000, min(self::PAGE_CHARS_MAX, (int) ($input['page_chars'] ?? self::PAGE_CHARS_DEFAULT)));
        $total_chars = mb_strlen($text, 'UTF-8');
        $page_count = max(1, (int) ceil($total_chars / $page_chars));
        $page = max(1, min($page_count, (int) ($input['page'] ?? 1)));
        $offset = ($page - 1) * $page_chars;
        $content = mb_substr($text, $offset, $page_chars, 'UTF-8');

        return [
            'attachment_id' => $id,
            'title' => get_the_title($attachment),
            'filename' => basename($path),
            'mime_type' => $mime,
            'url' => wp_get_attachment_url($id),
            'extraction_method' => $extracted['method'],
            'source_page_count' => $extracted['page_count'],
            'warning' => $extracted['warning'],
            'page' => $page,
            'page_count' => $page_count,
            'page_chars' => $page_chars,
            'total_chars' => $total_chars,
            'content' => $content,
            'has_more' => $page < $page_count,
            'next_page' => $page < $page_count ? $page + 1 : null,
        ];
    }
}
