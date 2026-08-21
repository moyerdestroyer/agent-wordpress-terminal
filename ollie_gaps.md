# Ollie system reassessment for AWPT

> **Implementation status (August 2026):** G1–G3 are **closed**. The model-facing **content catalog is small on purpose** (see the tool comparison below): surgical work is `propose-block-batch-update` (`set` / `remove` / `insert`); section swaps and adds are `propose-pattern-replace` / `propose-pattern-insert` and the server prepares. Do not re-expose retired aliases (`propose-block-attrs-update`, `propose-block-insert`, `propose-block-remove`, `list-blocks`) to match Ollie's action-routers. `awpt-domain.json` remains the theme's only manifest and may reference `design.catalog`. AWPT compiles a theme-neutral snapshot behind `awpt/read-design-system`; a task-scoped slice is injected; the ability expands a named section. Theme-derived preset checks reject newly introduced hardcoded tokens when the active theme publishes matching presets; unchanged legacy markup is grandfathered. Declined: a second manifest, a compiled rubric/example schema, a full block/template inventory, Ollie aesthetics as universal rules, a forced evaluate tool call, and extra model-facing abilities for jobs the catalog already covers. This document is architectural background and the backlog for G4–G8.

## Purpose and source boundary

The `ol/` subtree is an offline reference implementation. It contains:

- Ollie theme 1.6.1 and its pattern/token system.
- Ollie Pro 2.6.4, including seven consolidated WordPress Abilities.
- A modular Ollie agent skill: a short routing spine plus token, markup, ability, design, archetype, preset, and rubric references.

AWPT must not import or require this code at runtime. The useful comparison is architectural: how does an agent gain enough access to a design system to make theme-native decisions, and how should AWPT safely execute those decisions?

This assessment is based on the checked-in source, not only the prose documentation. The snapshot contains ability classes marked `@since 3.0.0` and `3.1.0` while the plugin header remains 2.6.4, and no automated test suite is present in `ol/`. Treat it as design evidence, not a verified release contract.

## Executive conclusion

Ollie remains highly relevant, but it should not be AWPT's target architecture.

Ollie's strongest contribution is its **design-system access layer**:

- a compact, progressively disclosed map of the theme's identity, tokens, patterns, components, and constraints;
- explicit routes from user intent to existing design-system assets;
- concrete markup examples and composition archetypes;
- a vocabulary for theme-native design judgment: hierarchy, rhythm, contrast, density, width, and accessibility;
- a rubric that lets the agent evaluate custom work against the same system;
- tools that expose and persist the design choices described by the instructions.

AWPT's strongest contribution is its **transaction and runtime system**:

- narrow typed abilities selected per task;
- durable evaluate → act workflows;
- staged proposals and explicit approval boundaries;
- block fingerprints, source hashes, and apply-time drift checks;
- one coherent proposal per model response;
- preview, apply, reject, rollback, and Review Undo;
- preservation/domain validation;
- structured recovery, incident logs, and scorecards.

AWPT and Dufresne specifics are necessary delivery mechanics, not the design system itself. AWPT should discover and compile design-system evidence, reason against it, and execute through its safer proposal architecture. Dufresne should remain a review surface that supplies page context, reviewer intent, and constrained apply/Undo behavior. Replacing AWPT's transaction model with Ollie's immediate-write model would be a regression; treating AWPT's mechanics as a substitute for design-system access would also miss the point.

## The three-layer model

### 1. Design authority

This layer answers: **What does good, native work look like on this site?**

It includes:

- identity and active style direction;
- resolved tokens and allowed values;
- registered blocks, variations, and components;
- patterns and page/section archetypes;
- semantic, accessibility, and responsive rules;
- representative examples and anti-patterns;
- a quality rubric;
- provenance and confidence for every rule.

Ollie supplies this as a theme-specific skill plus theme/plugin data. AWPT needs a generic contract that any theme or domain pack can fulfill.

### 2. Execution substrate

This layer answers: **How can the agent inspect and change WordPress safely?**

This is AWPT: a small model-facing job catalog, server-side pattern preparation, proposal staging, fingerprints, preservation checks, previews, apply-time conflict detection, recovery, rollback, and observability. Receipts and slot maps are executor details, not extra tools.

### 3. Product surface

This layer answers: **Who initiated the work, what context applies, and how is it reviewed?**

Dufresne is one such surface. It provides the review-queue item, optional reviewer direction, automatic application of one constrained action, visible results, and Undo. Terminal and CLI are other surfaces with different approval semantics. None should own theme design knowledge.

