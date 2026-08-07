# Pattern-native workflow gaps: living plan

Ollie is reference-only (`ol/` is offline parity material). This plan borrows useful workflow ideas without making Ollie Pro, the Ollie theme, or the Ollie skill a runtime dependency.

## Status as of 2026-08

**Section replace is implemented as a capability. Improve agents rarely call it.** Soft prefer + freehand remain the default Improve path. Full freehand hard-locks (M2b) were tried and rolled back.

### Milestone board

| Milestone | Status | Notes |
|---|---|---|
| **M0** Evidence harness | **Partial** | Fresh-session queue default, path classifier v2, `server_materialized` from ops/manifest, raw traces. Still missing: catalog hash in summaries, paired Ollie scores, full rubric automation. |
| **M1** Section replace | **Done (capability)** | `prepare-pattern-change` → `propose-pattern-replace`, receipts, fingerprint fail-closed, contract tests, apply-path wrapper fidelity. |
| **M2a** Replace prep receipts | **Done (with M1)** | Bound `preparation_id` for section replace only. Invented IDs fail closed. |
| **M2b** Hard freehand / full fallback matrix | **Rolled back** | WriteAuthorization + freehand locks + draft-create receipt bind removed after post-M2 audit (0 materialize, freehand worse; Improve brief “bespoke” leak). **Do not reintroduce without prep adoption.** |
| **M3** Target/section modeling | **Done (capability)** | Outline roles, target-aware prepare/recommend, carry-forward list, soft op hints, dynamic preserve warnings. Adoption still Improve-side. |
| **M4** Whole-page redesign | **Not started** | Correctly gated on measured need after M3+M5 cohort |
| **M5** Tune targets from baseline | **Partial (scorecard)** | `QueueImproveScorecard` + `bin/cohort-scorecard.php`; rates report-only — no formal % targets yet |

### Audit cohorts (AWPT-only; not Ollie parity)

| Cohort | n | Server mat. | Prepare OK | Replace OK | Freehand provenance | Notes |
|---|---:|---:|---:|---:|---:|---|
| Post-M1 | 10 | 1 | — | 0 | 2 | One insert; freehand common |
| Post-M2 (enforcement) | 10 | 0 | 0 | 0 | 7 | Contaminated freehand unlock |
| Smoke post-rollback | 5 | 0 | — | 0 | 3 | Freehand stages again; prepare unused |
| **Post-M3** | **5** | **0/5** | **0/5** (attempted **0/5**) | **0/5** | **3/5** | Paths: freehand 3, surgical_with_unfit 2. All staged valid. Mean wall ~337s. M3 tools never called. |
| **Scenario pack** | **7** | **0/7** | **1/7** (S2 only) | **0/7** | **2/7** | Task-shaped terminal scenarios (`bin/run-scenario.php`). Soft path match 0 for replace/insert. See table below. |

#### Scenario pack detail (terminal, 2026-08-07)

| ID | Soft expected | Path | Prep | Key failure / note |
|---|---|---|---:|---|
| S1 middle swap | pattern_replace | no_change | 0 | `propose-pattern-replace` without prep → `awpt_preparation_id_required`; also provider HTTP timeout |
| S2 FAQ replace | pattern_replace | surgical_with_unfit | **1** | **Prepare succeeded** (role=faq, path=2). Replace failed: `awpt_pattern_text_block_not_editable` (model wrote slots onto `core/query`) → freehand fallback |
| S3 header only | pattern_replace | surgical_or_other | 0 | Never called prepare/replace; surgical content update |
| S4 insert CTA | pattern_insert | surgical_or_other | 0 | `propose-pattern-insert` without prep; `awpt_placeholder_content_remaining` |
| S5 copy-only | surgical | no_change | 0 | **Good control:** asked which typo to fix; no redesign |
| S6 carry-forward | pattern_replace | freehand provenance | 0 | Invented prep ids (`placeholder-needs-prepare`) → `awpt_preparation_not_found` |
| S9 Improve baseline | (none) | freehand provenance | 0 | Adoption thermometer only |

Artifacts: `tmp-queue-runs/cohort-scenario-pack-summary.json`, `awpt-scenario-*-post-*.json`, `cohort-scenarios.log`. Harness: `bin/run-scenario.php` + `evaluation/scenarios.json`.

### Bottleneck

Not “replace is broken” — **agents almost never call prepare/replace on Improve.** Post-M3 cohort confirms: **0/5 prepare attempts**, freehand or surgical freehand-adjacent paths dominate. M3 capability is live (outline, target-aware prepare, scorecard); **routing adoption** is still the gap. **M4 not indicated** — failure mode is unused section path, not hop-count for whole-page redesign.

