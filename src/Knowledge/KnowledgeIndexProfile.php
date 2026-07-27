<?php

/**
 * Knowledge corpus profile fingerprint.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Knowledge;

if (!defined('ABSPATH')) {
    exit();
}

final class KnowledgeIndexProfile {
    public const FORMAT_VERSION = '2';
    public const SOURCE_POLICY_VERSION = '2';
    public const NORMALIZATION_VERSION = '2';

    public static function value(): string {
        return implode(':', [
            'format-' . self::FORMAT_VERSION,
            'sources-' . self::SOURCE_POLICY_VERSION,
            'normalize-' . self::NORMALIZATION_VERSION,
            'chunks-' . KnowledgeTextChunker::VERSION,
        ]);
    }
}
