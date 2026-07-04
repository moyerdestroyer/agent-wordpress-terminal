<?php

/**
 * Recovers corrupted URLs by matching them against known-good originals.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Models occasionally drop punctuation (most often parentheses) when retyping a long
 * URL into a tool call, even within the same turn it was given in. Since AWPT already
 * has the byte-perfect original sitting in the conversation history, this compares a
 * candidate URL against a list of known-good URLs by stripping all punctuation/
 * whitespace-equivalent characters from both — which is exactly the class of
 * difference this failure mode produces — and prefers the verified original when they
 * match.
 *
 * Pure string logic with no WordPress/database dependency, so it can be unit tested
 * directly.
 */
final class UrlIntegrityResolver
{
    /**
     * Resolve a candidate URL to a verified original from `$known_urls`, if one of
     * them normalizes to the same value. Returns the candidate unchanged otherwise
     * (including when it already exactly matches a known URL).
     *
     * @param list<string> $known_urls
     */
    public function resolve(string $candidate_url, array $known_urls): string
    {
        $normalized_candidate = $this->normalize($candidate_url);

        foreach ($known_urls as $known_url) {
            if ($known_url === $candidate_url) {
                return $candidate_url;
            }

            if ('' !== $normalized_candidate && $this->normalize($known_url) === $normalized_candidate) {
                return $known_url;
            }
        }

        return $candidate_url;
    }

    /**
     * Normalize a URL for fuzzy comparison: decode percent-encoding, then strip
     * everything except letters and digits.
     */
    private function normalize(string $url): string
    {
        $decoded = rawurldecode($url);

        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $decoded));
    }
}