---

## Verdict

The earlier diagnosis was **directionally right but too broad**.

AWPT does have a pattern-native gap for existing-content redesigns: agents commonly read a pattern and then submit model-authored `post_content` with the pattern name attached as provenance. Reading a pattern does not prove that the resulting document was derived from it.

However, AWPT is not generally missing server-side pattern machinery. It already has:

- server expansion of pattern references (`PatternTemplateExpander`);
- ordered multi-pattern composition (`PatternCompositionBuilder`);
- path-addressed text and media edits;
- compact server-materialized creation (`prepare-pattern-draft` → `propose-patterned-post`);
- server-materialized insertion into an existing post (`propose-pattern-insert`);
- **server-materialized section replacement** (`prepare-pattern-change` → `propose-pattern-replace`) — **shipped**;
- composition validation, preview, approval, and apply-time concurrency checks.

What remains open (narrower than the first draft of this doc):

1. ~~No pattern-replace for an existing section.~~ **Filled as ability (M1).** Adoption on Improve is still low.
2. **No compact, server-materialized whole-document redesign contract** (M4) — AWPT product extension; not literal Ollie parity; still deferred.
3. **Preparation is only binding on the replace path.** Freehand and create draft paths are not receipt-hard-locked (M2b rolled back by design after measurement).
4. **Fallback routing is not fully hard.** `no_recommendations` is checked; other unfit codes remain largely advisory.
5. **Evaluation is better but not a full baseline.** Fresh sessions + classifier + raw traces exist; paired Ollie scoring and catalog hash still incomplete.

Concise diagnosis **now**:

> AWPT can materialize patterns for create, insert, **and section replace**. The remaining Improve gap is **adoption** of the replace path (and optionally later whole-page redesign), not missing replace machinery. Hard freehand locks without prep adoption were counterproductive.

---

## What the Ollie reference actually establishes

| Ollie workflow | Current AWPT equivalent | Status |
|---|---|---|
| Create from a full-page pattern | `prepare-pattern-draft` → `propose-patterned-post` | Functionally present |
| Search returns full markup and caches it | `recommend-patterns`, `read-pattern`, preparation output | Similar; durable receipt only required on **replace** |
| Apply a pattern to an existing post | `propose-pattern-insert` | Present |
| Replace an existing top-level section with a pattern | `prepare-pattern-change` → `propose-pattern-replace` | **Implemented** |
| Update text while preserving structure | Block tree + batch/block updates | Present |
| Create from scratch only after miss or explicit custom | Soft routing + partial unfit checks | Partial; freehand remains available |
| Validate before write | `CompositionGate` + staging | Present |

---

## Target workflows

### Existing section (primary; implemented)

```text
read-content / read-block-tree
  → prepare-pattern-change(post_id, intent, mode=replace, target_path [, fingerprint])
  → propose-pattern-replace(preparation_id, text updates, media placements)
  → preview / inspect
  → human approval
```

The model should not serialize replacement pattern markup. Invented `preparation_id` values fail closed.

### Whole-page Improve (M4 — only if warranted)

```text
prepare-pattern-redesign(post_id, intent)
  → propose-patterned-redesign(preparation_id, …)
```

Do not build until section ops + adoption work show coherent full-page transform is still too hard.

### Custom fallback

Preparation may return `custom_fallback` with a machine-derived reason. Freehand remains available without a full M2b matrix (product choice after measurement).

---

## Implementation plan (updated)

### M0 — Make the evidence trustworthy

**Status: partial**

Done:

- [x] Fresh-session default for queue Improve (`bin/queue-improve-one.php`; `--reuse-session` opt-in)
- [x] Derive `server_materialized` from operation + composition manifest
- [x] Path classifier version; raw traces separate from summaries
- [x] First-proposal validity / wall time fields in summaries (basic)

Still open:

- [ ] Catalog hash + fuller version metadata in every summary
- [ ] Paired AWPT/Ollie parity corpus scoring + rubric sheets
- [ ] Correction-count / preservation / preview outcome denser recording

Exit criterion (unchanged intent): reproducible baseline with denominators; paired results when Ollie fixture work is funded.

### M1 — Server-side pattern replacement

**Status: done (capability)**

- [x] `prepare-pattern-change` (insert/replace modes)
- [x] Verified path + fingerprint (live recheck)
- [x] Bound `preparation_id` + editable slots
- [x] `propose-pattern-replace` via composition builder + gate + staging
- [x] Apply-time rebuild + fingerprint fail-closed
- [x] Contract tests (collateral, multi-root, receipt integrity)