## System maps

### Ollie

#### Design-system access layer

`ol/ollie-skill/SKILL.md` is a 91-line routing spine. It loads detail only when needed:

- `reference/TOKENS.md` for theme tokens;
- `reference/ABILITIES.md` for tool contracts;
- `reference/MARKUP.md` for Gutenberg serialization rules;
- `design/DESIGN.md` plus archetypes, presets, and rubric only for deliberate custom work.

Its default route is simple:

1. Prefer a full-page pattern for creation.
2. Prefer pattern search/apply/replace for existing sections.
3. Use surgical block editing for text or attributes.
4. Enter the from-scratch design layer only after a pattern miss or an explicit bespoke request.
5. Read global styles before changing the site-wide design.

This is a strong progressive-disclosure model. More importantly, it makes the theme legible to the agent: available assets, valid values, composition grammar, and evaluation criteria are all accessible without asking the model to infer the system from rendered pages alone.

#### Ability layer

Ollie exposes seven consolidated abilities:

| Ability | Scope |
|---|---|
| `ollie/manage-posts` | Post/CPT CRUD and pattern-based creation |
| `ollie/manage-content` | Top-level block replacement, insertion, deletion, and replacement batches |
| `ollie/manage-blocks` | Nested attribute and saved-HTML updates |
| `ollie/manage-patterns` | Cloud search plus top-level pattern apply/replace |
| `ollie/manage-global-styles` | Global style tokens and Font Library operations |
| `ollie/manage-navigation` | Block navigation list/get/create/update |
| `ollie/manage-templates` | Template and template-part reads/updates |

The action-router design keeps the **ability name list** small (seven). Each ability is still a large enum of actions (`list`, `create`, `batch-update`, `list-text`, …). The model chooses a router **and** an action. Descriptions do substantial routing work. Writes are immediate except pattern apply/replace's unbound preview/confirm.

That is a different kind of small than AWPT's catalog: Ollie hides cardinality inside schemas; AWPT hides sequences inside executors and keeps one job per ability.

#### Write and preview model

Most Ollie mutation actions write immediately. Pattern apply/replace is the exception: it offers a transient preview and a second `confirm` call.

Structural deletion does exist, correcting the earlier assessment: `ollie/manage-content delete_block` removes a **top-level** block by numeric index. Ollie's nested `manage-blocks` path supports updates but not nested deletion. AWPT's dotted-path removal is therefore broader.

### AWPT

AWPT still *registers* many abilities (diagnostics, site settings, knowledge, MCP). **The model does not see that list.** Turn allowlists expose a small job catalog. Prompt modules, work-context, domain packs, and recovery text stay behind that catalog — they are not extra tools.

For focused **content edit / Improve**, the model-facing jobs are:

| Job | Ability the model may call |
|---|---|
| Page structure, sections, fingerprints | `awpt/read-block-tree` |
| One leaf's attrs / inner HTML | `awpt/get-block` |
| Surgical set / remove / insert | `awpt/propose-block-batch-update` |
| Swap a section with a theme pattern | `awpt/propose-pattern-replace` (server prepares) |
| Add a section | `awpt/propose-pattern-insert` (server prepares) |
| Optional slot preview | `awpt/prepare-pattern-change` |
| Full-document freehand | `awpt/propose-content-update` |
| Expand a named design-system section | `awpt/read-design-system` |
| Rank theme patterns | `awpt/recommend-patterns` |

Create stays `prepare-pattern-draft` → `propose-patterned-post` (bespoke fallback: `propose-new-post`). Site, template, navigation, and diagnose tools appear only when the turn profile is that job.

AWPT uses the active theme as design authority and compiles `theme.json`, registered patterns, domain-pack catalogs, and declarative rules into one inspectable snapshot. A task-scoped slice of that snapshot is injected into the prompt; `awpt/read-design-system` expands a named section. Ollie remains richer as theme-specific prose.

AWPT routes content, layout, and configuration mutations through staged actions. Terminal requires human approval; Review may auto-apply exactly one constrained, reversible action after validation and rendered inspection. Supporting operations such as approved media acquisition have their own boundaries and are not evidence that proposal writes may bypass staging.

## Tool catalog comparison

Count abilities the model is offered on a typical page-edit turn, not every PHP class on disk.

