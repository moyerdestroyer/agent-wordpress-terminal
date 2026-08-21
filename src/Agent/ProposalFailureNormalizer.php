<?php

/**
 * Maps proposal WP_Error codes into turn-scoped recovery constraints.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Agent;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Feedback-only normalizer. Validators stay authoritative; this shapes guidance.
 */
final class ProposalFailureNormalizer {
    /**
     * @param array<string, mixed> $error_data
     * @return list<array{
     *     id: string,
     *     error_code: string,
     *     summary: string,
     *     facts?: array<string, mixed>,
     *     hints?: list<string>
     * }>
     */
    public static function normalize(string $error_code, array $error_data = [], string $error_message = ''): array {
        $error_code = sanitize_key($error_code);
        $encoded_error_data = wp_json_encode($error_data);
        $combined = trim($error_message . ' ' . (false === $encoded_error_data ? '' : $encoded_error_data));

        return match (true) {
            'awpt_presentation_content_loss' === $error_code => [self::preserve_content($error_code, $error_data)],
            'awpt_unresolved_local_media_url' === $error_code => [self::unresolved_local_media(
                $error_code,
                $error_data,
                $error_message,
            )],
            'awpt_domain_validation_failed' === $error_code => [self::domain_validation(
                $error_code,
                $error_data,
                $error_message,
            )],
            'awpt_pattern_replace_requires_content' === $error_code,
            'awpt_invalid_block_position' === $error_code,
                => [self::pattern_insert_position($error_code, $error_data, $error_message)],
            'http_request_failed' === $error_code,
            'awpt_proposal_output_truncated' === $error_code,
                => [self::provider_timeout_or_truncation($error_code, $error_data, $error_message)],
            'awpt_required_page_h1_missing' === $error_code => [self::requires_page_h1($error_code, $error_data)],
            'awpt_heading_level_skipped' === $error_code,
            'awpt_duplicate_page_heading' === $error_code,
                => [self::heading_outline($error_code, $error_data, $error_message)],
            'awpt_block_fingerprint_mismatch' === $error_code => [self::exact_fingerprints($error_code, $error_data)],
            'awpt_pattern_text_block_not_editable' === $error_code,
            'awpt_block_inner_html_not_editable' === $error_code,
                => [self::unsupported_block_edit($error_code, $error_data, $error_message)],
            'awpt_pattern_text_updates_required' === $error_code => [self::pattern_text_updates_required(
                $error_code,
                $error_data,
                $error_message,
            )],
            'awpt_pattern_text_path_invalid' === $error_code => [self::pattern_text_path_invalid(
                $error_code,
                $error_data,
                $error_message,
            )],
            'awpt_empty_block_attrs' === $error_code,
            'awpt_unknown_block_attribute' === $error_code,
                => [self::invalid_block_attributes($error_code, $error_data, $error_message)],
            'awpt_multiple_proposals' === $error_code => [self::multiple_proposals(
                $error_code,
                $error_data,
                $error_message,
            )],
            'ability_invalid_input' === $error_code && self::mentions_fingerprint($combined)
                => [self::exact_fingerprints($error_code, $error_data, $error_message)],
            'awpt_pattern_fallback_reason_required' === $error_code => [self::pattern_fallback_reason(
                $error_code,
                $error_data,
            )],
            'awpt_pattern_not_read' === $error_code => [self::pattern_read_required($error_code, $error_data)],
            'awpt_pattern_not_found' === $error_code => [self::pattern_name_exact(
                $error_code,
                $error_data,
                $error_message,
            )],
            default => '' !== $error_code
                ? [[
                    'id' => 'generic_' . $error_code,
                    'error_code' => $error_code,
                    'summary' => '' !== trim($error_message)
                        ? trim($error_message)
                        : sprintf(
                            /* translators: %s: error code */
                            __('Proposal validation failed (%s).', 'agent-wordpress-terminal'),
                            $error_code,
                        ),
                    'facts' => self::selected_facts($error_data, [
                        'recommended_next_tools',
                        'recovery',
                        'status',
                    ]),
                    'hints' => [
                        __(
                            'Read the complete structured error_data and address every listed issue. Do not re-call awpt/recommend-patterns or awpt/list-patterns unless pattern selection itself failed.',
                            'agent-wordpress-terminal',
                        ),
                    ],
                ]]
                : [],
        };
    }

