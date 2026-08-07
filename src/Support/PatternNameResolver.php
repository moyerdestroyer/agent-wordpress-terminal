<?php

/**
 * Resolves paraphrased pattern names to registered active-theme slugs.
 *
 * @package AWPT
 */

declare(strict_types=1);

namespace AWPT\Support;

use AWPT\Domain\DomainPackRegistry;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Exact match first, then pack-supplied aliases, then light namespace-agnostic normalization.
 *
 * Theme thrash recovery (aliases, preferred namespaces) belongs in the active Domain Pack
 * (`patterns.namespace`, `patterns.aliases`), not in AWPT core constants.
 */
final class PatternNameResolver {
    private PatternCatalog $catalog;

    /** @var list<string>|null */
    private ?array $namespaces;

    /** @var array<string, string>|null */
    private ?array $aliases;

    /**
     * @param list<string>|null         $namespaces Explicit pack namespaces; null loads active packs.
     * @param array<string, string>|null $aliases    Explicit alias map; null loads active packs.
     */
    public function __construct(
        ?PatternCatalog $catalog = null,
        ?array $namespaces = null,
        ?array $aliases = null,
    ) {
        $this->catalog = $catalog ?? new PatternCatalog();
        $this->namespaces = $namespaces;
        $this->aliases = $aliases;
    }

    /**
     * @return array{pattern: array<string, mixed>, resolved_name: string, resolved_from: string|null}|null
     */
    public function resolve(string $requested): ?array {
        $requested = trim($requested);

        if ('' === $requested) {
            return null;
        }

        $exact = $this->catalog->find($requested);

        if (null !== $exact) {
            return [
                'pattern' => $exact,
                'resolved_name' => (string) ($exact['name'] ?? $requested),
                'resolved_from' => null,
            ];
        }

        foreach ($this->candidates($requested) as $candidate) {
            if ($candidate === $requested) {
                continue;
            }

            $found = $this->catalog->find($candidate);

            if (null === $found) {
                continue;
            }

            return [
                'pattern' => $found,
                'resolved_name' => (string) ($found['name'] ?? $candidate),
                'resolved_from' => $requested,
            ];
        }

        return null;
    }

    /** @return list<string> */
    private function candidates(string $requested): array {
        $lower = mb_strtolower(trim($requested));
        $normalized = str_replace('_', '-', $lower);
        $candidates = [$lower, $normalized];
        $aliases = $this->alias_map();

        if (isset($aliases[$lower])) {
            $candidates[] = $aliases[$lower];
        }

        if (isset($aliases[$normalized])) {
            $candidates[] = $aliases[$normalized];
        }

        // Bare slug → try active pack / theme namespaces + common thrash shapes.
        if (!str_contains($normalized, '/')) {
            foreach ($this->namespace_list() as $namespace) {
                $candidates[] = $namespace . '/' . $normalized;
                $candidates[] = $namespace . '/header-' . preg_replace('/^header-/', '', $normalized);
                $candidates[] = $namespace . '/layout-page-' . preg_replace(
                    '/^(layout-page-|page-)/',
                    '',
                    $normalized,
                );
                $candidates[] = $namespace . '/section-' . preg_replace('/^section-/', '', $normalized);

                if (in_array($normalized, ['page-header', 'header-page'], true)) {
                    $candidates[] = $namespace . '/header-page';
                    $candidates[] = $namespace . '/page-header';
                }

                if (in_array($normalized, ['documentation-page', 'docs-page', 'doc-page'], true)) {
                    $candidates[] = $namespace . '/layout-page-documentation';
                }

                if (in_array($normalized, ['team-directory', 'team-member-directory', 'staff-directory'], true)) {
                    $candidates[] = $namespace . '/section-team-member-directory';
                }
            }
        }

        // page-header ↔ header-page style swap inside any namespace.
        if (preg_match('#^([^/]+)/(page-header|header-page)$#', $normalized, $m)) {
            $candidates[] = $m[1] . '/header-page';
            $candidates[] = $m[1] . '/page-header';
        }

        if (preg_match('#^([^/]+)/documentation-page$#', $normalized, $m)) {
            $candidates[] = $m[1] . '/layout-page-documentation';
        }

        if (preg_match('#^([^/]+)/(team-directory|team-member-directory|staff-directory)$#', $normalized, $m)) {
            $candidates[] = $m[1] . '/section-team-member-directory';
        }

        return array_values(array_unique(array_filter($candidates, static fn(string $v): bool => '' !== $v)));
    }

    /** @return array<string, string> */
    private function alias_map(): array {
        if (null !== $this->aliases) {
            return $this->aliases;
        }

        return DomainPackRegistry::instance()->pattern_aliases();
    }

    /** @return list<string> */
    private function namespace_list(): array {
        if (null !== $this->namespaces) {
            return $this->namespaces;
        }

        return DomainPackRegistry::instance()->pattern_namespaces();
    }
}
