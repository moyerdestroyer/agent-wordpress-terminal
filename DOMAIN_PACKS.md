# AWPT Domain Packs

Domain Packs let an active theme provide the design-system and editorial knowledge that a general-purpose agent cannot safely infer from pattern names alone. Packs are active by default, visible under **Knowledge → Domain Packs**, and can be disabled by an administrator. A pack can guide decisions, but it cannot bypass AWPT permissions, evidence gathering, staged actions, validation, or human approval.

## Theme contract

Add `awpt-domain.json` to the theme root. Domain Pack v1 remains supported, but
new integrations should use the local v2 schema at
[`schemas/awpt-domain-v2.schema.json`](schemas/awpt-domain-v2.schema.json).

```json
{
  "$schema": "https://awpt.dev/schemas/awpt-domain-v2.json",
  "schema_version": 2,
  "id": "my-theme",
  "label": "My Theme",
  "version": "1.0.0",
  "guidance": [
    {
      "id": "composition",
      "label": "Page composition",
      "path": "docs/Agent/Page Composition.md",
      "applies_to": ["compose", "edit"],
      "triggers": [],
      "audience": "editor",
      "priority": 90,
      "hard": true
    }
  ],
  "patterns": {
    "namespace": "my-theme",
    "catalog": "inc/blocks/awpt-patterns.json",
    "aliases": {
      "my-theme/page-header": "my-theme/header-page"
    }
  },
  "rules": {
    "path": "inc/blocks/awpt-rules.json"
  },
  "validators": ["composition"],
  "recommenders": [],
  "materializers": [],
  "proposal_operations": []
}
```

Manifest file references must remain inside the theme. AWPT bounds manifest and guidance sizes, sanitizes declarative values, and ignores invalid optional records.

## Guidance and `AGENTS.md`

Use scoped Markdown guidance for runtime product knowledge: page composition, accessibility, content policy, dynamic-data rules, and other instructions the site editor needs. Mark only true invariants as `hard`; AWPT automatically injects matching hard modules. Other matching modules appear as compact references in the work context and the agent reads them on demand with `awpt/read-domain-guidance`. Optional `triggers` narrow a module to turns containing one of those phrases, while `audience: developer` keeps contributor material out of editor work.

Keep `AGENTS.md` for contributors and coding agents: repository layout, build commands, implementation conventions, and pattern-authoring checklists. AWPT indexes `AGENTS.md` as developer-audience knowledge and de-emphasizes it for ordinary editorial turns. It is not the runtime Domain Pack contract.

When WordPress exposes `wp_knowledge`, published Knowledge entries can extend or replace a pack guidance item using these REST-visible post-meta fields:

- `_awpt_pack_id`
- `_awpt_guidance_id`
- `_awpt_override_mode` (`extend` or `replace`)

AWPT also supports the proposal-era `wp_guideline` post type through feature detection. Without either post type, the theme files remain the source of truth.

## Pattern namespaces and aliases

`patterns.namespace` is the registered block-pattern prefix the pack owns (usually the theme
stylesheet slug). AWPT uses it when expanding bare slugs the agent invents (`hero` →
`my-theme/hero` candidates).

Optional `patterns.aliases` maps thrash names the agent has used to the **exact** registered
slug. Prefer shipping aliases in the pack so theme authors can fix resolution without an AWPT
release. AWPT still applies a few **namespace-agnostic** heuristics (`page-header` ↔
`header-page` inside any namespace); product-specific synonyms belong in the pack.

## Pattern catalog

The catalog is a JSON object with a `patterns` map keyed by the exact registered pattern slug. Catalog v1 remains supported; new packs should use the local v2 schema at
[`schemas/awpt-patterns-v2.schema.json`](schemas/awpt-patterns-v2.schema.json). Useful fields include:

- `role`, `summary`, `intents`, `use_when`, `avoid_when`, and `search_terms`
- `post_types`, `companions`, and `composed`
- `dynamic_content`, `required_blocks`, and `max_per_document`
- `content_rules`, `validation`, and `docs`
- `placement`, `relationships`, named editable `slots`, `design`, and an optional `preview`

Catalog v2 treats an entry as a composition contract, not merely a search card. Keep selection judgment (`use_when`, `avoid_when`, alternatives), editing affordances (`slots`), and visual intent (`design`) curated. Generate deterministic facts such as composed pattern references, block dependencies, content hashes, and screenshot paths from the pattern source in CI whenever possible.

AWPT merges this structured metadata with ordinary pattern headers. `awpt/recommend-patterns` ranks candidates by user intent, active-theme ownership, compatibility, and the curated semantics; `awpt/read-pattern` remains the source for exact markup.

When AWPT inserts or adapts a pattern, it stamps `metadata.patternName` onto materialized root blocks and stores a composition manifest with the pack version and source hash. Validators therefore retain the identity that is normally lost when a pattern becomes editable blocks.

## Declarative rules