| | Ollie | AWPT (content edit / Improve) |
|---|---|---|
| Names on the wire | 7 routers (`ollie/manage-*`) | About 6–9 abilities, allowlist by turn (evaluate is read-only; act is batch + pattern propose) |
| How the model picks a verb | `action` enum inside the router (`list-text`, `batch-update`, `delete_block`, …) | Ability name **is** the verb (`propose-block-batch-update` with `kind` `set` / `remove` / `insert`) |
| Who routes multi-hop work | Skill prose + action descriptions | Server (pattern propose prepares; `set` chooses attr vs HTML slot) |
| Targeting | Top-level numeric index; nested `index_path` arrays | Dotted `path` + 64-char `fingerprint` |
| Pattern change | `manage-patterns` search → apply/replace; model often resends markup; preview token is not bound | `propose-pattern-replace` / `insert` with `path` + `intent` (or a real `preparation_id`). Compact text/media slots. Receipt is an implementation detail |
| Surgical copy | `list-text` (leaves + current HTML) → `batch-update` | `read-block-tree` then `get-block` only when markup is required → one `set` batch. **No `list-text` yet (G4)** |
| Write boundary | Immediate, except an unbound pattern confirm | Staged proposal; human apply (Review: one constrained auto-apply) |

**Do not close the cardinality gap by adding tools.** Ollie looks small because routing is stuffed into seven schemas. AWPT looks small because allowlists and executors own routing. Re-offering `propose-block-attrs-update` / `insert` / `remove`, `list-blocks`, or a required `prepare-pattern-change` hop would recreate the maze session 352 died in.

If surgical turns still take too many `get-block` calls, the fix is G4: a **compact leaf view on the existing tree read** (path, fingerprint, editable HTML, mutation kinds) — not a new write ability.

## Secondary implementation comparison

This table is useful for implementation choices, but tool parity is not the objective. The remaining product gap is **live adoption of the small catalog** (G5) and cheaper leaf evidence (G4), not whether a design-system contract exists and not whether AWPT's registered-ability count matches Ollie's seven names.

| Dimension | Ollie | AWPT | Assessment |
|---|---|---|---|
| Design-system access | Theme-specific skill, tokens, patterns, archetypes, examples, rubric | Compiled snapshot via `awpt/read-design-system` plus a task-scoped injected slice; packs optionally enrich it | AWPT now has a theme-neutral contract; Ollie is still richer as prose for one theme |
| Agent routing | Small, explicit skill spine | Dynamic work-context and prompt modules | Ollie is easier to reason about; AWPT is more context-sensitive |
| Progressive disclosure | Dedicated token/markup/design references | Slim injected spine; compose/global-styles slices; `read-design-system` expands a named section | Both use it; Ollie still has richer hand-authored references |
| Pattern policy | Strong default: pattern first | Pattern preferred, bespoke fallback allowed | Similar intent; Ollie communicates it more decisively |
| Pattern retrieval | Semantic cloud search, local disk cache | Registered/theme/domain pattern catalog and recommendations | Ollie has broader discovery; AWPT has stronger provenance/control |
| New-page speed | One-shot best-pattern creation | Prepared/staged patterned post workflow | Ollie is faster; AWPT is safer and more customizable |
| Custom content in patterns | Returns `merge_required` and asks the model to merge raw markup | Receipt-bound compact text/media slots | AWPT is materially safer |
| Surgical text workflow | `list-text → batch-update` with precomputed paths | `read-block-tree` (+ `get-block` for markup) → `propose-block-batch-update` `kind=set` | Ollie is more ergonomic (one compact leaf list). AWPT is more strongly verified. Do not add a second write tool to close this |
| Structural edits | Top-level numeric indices and immediate writes | Dotted paths, fingerprints, atomic `set`/`remove`/`insert` proposals, staged apply | AWPT is broader and safer |
| Batch execution | Mutate one tree, serialize/save once | Validate against original; apply staged typed mutations | Shared atomic goal; AWPT adds proposal and drift boundaries |
| Design tokens | Explicit hard rules and detailed theme-specific reference | Resolved tokens in the compiled snapshot; theme-derived preset baseline plus optional pack rules | Lesson adopted at the proposal boundary, not as Ollie token names |
| Design judgment | Presets, archetypes, rhythm, hierarchy, rubric | Injected scope-specific guidance IDs, pack guidance files, and composition validators. No first-class compiled rubric object | Rubric stays pack guidance, not a second schema |
| Schema validation | Parser/registry checks, mostly warnings, plus sanitizer fixes | Composition, block, preservation, and domain validators | AWPT is stricter at proposal/apply boundaries |
| Preview | Pattern-only transient preview | Staged WordPress preview/autosave plus rendered inspection | AWPT is stronger |
| Concurrency | Numeric position; no content fingerprint | Fingerprints, original content, source hashes, apply-time conflicts | AWPT is much stronger |
| Approval and recovery | Mostly immediate mutation | Stage, apply, reject, rollback/Undo, duplicate/retry control | AWPT is much stronger |
| Observability | Ability responses | Sessions, workflows, tool calls, incidents, scorecards | AWPT is much stronger |
| Global styles | Rich token and font-management convenience | Read then staged `propose-global-styles-patch` (full-document update is not on the default compose list) | Ollie is more complete; AWPT is safer |
| Navigation/templates | Consolidated direct reads and mutations | Read then staged proposals | Same capability class; different safety model |

