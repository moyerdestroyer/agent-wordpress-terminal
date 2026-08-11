# Pattern-native workflow gaps: current state and next work

Ollie remains reference-only (`ol/` is offline parity material). AWPT borrows useful workflow ideas without making Ollie Pro, the Ollie theme, or the Ollie skill a runtime dependency.

## Status as of 2026-08-10

The implementation gaps that previously made Improve unreliable are now closed in this workspace:

- Evaluate → act is a typed, server-backed workflow rather than transcript inference. The server owns the plan, focus binding, expiry, state transitions, and action linkage.
- The terminal, review bridge, and CLI use the same two-phase semantics. `/improve` now enters evaluate rather than silently retaining the legacy one-shot server behavior.
- Prepared insert consumes the signed insert receipt, its bound pattern body and position, compact text/media updates, source hash, and anchor fingerprint. The simpler catalog-name insert remains an explicit uncustomized path.
- Compact-slot failures return receipt-bound editable/media slots, carry-forward values, and a same-receipt retry example for both replace and insert.
- Scorecards now use declared scenario classes, unique run IDs, separate insert/replace metrics, correction counts, and an explicit prepare → propose funnel.
- Review-queue Improve is one-click evaluate → act → apply for one reversible, page-scoped content action. It exposes whether page-specific AI history is fresh or continuing, accepts optional reviewer direction, supports an explicit fresh start, and retains Undo. Terminal writes remain staged behind explicit approval.
- Surgical batches now support one atomic `update_block` mutation when a block needs both attribute and rich-text changes. The ability schema, validator recovery text, stored payload, review summary, and act prompt all expose the same per-path rule.
- Improve act uses a generous 480-second overall safety ceiling instead of rigid phase allocations. A productive existing-page composition may use up to 450 seconds of the remaining wall; completion-count and repeated-proposal-failure caps remain the loop breakers.

The main uncertainty has moved from capability to repeatability and measured behavior. One clean live review run now validates surgical execution and automatic apply, but the historical cohorts still cannot tell us whether the model consistently authors and follows a page-specific plan, adopts prepared operations, recovers from compact-slot errors, or produces better rendered results across scenario classes.

### Evidence levels

| Area | Current state | Evidence |
|---|---|---|
| Patterned new post | Implemented | Composition and contract tests |
| Existing-section replace | Implemented, receipt-bound | Integrity, drift, preservation, and rebuild tests |
| Basic registered-pattern insert | Implemented | Catalog materialization tests |
| Customized prepared insert | Implemented in this workspace | Schema/receipt/recovery contract tests; live provider run pending |
| Evaluate-only and act isolation | Implemented; one live review run | Runtime/workflow tests and session 328; evaluate used deterministic fallback plan |
| Durable plan lifecycle | Implemented in this workspace | Database-backed state and transition tests |
| Surface-specific apply boundary | Implemented; review happy path exercised | Session 328 auto-applied action 143; Terminal and Review edge cases remain |
| Mixed-class scorecard v2 | Implemented in this workspace | Unit tests; fresh cohort pending |
| Routing adoption and rendered quality | One positive surgical case; broader behavior unknown | Render inspection succeeded and reviewer accepted page 847; no fresh cohort or formal rubric yet |

“Implemented in this workspace” is narrower than “validated in production.” The code is currently uncommitted. One clean post-change review run is recorded below, but there is not yet a representative provider cohort.

## What changed

### One durable Improve workflow

Sessions now store one active Improve workflow with a UUID, prompt version, focused post, expiry, evaluate/act turn IDs, authoritative plan, related action IDs, and terminal state. The lifecycle distinguishes evaluating, plan-ready, acting, staged, no-change, failed, applied, rejected, and rolled back.

The act phase accepts a workflow ID, not a client-supplied plan. This closes two earlier problems: a reload no longer has to guess whether transcript text is executable, and a stale or duplicated Execute click cannot silently launch a second act turn. Focus mismatches, expired plans, empty plans, and invalid transitions fail closed.

Frontend orchestration is shared by the terminal and review bridge; PHP orchestration is shared by the CLI runners. Their completion policy is intentionally different: Terminal stops at a staged action, while a reviewer’s Improve click applies exactly one page-scoped, reversible, review-safe content action. Unsupported or ambiguous action sets fail closed. Recovered proposals stay staged rather than being silently applied on reload.

The review bridge keeps page-specific history because follow-up reviewer requests benefit from continuity, but now states whether that history is fresh or continuing. **Start fresh** creates a new focused session, and focused-session reuse selects that newest session rather than preferring an older session with more actions. The review evaluation query also carries the post ID/title, a generic final-review rubric, and optional reviewer notes so knowledge and pattern retrieval receive task-shaped context.