Most composition constraints belong in the JSON rules file, validated by
[`schemas/awpt-rules-v1.schema.json`](schemas/awpt-rules-v1.schema.json):

```json
{
  "$schema": "https://awpt.dev/schemas/awpt-rules-v1.json",
  "schema_version": 1,
  "rules": [
    {
      "id": "single-page-heading",
      "type": "headings.single_h1",
      "severity": "error",
      "scope": ["compose", "edit", "template"],
      "config": {},
      "message": "Use no more than one H1 in a composition.",
      "suggestion": "Demote additional headings to H2 or below.",
      "docs": "docs/Agent/Page Composition.md"
    }
  ]
}
```

Supported rule types are `blocks.disallow`, `blocks.require`, `blocks.count`,
`headings.single_h1`, `headings.no_skips`, `anchors.unique`,
`attributes.require`, `patterns.max`, `patterns.require_blocks`, and
`tokens.require_presets`. Pattern catalog fields `max_per_document` and
`required_blocks` are also enforced automatically after materialization.

Use PHP only when the constraint cannot be expressed in this bounded
vocabulary. Declarative rules are inspectable in Domain Pack health, have
stable hashes, and run consistently at validation, staging, and apply.

## PHP extensions

Register trusted callbacks from theme PHP on `awpt_domain_packs_init`:

```php
add_action( 'awpt_domain_packs_init', function () {
    awpt_register_domain_validator( 'my-theme', 'composition', array(
        'callback' => 'my_theme_validate_composition',
    ) );
} );
```

Available registration functions are:

- `awpt_register_domain_validator()`
- `awpt_register_pattern_recommender()`
- `awpt_register_pattern_materializer()`
- `awpt_register_proposal_operation()`

Empty `recommenders`, `materializers`, and `proposal_operations` arrays are normal.
Most themes only need declarative rules plus optional validators (as CivicPress does).
Declare an extension id in the manifest only when you also register a matching PHP callback.

Validators are read-only callbacks returning typed findings with `severity`,
`code`, `message`, and optional `rule_id`, `block_path`, `source`,
`suggestion`, `expected`, `actual`, and `docs`. Errors block staging or apply;
warnings and information stay visible on the action card.

## What AWPT supplies

Theme authors do not need to ship an agent, MCP server, prompt bundle, or
workflow implementation. AWPT owns:

- task classification and evidence gates for composition, editing, templates,
  navigation, global styles, and diagnosis;
- progressive guidance retrieval and optional `wp_knowledge` overrides;
- offline deterministic pattern ranking with an optional cached semantic
  rerank when embeddings are available;
- baseline Gutenberg validation, declarative rules, and exceptional PHP
  validators;
- transparent, invariant-checked safe repairs on proposed copies;
- structured `agent_feedback`, one bounded correction attempt, staged review,
  apply-time revalidation, and ruleset drift reporting.

The theme supplies its design vocabulary: scoped guidance, enriched pattern
metadata, declarative constraints, and only the PHP callbacks its domain
genuinely requires.

## Author checklist

1. Add a v2 `awpt-domain.json`.
2. Cover the work types the theme supports with small editor-audience guidance
   modules; mark only non-negotiable constraints `hard`.
3. Add every important theme pattern to the catalog with exact slug, role,
   intent, compatibility, use/avoid guidance, and structural constraints.
4. Put enforceable constraints in `awpt-rules.json`; reserve PHP for semantic
   checks that need custom code.
5. Activate the theme, open **Knowledge → Theme expertise**, and resolve Domain
   Pack health warnings about stale metadata, missing scopes, unsupported
   rules, or missing callbacks.
6. Exercise `awpt/get-work-context`, `awpt/recommend-patterns`,
   `awpt/read-domain-guidance`, and `awpt/validate-composition` before testing a
   staged proposal and apply-time revalidation.

## Custom proposal operations

A custom operation must register:

- `ability_name` and `input_schema`
- `permission_callback`
- `sanitize_callback`
- `stage_callback`
- `apply_callback`

Reversible operations must also provide `snapshot_callback`, `fingerprint_callback`, and `rollback_callback`; the fingerprint protects both apply and rollback from stale writes. Operations may provide `preview_callback`, `validate_callback`, and `cleanup_callback`. An operation without rollback must declare `irreversible: true`, which AWPT shows prominently and confirms again before apply.

The lifecycle is:

1. Permission check, sanitize, stage, snapshot, and validate.
2. Human reviews the affected resource, findings, diff, and optional preview.
3. Apply repeats permission, stale-snapshot, and validation checks.
4. Reject runs optional preview cleanup.
5. Rollback checks permission and the post-apply fingerprint before restoring the snapshot.

Callbacks are trusted theme/plugin PHP, but they do not get automatic authority. Their permission callback must use the minimum relevant WordPress capability.

## CivicPress reference kit

The CivicPress theme is the reference implementation. Its heading hierarchy
and structured pattern limits are declarative; its call-to-action language
check remains a small PHP extension because that judgment is semantic rather
than structural.