    private static function mentions_fingerprint(string $text): bool {
        return (bool) preg_match('/expected_fingerprint|fingerprint/i', $text);
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function unsupported_block_edit(
        string $error_code,
        array $error_data,
        string $error_message,
    ): array {
        return [
            'id' => 'supported_block_operation',
            'error_code' => $error_code,
            'summary' => '' !== trim($error_message)
                ? trim($error_message)
                : __('The selected block operation cannot edit this block shape.', 'agent-wordpress-terminal'),
            'facts' => self::selected_facts($error_data, [
                'block_path',
                'block_name',
                'inner_count',
                'recommended_next_tools',
            ]),
            'hints' => [
                __(
                    'Call awpt/get-block for the exact target. If inner_html_editable is true, use one replace_inner_html change with the returned complete inner_html and fingerprint.',
                    'agent-wordpress-terminal',
                ),
                __(
                    'For nested blocks, edit supported child paths. Use propose-content-update only when the verified block cannot be expressed surgically.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function invalid_block_attributes(
        string $error_code,
        array $error_data,
        string $error_message,
    ): array {
        return [
            'id' => 'valid_block_attributes',
            'error_code' => $error_code,
            'summary' => '' !== trim($error_message)
                ? trim($error_message)
                : __('The block attribute mutation is not valid for its target.', 'agent-wordpress-terminal'),
            'facts' => self::selected_facts($error_data, [
                'block_path',
                'block_name',
                'unknown_attributes',
                'allowed_attributes',
                'recommended_next_tools',
            ]),
            'hints' => [
                self::attribute_recovery_hint($error_data, $error_message),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     */
    private static function attribute_recovery_hint(array $error_data, string $error_message): string {
        $block = (string) ($error_data['block_name'] ?? '');
        $unknown = is_array($error_data['unknown_attributes'] ?? null)
            ? array_map('strval', $error_data['unknown_attributes'])
            : [];

        if (in_array('level', $unknown, true) && str_contains($block, 'paragraph')) {
            return __(
                'Attribute level belongs on core/heading, not core/paragraph. Keep body copy as a paragraph, or remove the paragraph and insert a heading block — do not set level on paragraphs.',
                'agent-wordpress-terminal',
            );
        }

        if (str_contains($error_message, 'level') && str_contains($error_message, 'paragraph')) {
            return __(
                'Attribute level belongs on core/heading, not core/paragraph. Keep body copy as a paragraph, or remove the paragraph and insert a heading block — do not set level on paragraphs.',
                'agent-wordpress-terminal',
            );
        }

        return __(
            'Do not invent empty or unrelated attrs. For an HTML attribute inside saved markup, call awpt/get-block and use replace_inner_html when eligible. Prefer html/text fields over attrs.content for rich text.',
            'agent-wordpress-terminal',
        );
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function multiple_proposals(string $error_code, array $error_data, string $error_message): array {
        return [
            'id' => 'one_atomic_proposal',
            'error_code' => $error_code,
            'summary' => '' !== trim($error_message)
                ? trim($error_message)
                : __('Distinct staging proposals must be consolidated.', 'agent-wordpress-terminal'),
            'facts' => self::selected_facts($error_data, ['requested_tools', 'recommended_next_tools']),
            'hints' => [
                __(
                    'Return exactly one complete proposal call. Exact duplicate calls are collapsed automatically, but different proposal arguments remain unsafe.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function preserve_content(string $error_code, array $error_data): array {
        return [
            'id' => 'preserve_content',
            'error_code' => $error_code,
            'summary' => __(
                'Preserve existing page copy, working links, numbers, media, and legal references.',
                'agent-wordpress-terminal',
            ),
            'facts' => self::selected_facts($error_data, [
                'token_recall',
                'minimum_token_recall',
                'text_length_ratio',
                'minimum_text_length_ratio',
                'missing_links',
                'missing_numeric_tokens',
                'missing_short_fragments',
                'missing_excerpt',
            ]),
            'hints' => [
                __(
                    'Map source facts into pattern slots first. If slots cannot hold all substantive copy, extend with additional sections rather than dropping contact, legal, or numeric facts when strict preservation was requested.',
                    'agent-wordpress-terminal',
                ),
                __(
                    'Retry a pattern-backed adaptation or a multi-section compose. Prefer theme patterns; bespoke structure is fine when no pattern fits.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function unresolved_local_media(
        string $error_code,
        array $error_data,
        string $error_message,
    ): array {
        return [
            'id' => 'unresolved_local_media',
            'error_code' => $error_code,
            'summary' => '' !== trim($error_message)
                ? trim($error_message)
                : __('A block references an unresolved same-site Media Library URL.', 'agent-wordpress-terminal'),
            'facts' => self::selected_facts($error_data, [
                'block_path',
                'block_name',
                'url',
                'recovery',
                'recommended_next_tools',
            ]),
            'hints' => [
                __(
                    'Do not invent Media Library IDs or re-paste the failing /wp-content/uploads/ URL.',
                    'agent-wordpress-terminal',
                ),
                __(
                    'Omit optional image/cover media when no verified library attachment exists and adapt without inventing photos or attachment IDs.',
                    'agent-wordpress-terminal',
                ),
                __(
                    'Do not re-call awpt/recommend-patterns or awpt/list-patterns for this media integrity failure — fix the proposal media and restage.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function domain_validation(string $error_code, array $error_data, string $error_message): array {
        $blocking = is_array($error_data['blocking_findings'] ?? null)
            ? $error_data['blocking_findings']
            : (is_array($error_data['validation_findings'] ?? null) ? $error_data['validation_findings'] : []);
        $codes = [];

        foreach ($blocking as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            if ('error' !== (string) ($finding['severity'] ?? 'error')) {
                continue;
            }

            $code = sanitize_key((string) ($finding['code'] ?? ''));

            if ('' !== $code) {
                $codes[] = $code;
            }
        }

        return [
            'id' => 'domain_validation',
            'error_code' => $error_code,
            'summary' => '' !== trim($error_message)
                ? trim($error_message)
                : __('Composition rules rejected newly introduced issues.', 'agent-wordpress-terminal'),
            'facts' => array_merge(
                self::selected_facts($error_data, [
                    'primary_code',
                    'blocking_findings',
                    'recovery',
                    'recommended_next_tools',
                ]),
                [] !== $codes ? ['blocking_codes' => array_values(array_unique($codes))] : [],
            ),
            'hints' => [
                __(
                    'Address only blocking_findings (new errors). Inherited import Custom HTML and pattern-default # links are often warnings or grandfathered on edits.',
                    'agent-wordpress-terminal',
                ),
                __(
                    'Prefer a smaller retry: awpt/propose-pattern-insert for the header or one section, then a limited content update — avoid resending an entire 80+ block rewrite.',
                    'agent-wordpress-terminal',
                ),
                __(
                    'Do not re-call awpt/recommend-patterns unless the page archetype changed.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function pattern_insert_position(
        string $error_code,
        array $error_data,
        string $error_message,
    ): array {
        return [
            'id' => 'pattern_insert_position',
            'error_code' => $error_code,
            'summary' => '' !== trim($error_message)
                ? trim($error_message)
                : __('Pattern insert position is invalid for a document rewrite.', 'agent-wordpress-terminal'),
            'facts' => self::selected_facts($error_data, [
                'allowed_positions',
                'received_position',
                'recovery',
                'recommended_next_tools',
            ]),
            'hints' => [
                __(
                    'Insert positions are only before, after, or append. position replace is not supported on propose-pattern-insert.',
                    'agent-wordpress-terminal',
                ),
                __(
                    'For a section swap, call awpt/propose-pattern-replace with path and intent — the server prepares.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function provider_timeout_or_truncation(
        string $error_code,
        array $error_data,
        string $error_message,
    ): array {
        return [
            'id' => 'provider_timeout',
            'error_code' => $error_code,
            'summary' => '' !== trim($error_message)
                ? trim($error_message)
                : __('The provider timed out or truncated a large proposal.', 'agent-wordpress-terminal'),
            'facts' => self::selected_facts($error_data, ['recovery', 'recommended_next_tools', 'status']),
            'hints' => [
                __(
                    'Shrink the next propose: insert one verified pattern section or header, or update a limited block range — do not resend a full multi-section document rewrite in one tool call.',
                    'agent-wordpress-terminal',
                ),
                __(
                    'Reuse recommend/read results already in this turn; do not restart discovery.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function requires_page_h1(string $error_code, array $error_data): array {
        return [
            'id' => 'requires_page_h1',
            'error_code' => $error_code,
            'summary' => __(
                'Rendered evidence requires exactly one content H1 in the proposal.',
                'agent-wordpress-terminal',
            ),
            'facts' => self::selected_facts($error_data, ['recommended_next_tools']),
            'hints' => [
                __(
                    'Promote an existing heading to level 1, or insert one core/heading with level 1 using the verified page title.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function heading_outline(string $error_code, array $error_data, string $error_message): array {
        return [
            'id' => 'heading_outline',
            'error_code' => $error_code,
            'summary' => '' !== trim($error_message)
                ? trim($error_message)
                : __('The proposal heading outline is invalid.', 'agent-wordpress-terminal'),
            'facts' => self::selected_facts($error_data, ['heading', 'recommended_next_tools']),
            'hints' => [
                __(
                    'After an H1, top-level sections must be H2 unless a real H2 parent precedes H3 children.',
                    'agent-wordpress-terminal',
                ),
                __(
                    'If you insert a page H1, include heading-level fixes for remaining FAQ/section headings in the same batch so the outline does not skip.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function exact_fingerprints(
        string $error_code,
        array $error_data,
        string $error_message = '',
    ): array {
        $summary = 'awpt_block_fingerprint_mismatch' === $error_code
            ? __(
                'Batch targets must use the exact current 64-character block fingerprints.',
                'agent-wordpress-terminal',
            )
            : (
                '' !== trim($error_message)
                    ? trim($error_message)
                    : __(
                        'Batch changes require a complete expected_fingerprint for every targeted block.',
                        'agent-wordpress-terminal',
                    )
            );

        return [
            'id' => 'exact_fingerprints',
            'error_code' => $error_code,
            'summary' => $summary,
            'facts' => self::selected_facts($error_data, [
                'change_index',
                'block_path',
                'received_fingerprint',
                'current_fingerprint',
                'remediation',
            ]),
            'hints' => [
                __(
                    'Copy current_fingerprint exactly from the latest read-block-tree evidence; fingerprints are 64 characters and must not be shortened.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function pattern_text_updates_required(
        string $error_code,
        array $error_data,
        string $error_message,
    ): array {
        return [
            'id' => 'pattern_text_updates_required',
            'error_code' => $error_code,
            'summary' => '' !== trim($error_message)
                ? trim($error_message)
                : __(
                    'Map existing page copy into pattern editable_slots before staging this replace.',
                    'agent-wordpress-terminal',
                ),
            'facts' => self::selected_facts($error_data, [
                'preparation_id',
                'editable_slots',
                'media_slots',
                'carry_forward',
                'recovery',
                'retry_example',
                'status',
            ]),
            'hints' => [
                __(
                    'Reuse preparation_id. Send pattern_text_updates with block_path values from editable_slots and content drawn from carry_forward / the live page. Do not call prepare again.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function pattern_text_path_invalid(
        string $error_code,
        array $error_data,
        string $error_message,
    ): array {
        return [
            'id' => 'pattern_text_path_invalid',
            'error_code' => $error_code,
            'summary' => '' !== trim($error_message)
                ? trim($error_message)
                : __('pattern_text_updates used invalid block_path values.', 'agent-wordpress-terminal'),
            'facts' => self::selected_facts($error_data, [
                'preparation_id',
                'editable_slots',
                'invalid_paths',
                'invalid_updates',
                'recovery',
                'retry_example',
                'status',
            ]),
            'hints' => [
                __(
                    'Copy block_path exactly from editable_slots (dotted numbers like 1.0.1.0.0). Do not invent names such as intro_paragraph or first_h2. Optional: use slot_id when the slot contract exposes one.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function pattern_fallback_reason(string $error_code, array $error_data): array {
        return [
            'id' => 'pattern_fallback_reason',
            'error_code' => $error_code,
            'summary' => __(
                'Theme or site patterns are preferred; bespoke composition is allowed when no pattern fits.',
                'agent-wordpress-terminal',
            ),
            'facts' => self::selected_facts($error_data, ['preferred_patterns', 'recommended_next_tools', 'recovery']),
            'hints' => [
                __(
                    'List/read a preferred pattern, or stage a clear bespoke layout with registered blocks and tokens.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function pattern_read_required(string $error_code, array $error_data): array {
        return [
            'id' => 'pattern_read_required',
            'error_code' => $error_code,
            'summary' => __(
                'Read the selected pattern before using it as the basis for a layout rewrite.',
                'agent-wordpress-terminal',
            ),
            'facts' => self::selected_facts($error_data, ['recommended_next_tools']),
            'hints' => [
                __(
                    'Call awpt/read-pattern with the exact name before retrying the proposal.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @return array{id: string, error_code: string, summary: string, facts: array<string, mixed>, hints: list<string>}
     */
    private static function pattern_name_exact(string $error_code, array $error_data, string $error_message): array {
        return [
            'id' => 'pattern_name_exact',
            'error_code' => $error_code,
            'summary' => '' !== trim($error_message)
                ? trim($error_message)
                : __('The requested pattern name is unavailable.', 'agent-wordpress-terminal'),
            'facts' => self::selected_facts($error_data, ['recommended_next_tools']),
            'hints' => [
                __(
                    'Reuse an exact pattern_name from a successful awpt/read-pattern result, or omit pattern_name and provide pattern_fallback_reason.',
                    'agent-wordpress-terminal',
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $error_data
     * @param list<string>         $keys
     * @return array<string, mixed>
     */
    private static function selected_facts(array $error_data, array $keys): array {
        $facts = [];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $error_data)) {
                continue;
            }

            $facts[$key] = $error_data[$key];
        }

        return $facts;
    }
}
