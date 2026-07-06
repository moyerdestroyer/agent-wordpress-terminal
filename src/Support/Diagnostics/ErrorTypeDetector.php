<?php

/**
 * Classifies error text by runtime.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support\Diagnostics;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Detects PHP and JS error categories from raw text.
 */
final class ErrorTypeDetector {
    public function detect(string $text): ?string {
        if (preg_match('/\bPHP Fatal error\b/i', $text)) {
            return 'php_fatal';
        }

        if (preg_match('/\bPHP (Warning|Notice)\b/i', $text)) {
            return 'php_warning';
        }

        if (preg_match('/\b(Uncaught|Exception|Error)\b/i', $text)) {
            return 'php_exception';
        }

        if (preg_match('/\b(ReferenceError|TypeError|SyntaxError)\b/', $text)) {
            return 'js_error';
        }

        if (preg_match('/\bunhandledrejection\b/i', $text)) {
            return 'js_unhandled_rejection';
        }

        return null;
    }
}