Exit criterion for *machinery*: met. Exit criterion for *Improve adoption*: **not** met (agents rarely use the path).

### M2a — Replace prep receipts

**Status: done (shipped with M1)**

- [x] Signed/transient receipt for section change
- [x] Propose-replace consumes receipt; rejects missing/tampered/stale prep

### M2b — Hard freehand / full fallback matrix

**Status: deferred / rolled back**

Tried: global freehand locks, draft create receipt bind, server unfit matrix, compose allowlist stripping content_update.

Measured: worse freehand share; prepare still unused; Improve prompt word “bespoke” auto-unlocked freehand.

**Policy:** do not reintroduce M2b until prepare succeeds often and freehand still abandons bound prep. Prefer soft nudge + ergonomics first.

### M3 — Improve target and section modeling

**Status: done (capability)** — Improve adoption still measured under M5

- [x] `PageSectionModel` outline (path, fingerprint, role, heading, dynamic/preserve flags, links, numeric tokens)
- [x] Richer `top_level_sections` on `read-block-tree` + prepare section menus
- [x] Target-aware prepare ranking (pass section role into `recommend-patterns`; prefer section scope)
- [x] Soft `recommended_operation` / least-destructive hints on prepare output (+ missing-path suggestions)
- [x] Carry-forward list on prepare success for slot filling (model maps copy; no PHP rewrite)
- [x] Dynamic-section preserve warning on replace prep when `preserve_by_default`

### M4 — Whole-document patterned redesign

**Status: won't do for now (gated off)** — post-M3 cohort shows freehand from **never calling** prepare/replace, not from multi-section hop exhaustion. Revisit only if section ops are adopted and still insufficient for page-wide work.

### M5 — Tune routing from measured outcomes

**Status: partial** — scorecard + first post-M3 cohort recorded; **no fixed % targets** (denominators only).

- [x] Per-run scorecard fields (`prepare_change_success`, `propose_replace_success`, freehand, eligibility)
- [x] Cohort aggregator: `bin/cohort-scorecard.php` / `bin/queue-improve-cohort.sh`
- [x] Docs in `evaluation/README.md`
- [x] Post-M3 cohort (n=5 structural): prepare 0/5, replace 0/5, server mat. 0/5, freehand 3/5 — `tmp-queue-runs/cohort-post-m3-summary.json`
- [ ] Optional numeric targets only after adoption moves (still report-only)
- [ ] Catalog hash / theme id in queue meta (cheap M0 leftover; still open)

### Near-term: adoption ergonomics (done)

Not a new milestone letter — product follow-on to M1; **shipped**:

- [x] Auto-fill `expected_fingerprint` on prepare when `target_path` is valid
- [x] Surface top-level sections (path + fingerprint) on `read-block-tree` for prepare
- [x] Soft system nudge after successful prepare-change (prefer replace; do not hard-lock freehand)
- [x] Keep Improve brief free of bare “bespoke” freehand unlocks

---

## Acceptance metrics

| Metric | Applies to | Required direction |
|---|---|---|
| Replace collateral | Contract tests / replace applies | 0 unrelated sections (excl. documented normalization) |
| Stale prep / fingerprint | Contract tests | Fail closed |
| Invented preparation_id | Contract + audits | 0 successful stages |
| False pattern provenance | Ideal long-term | 0 with manifest/receipt — **not** enforced on freehand after M2b rollback |
| Unsupported fallback auth | Ideal long-term | 0 — only `no_recommendations` hard today |
| Server-materialized share | Improve audits | Report-only until adoption moves; **no fixed % yet** |
| Quality / Ollie parity | Paired corpus | Rubric + rendered review; not path telemetry alone |

---

## Non-goals

- No Ollie runtime dependency or copied cloud catalog.
- No forced full-page redesign when a surgical edit or one section replacement is safer.
- No removal of custom/freehand composition for work that needs it.
- No automatic invention of facts, fees, deadlines, links, or media.
- No claim of Ollie parity based only on tool-path telemetry.
- No reintroduction of M2b hard freehand locks without prep adoption evidence.

---

## Recommended next step

1. **Improve evaluate→act shipped (AWPT-only)** — review bridge + `queue-improve-one` default two-step; Dufresne mount unchanged. Re-measure open-ended Improve with `improve-page-eval-act-v1`.
2. Scenario pack still shows propose-without-prepare and slot-mapping footguns (S1/S2/S4/S6) — soft recovery and editable_slots guidance remain high leverage.
3. **M4 stays off.**

Do **not** reintroduce M2b freehand hard-locks without prep→propose conversion evidence.
Do **not** invent fixed materialize % targets without scenario denominators.