## Important code-versus-documentation findings

The Ollie skill is valuable, but some guarantees in its prose are stronger than the implementation.

### Design linting is not a universal write gate

The skill says the linter rejects hardcoded tokens and the ability reference says all block markup passes through two validation layers. In source:

- the design linter is called by pattern apply/replace and by the standalone lint endpoint;
- post creation/update, content mutation, block mutation, template mutation, and navigation mutation call the block sanitizer but not the design linter;
- pattern apply/replace returns the lint summary but does not stop an apply when `validation.valid` is false;
- most schema findings—unregistered blocks, attribute type mismatches, invalid child blocks, empty content, excessive nesting, and duplicate anchors—are warnings.

AWPT should adopt the token checks, but enforce them at its proposal boundary instead of copying the advisory behavior.

### Pattern preview confirmation is not bound

The pattern schema describes `preview_token` as required when `confirm=true`. The execute path accepts confirmation with no token, does not load or compare the transient payload, and merely deletes a supplied token before writing newly recomputed content.

AWPT's durable action ID, preview payload, content fingerprints, and apply-time conflict checks are the correct foundation to retain.

### Immediate writes are pervasive

Ollie directly updates posts, templates, navigation, global styles, and fonts. Several paths temporarily remove KSES filters after custom sanitization. Ability annotations also mark mutation tools—including post deletion—as non-destructive.

This is appropriate evidence about Ollie's intended convenience, not a safety pattern for AWPT.

### Block mutation boundaries matter

- `manage-content delete_block` is top-level only.
- `manage-content batch_update` replaces top-level blocks in descending index order and saves once.
- `manage-blocks batch-update` supports nested attribute/inner-HTML edits and saves once.
- `manage-blocks update` replaces `innerContent` with one HTML string; the skill correctly routes it toward text leaves, because using it indiscriminately on a container could invalidate its child placeholder map.
- Ollie's block validator keeps duplicate-anchor state in a static local variable during recursive validation, which can leak state across validations in one request.

These caveats reinforce AWPT's typed-operation and exact-target approach.

### Cloud pattern caching changes theme files

Semantic pattern search writes fetched pattern PHP files into the active theme's `patterns/cloud` directory. AWPT should not adopt this side effect implicitly. External pattern acquisition would need an explicit trust, provenance, update, and storage policy.

## What AWPT should build

### G1 — Define a Design System Access Contract — closed

**Priority: highest**

Define a normalized, inspectable contract that separates design authority from execution mechanics. The contract should be fillable by active-theme data, theme-owned guidance, registered WordPress assets, and domain packs.

It should expose:

- system identity, source, version/hash, and active variation;
- resolved design tokens with semantic roles, not only raw values;
- available blocks, variations, patterns, templates, and reusable components;
- pattern and component selection guidance;
- composition archetypes and representative native examples;
- hard constraints, soft guidance, and anti-patterns as distinct rule classes;
- semantic/accessibility/responsive expectations;
- a quality rubric;
- source provenance, scope, priority, and confidence for each rule or asset;
- validation hooks or machine-checkable equivalents where available.

The contract must be theme-neutral. Ollie can populate it richly; CivicPress or another theme can provide different tokens, patterns, and rules without changing AWPT's workflow code.

Exit criterion: one read returns a stable design-system manifest whose provenance can be traced back to active-theme, WordPress, and domain-pack sources without importing Ollie-specific knowledge.

### G2 — Compile task-scoped design context progressively — closed

**Priority: high**

Add an AWPT-owned compiler that selects the smallest relevant slice of the design-system manifest for the current work type.

Examples:

