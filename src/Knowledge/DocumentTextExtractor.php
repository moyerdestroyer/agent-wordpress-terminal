<?php

/**
 * Extracts bounded text from Media Library document files.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

final class DocumentTextExtractor {
    private const MAX_BYTES = 5_000_000;
    private const MAX_CHARS = 500_000;

    /**
     * @return array{text: string, method: string, page_count: int, warning: string}
     */
    public function extract(string $path, string $mime, string $filename = ''): array {
        $extension = strtolower(pathinfo('' !== $filename ? $filename : $path, PATHINFO_EXTENSION));

        if ('application/pdf' === $mime || 'pdf' === $extension) {
            return new PdfTextExtractor()->extract_with_metadata($path);
        }

        if (
            str_starts_with($mime, 'text/')
            || in_array($extension, ['txt', 'md', 'markdown', 'csv', 'json', 'xml', 'html', 'htm'], true)
        ) {
            return $this->plain_text($path);
        }

        if (
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime
            || 'docx' === $extension
        ) {
            return $this->docx($path);
        }

        return $this->result(
            '',
            'unsupported',
            __('This document type does not have a local text extractor.', 'agent-wordpress-terminal'),
        );
    }

    /**
     * @return array{text: string, method: string, page_count: int, warning: string}
     */
    private function plain_text(string $path): array {
        $bytes = is_readable($path) ? filesize($path) : false;

        if (false === $bytes || $bytes <= 0 || $bytes > self::MAX_BYTES) {
            return $this->result(
                '',
                'unavailable',
                __('The document is empty, unreadable, or too large.', 'agent-wordpress-terminal'),
            );
        }

        $content = file_get_contents($path);

        return is_string($content)
            ? $this->result($content, 'plain_text')
            : $this->result('', 'unavailable', __('The document could not be read.', 'agent-wordpress-terminal'));
    }

    /**
     * @return array{text: string, method: string, page_count: int, warning: string}
     */
    private function docx(string $path): array {
        if (!class_exists('ZipArchive')) {
            return $this->result(
                '',
                'unavailable',
                __('The PHP Zip extension is required to read DOCX files.', 'agent-wordpress-terminal'),
            );
        }

        $zip = new \ZipArchive();

        if (true !== $zip->open($path)) {
            return $this->result(
                '',
                'unavailable',
                __('The DOCX archive could not be opened.', 'agent-wordpress-terminal'),
            );
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!is_string($xml)) {
            return $this->result(
                '',
                'unavailable',
                __('The DOCX document body was not found.', 'agent-wordpress-terminal'),
            );
        }

        $xml = str_replace(['</w:p>', '</w:tr>', '<w:tab/>'], ["\n", "\n", "\t"], $xml);
        $text = html_entity_decode(wp_strip_all_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return $this->result($text, 'docx');
    }

    /**
     * @return array{text: string, method: string, page_count: int, warning: string}
     */
    private function result(string $text, string $method, string $warning = ''): array {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        return [
            'text' => mb_substr($text, 0, self::MAX_CHARS, 'UTF-8'),
            'method' => $method,
            'page_count' => '' === $text ? 0 : max(1, substr_count($text, "\f") + 1),
            'warning' => $warning,
        ];
    }
}