### Honest prepared insert

`propose-pattern-insert` now has two explicit modes:

1. Prepared: `preparation_id` plus compact `pattern_text_updates` / `media_placements`. The server loads the receipt-bound pattern and position, rechecks the page and anchor, applies the compact edits, and records preparation provenance.
2. Uncustomized: `pattern_name`, path, and position after pattern-structure evidence. This remains useful only when the registered pattern is valid as-is.

Catalog drift does not invalidate the bound prepared body. Page or anchor drift does. Prompt copy now agrees with composition validation: required authoring placeholders must be replaced before staging.

### Recoverable compact-slot errors

Insert and replace proposal errors now return the rejected slot along with the receipt’s allowed text/media slots, carry-forward data, and a compact retry shape. Correctable errors keep the same receipt usable. Scorecard v2 infers correction count from failed pattern proposal attempts before success and records `prepared_then_corrected` when the run recovers to server materialization.

### Better artifacts

Queue and scenario artifacts use stable unique run IDs instead of overwriting by post ID. Metadata now includes scenario class, provider/model, prompt version, plugin version and Git state when available, active theme/version, active domain packs, and a deterministic pattern-catalog hash.

Structural denominators come from declared classes:

- `structural_replace`
- `additive_insert`
- `surgical_copy`
- `no_change`

Legacy summaries still use the old heuristic so historical artifacts remain aggregatable.

## Historical evidence

All recorded cohorts below predate durable workflow state, prepared insert, and the current act isolation.

| Cohort | n | Prepare success | Replace success | Server materialized | Freehand provenance | Interpretation |
|---|---:|---:|---:|---:|---:|---|
| Post-M1 | 10 | — | 0 | 1 | 2 | Replacement existed; one insert materialized. |
| Post-M2 enforcement | 10 | 0 | 0 | 0 | 7 | Hard freehand locks were counterproductive. |
| Smoke post-rollback | 5 | — | 0 | 0 | 3 | Staging recovered, but preparation was unused. |
| Post-M3 | 5 | 0/5 (0 attempts) | 0/5 | 0/5 | 3/5 | Section metadata alone did not change routing. |
| Scenario pack | 7 | 1/7 | 0/7 | 0/7 | 2/7 | Exposed missing slot mapping and prepared-insert support. |

The old S2 and S4 failures are now direct regression targets: S2 should recover from a bad compact path without touching its dynamic Query subtree; S4 should customize a prepared CTA before composition validation.

### First post-change live evidence

Two non-applying S4 additive-insert smokes were recorded after the implementation:

- Run `20260810140443-rzne4f` preserved evaluate isolation and successfully prepared an insert receipt, but the act model discarded the receipt, guessed `civicpress/call-to-action-section` instead of using the prepared pattern, failed the pattern proposal, and staged a surgical fallback. Scorecard v2 correctly reported one correction and `proposal_failed`; elapsed time was 250.6 seconds.
- After tightening receipt guidance, run `20260810141041-n67uuc` again preserved evaluate isolation but the plan itself contained the same guessed slug. Act never reached preparation, the insert failed, and provider finalization timed out at 290.2 seconds. No action was staged.

This is useful negative evidence: the prepared-insert server contract works, but plan fidelity and provider latency still block adoption. The current workspace now prevents a plan that explicitly names `prepare-pattern-change` from entering composition until bound preparation succeeds, tells evaluation not to guess slugs or placeholder URLs, and makes the prepared proposal schema distinguish receipt-bound fields from the legacy name-based path. A larger cohort should wait until the next S4 smoke reaches receipt-bound proposal input; otherwise it will mostly measure the same known failure at roughly four to five minutes per run.

A live review-queue retry on post 847 exposed a separate surgical-batch failure. Evaluate completed successfully in session 327, and act composed a detailed 20-change batch, but path `7.0` appeared twice: once as `update_attrs` and once as `replace_text`. The batch correctly failed atomically, then the old 240-second act wall left only about 32 seconds for correction after a 204-second compose call. No action was staged or applied. The new `update_block` contract removes the invalid-shape cause, and the 480-second outer circuit breaker leaves productive correction time without assigning fixed budgets to discovery, composition, or repair.

The next live review attempt on the same post succeeded end to end:

- Session 328 moved its durable workflow to `applied` and linked action 143. Evaluate ran for about 73 seconds; act ran for about 137 seconds, comfortably inside the 480-second outer safety ceiling.
- Act staged one atomic 18-change `propose-block-batch-update`: eight heading attribute updates, one combined `update_block` at the formerly conflicting path `7.0`, and nine removals of redundant `A:` paragraphs. There were no failed proposal or provider calls.
- Rendered-preview inspection succeeded before the Review surface auto-applied the action. The current post content matches the action’s applied payload, contains no `A:` marker, and was positively assessed by the reviewer.
- This validates the new combined mutation contract and one-click Review stage → inspect → apply happy path. It does **not** yet validate model-authored plan quality: evaluate exhausted its tool loop and AWPT produced the deterministic fallback plan, after which act used targeted page evidence to create the successful surgical batch. Plan finalization quality therefore remains a measured-behavior gap rather than a blocker for this case.

## Remaining gaps

### G1 — Run the post-change mixed cohort

**Priority: highest**

After one S4 smoke reaches a receipt-bound proposal input, run at least 12 fresh evaluate → act cases, with three runs in each declared class. Retain summary and raw artifacts together. For structural cases, inspect whether the act turn followed the evaluated operation/path and where the funnel stopped. For copy and no-change controls, verify they are excluded from the structural denominator.

Exit criterion: a reproducible scorecard locates prepare → propose loss by stage and reports insert separately from replace without denominator pollution.

### G2 — Exercise lifecycle and surface-specific apply UX in a real browser

**Priority: high**

The production bundle and unit contracts are healthy, and session 328 now confirms Review’s basic one-click evaluate → act → rendered inspection → apply path. Remaining browser work is lifecycle and failure coverage. In Terminal, exercise evaluate failure, plan-ready reload, Execute, duplicate Execute, act failure/retry, staged reload, Apply, Reject, and focus change. In Review, verify fresh/continuing context, Start fresh persistence, optional reviewer notes, unsafe/multiple-action fail-closed behavior, reload recovery, and Undo.

Exit criterion: browser evidence confirms state copy, button availability, reload recovery, Terminal’s human Apply boundary, and Review’s remaining constrained-apply edge cases plus Undo. The basic Review happy path is complete.

### G3 — Add live prepared-insert and same-receipt recovery fixtures

**Priority: high**

Contract tests prove the server shape; provider behavior is still unknown. Run an additive CTA case that maps copy/media into prepared slots and an FAQ case that first targets a rejected dynamic path, then retries using the returned allowed slot without renewed discovery or freehand fallback.

Exit criterion: both runs reach server materialization, preserve unrelated sections, and report correction cost accurately.

### G4 — Score rendered quality and preservation

**Priority: medium**

The harness is stronger on tool paths than outcomes. Add retained preview/render findings, exact before/after preservation checks, successful apply, rendered validation, approval clarity, and a rubric for paired AWPT/CivicPress vs Ollie fixtures. Pattern provenance alone is not parity evidence.

Exit criterion: another operator can reproduce a run and compare task quality, preservation, correction cost, validation, and approval clarity.

### G5 — Audit fallback honesty using current evidence

**Priority: medium, evidence-gated**

Do not restore the rolled-back global freehand lock. After the new cohort, measure fallback-code frequency and validate objectively checkable claims such as dynamic-section or preservation conflicts. Keep legitimate bespoke/surgical work available.

Exit criterion: checkable fallback claims are evidence-backed without blocking valid custom work.

## Current workflows

### Existing section (Terminal/CLI)

```text
evaluate plan
  → prepare-pattern-change(mode=replace, target path/fingerprint)
  → propose-pattern-replace(preparation_id, compact text/media changes)
  → staged preview
  → human approval
  → apply
```

### Added section (Terminal/CLI)

```text
evaluate plan
  → prepare-pattern-change(mode=insert, target path/fingerprint, position)
  → propose-pattern-insert(preparation_id, compact text/media changes)
  → staged preview
  → human approval
  → apply
```

### Explicit uncustomized insert

```text
read-pattern
  → propose-pattern-insert(pattern_name, path, position)
  → composition validation
  → staged preview
```

### Review queue

```text
explicit Improve click + optional reviewer request
  → evaluate with page-specific review context
  → act into exactly one page-scoped review-safe proposal
     (combined attrs + text on one path use one atomic update_block)
  → automatic apply
  → visible result + Undo
```

### Custom fallback

Preparation may return `custom_fallback` with a machine-derived reason. Freehand remains available but cannot claim server-materialized pattern provenance.

## Recommended next order

1. Record the 12-run mixed cohort (G1).
2. Browser-test lifecycle and approval states (G2).
3. Run the prepared-insert and correction recovery fixtures (G3).
4. Add rendered/preservation scoring before making parity claims (G4).
5. Tighten only fallback claims the new evidence shows are unsupported (G5).