- surgical copy work needs editable-block rules and existing component semantics, not the full archetype catalog;
- pattern insertion needs pattern candidates, token compatibility, intended section role, and carry-forward rules;
- bespoke composition needs the active direction, tokens, components, archetypes, examples, anti-patterns, and rubric;
- global-style work needs resolved current tokens, allowed mutations, and downstream impact;
- Dufresne Review needs the same design evidence as Terminal, plus its surface-specific apply constraint—not a separate design prompt.

Keep the always-on spine short and load detailed references only after routing. Record which design-system sources influenced the plan and proposal.

Exit criterion: prompt/contract tests prove the correct design context appears for pattern creation, surgical editing, global-style changes, and bespoke fallback without bloating unrelated turns.

### G3 — Enforce the compiled design contract on proposals — closed

**Priority: high**

Use the same compiled contract for validation, so instructions and enforcement cannot silently diverge. For custom or pattern-adapted markup:

- reject hardcoded colors when a matching token system exists;
- reject unsupported custom font sizes and spacing where theme presets are available;
- validate referenced slugs against resolved theme settings;
- validate block registration, attribute types, allowed children, anchors, and wrapper synchronization;
- distinguish blocking errors from safe, deterministic normalization;
- return exact path/rule recovery data.

Do not impose Ollie's palette names, section wrappers, or aesthetic preferences on other themes. Existing legacy content should not be rejected merely because it predates the policy; enforce this primarily on newly proposed or materially rewritten markup.

Exit criterion: invalid bespoke proposals fail before staging, while unchanged legacy markup and valid active-theme patterns pass.

**Shipped vs declined for G1–G3.** Shipped: one theme-neutral snapshot, unified catalog scopes (`compose`, `edit`, `evaluate`, `template`, `navigation`, `global_styles`, `diagnose`, `investigate`), a slim injected spine, compose/redesign and global-styles detail slices, Improve evaluate/act instructions that treat the slice as given, and `theme-require-presets` on newly introduced findings when the active theme publishes matching presets. Declined: a second theme manifest, a compiled rubric/example/anti-pattern schema, a full registered-block or template inventory in the always-on slice, Ollie palette names or section wrappers as universal rules, and a DiscoveryPolicy gate that forces `read-design-system` on evaluate.

### G4 — Add a compact editable-block inventory

**Priority: high**

Ollie's best execution ergonomic is `list-text`: it returns only editable leaves with precomputed paths, types, and current HTML. This is not a new write ability. AWPT already retired `list-blocks` from the model catalog; do not bring it back.

Fold a compact **leaf view into `read-block-tree`** (or a bounded flag on that same ability) containing:

- dotted path;
- block name, semantic type, and component/design role when known;
- complete fingerprint;
- exact editable rich text or saved HTML when safely bounded;
- supported mutation kinds (`set` / `remove` only for that leaf);
- parent section identity;
- truncation flags and a direct `get-block` recovery hint.

Exit criterion: a multi-block copy edit can move from **one** tree read to one `propose-block-batch-update` without a full-content read, path guessing, or extra `get-block` on group wrappers whose inner HTML is empty.

### G5 — Finish live small-catalog adoption

**Priority: high**

Pattern propose now auto-prepares. Score **outcomes**, not a `prepare-pattern-change` tool hit. Session 352 (LASLI / post 829, August 2026) wrote a strong evaluate plan and then failed act on an invented `preparation_id` and Gutenberg comments in inner HTML — both of those contracts have since been internalized.

Run:

1. an additive CTA case that stages **one** `propose-pattern-insert` with `path` + `intent` (or a real receipt). Internal prepare is an implementation detail;
2. a dynamic FAQ case that first targets an invalid compact slot, then recovers using returned allowed slots without renewed discovery or freehand fallback;
3. a saved-HTML surgical case that stages `propose-block-batch-update` `kind=set` with leaf `html` (wrapping `<!-- wp: -->` may be stripped). Unrelated sections stay put.

Exit criterion: all three reach one staged proposal, preserve unrelated content, and report correction cost accurately. Do not fail a run because the model skipped the optional `prepare-pattern-change` read.

### G6 — Run a comparative outcome cohort

**Priority: medium after G1–G5**

Run at least 12 fresh AWPT cases across:

- structural replacement;
- additive insertion;
- surgical copy/saved-markup repair;
- no-change control.

For a smaller matched subset, use Ollie fixtures to compare outcomes—not API call counts. Score:

- task completion;
- factual/content preservation;
- active-theme coherence;
- hierarchy and accessibility;
- responsive/render quality;
- number and cost of corrections;
- provenance;
- approval clarity;
- safe handling of concurrent edits.

Exit criterion: the scorecard identifies where AWPT loses quality or adoption by workflow stage and does not treat Ollie-specific tool shapes as parity requirements.

### G7 — Complete lifecycle browser coverage

**Priority: medium**

Current live evidence covers Review apply, Undo visibility, atomic competing-proposal rejection, and nested-removal replay. Remaining coverage:

- Terminal evaluate failure, plan-ready reload, Execute, duplicate Execute, act retry, staged reload, Apply, Reject, and focus change;
- Review fresh/continuing context, Start fresh persistence, optional notes, unsafe-action rejection, reload recovery, and Undo after reload.

Exit criterion: every durable state has verified copy, buttons, reload behavior, and the intended approval boundary.

### G8 — Decide whether site-design administration belongs in AWPT

**Priority: low / product decision**

Ollie includes Font Library installation/removal and structured global-style controls. AWPT can stage global-style JSON changes but does not offer equivalent font acquisition and lifecycle management.

Do not add this merely for parity. Decide whether AWPT is meant to administer design assets or only propose changes using installed assets. If added, remote font acquisition needs explicit network trust, license/provenance metadata, file validation, preview, approval, and rollback.

## What AWPT should not copy

- Immediate writes from ordinary agent tool calls.
- Numeric indices without fingerprints or source hashes.
- Preview tokens that are optional or not bound to the proposed payload.
- Raw model-side merging of custom content into full pattern markup.
- Validation summaries that do not block invalid writes.
- Temporary KSES removal as the main safety story.
- Implicit writes into the active theme during pattern search.
- Ollie-specific tokens, section wrappers, or aesthetic presets as universal rules.
- One large action-router schema (Ollie's seven `manage-*` enums) **or** a pile of overlapping `propose-block-*` aliases. Both overexpose verbs. Keep one job per ability; keep validation in the executor.
- A required `prepare-pattern-change` hop now that propose can prepare. Optional slot preview is enough.
- Placeholder-first page creation for tasks where the user supplied authoritative content or Review requires preservation.

## Current evidence

### AWPT live Review evidence

- Session 328 / action 143: one atomic 18-change surgical batch applied after rendered inspection.
- Session 335 / action 145: competing proposals failed together with `awpt_multiple_proposals`; a later consolidated batch applied and exposed Undo.
- Post 843 / stored tool call 2697: after the direct-parent placeholder fix, the exact four-removal batch replayed successfully, retained five intended nested children, emitted no PHP warnings, and made no database write.
- Pending posts 829, 834, 777, 778, and 779: read-only deep-removal dry runs each removed exactly one simulated target, preserved its parent, emitted no warnings, and left the database unchanged.
- Sessions 346–352 (post 829 LASLI, August 2026): evaluate can produce a concrete keep/improve plan and `awpt-units`. Act failed on the old catalog (invented `preparation_id` / `<FROM_PREPARE>`, 20k-token `[`, `replace_inner_html` with block comments). Session 351 staged a heading-level batch, then was rolled back by the next Improve. A provider-authored live `set`/`html` success after the catalog change remains pending.

These are targeted checks, not a representative cohort.

### Historical baseline

All cohorts below predate the current durable workflow, prepared insert, atomic proposal boundary, saved-HTML operation, and nested-removal fix.

| Cohort | n | Prepare success | Replace success | Server materialized | Freehand provenance |
|---|---:|---:|---:|---:|---:|
| Post-M1 | 10 | — | 0 | 1 | 2 |
| Post-M2 enforcement | 10 | 0 | 0 | 0 | 7 |
| Smoke post-rollback | 5 | — | 0 | 0 | 3 |
| Post-M3 | 5 | 0/5 (0 attempts) | 0/5 | 0/5 | 3/5 |
| Scenario pack | 7 | 1/7 | 0/7 | 0/7 | 2/7 |

Do not use these cohorts to judge the current workflow.

## Recommended next order

1. Run the three G5 **outcome** fixtures on the current catalog (insert, slot recovery, `set`/`html`). Do not score a prepare-pattern-change tool call.
2. If those runs still spend hops on empty group `get-block`s, add the G4 leaf view **on `read-block-tree`**. Do not add a write ability.
3. Run the mixed comparative cohort (G6).
4. Finish lifecycle browser coverage (G7).
5. Make an explicit product decision about Font Library/design-asset administration (G8).
