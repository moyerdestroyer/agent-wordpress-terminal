# AWPT comparison evaluations

`ollie-parity-tasks.json` is a shared task corpus for evaluating AWPT + CivicPress against Ollie Pro + its skill. Run each prompt from a clean, equivalent WordPress fixture and record tool traces, the first proposal, validation findings, correction turns, and the human-facing approval state.

**Ollie artifacts are reference-only.** The optional local `ol/` tree (Ollie theme, Ollie Pro, skill docs) is gitignored and is **not** a product dependency. Do not wire Ollie into AWPT runtime, Domain Packs, or Dufresne. Use it only for offline parity exercises against a separate fixture site.

Do not score a fast direct write as safer than an approval-gated proposal. Measure task success and approval clarity separately. A pattern-selection success requires a suitable pattern in the top three candidates; a composition success requires preserved dynamic blocks, registered design tokens, valid block grammar, and no unresolved blocking findings.

### Improve evaluate → act (production-shaped)

Review-queue **Improve** is two internal agent turns (still **one button**; Dufresne unchanged):

1. **Evaluate** — read-only tools; markdown plan only (`ImprovePagePrompt::evaluate_text()`).
2. **Act** — execute the plan (`ImprovePagePrompt::act_message($plan)`).

CLI (matches bridge):

```bash
wp eval-file wp-content/plugins/agent-wordpress-terminal/bin/queue-improve-one.php 848
wp eval-file wp-content/plugins/agent-wordpress-terminal/bin/queue-improve-one.php 848 one-shot   # legacy
```

`prompt_version` in summaries: `improve-page-eval-act-v1` vs legacy `improve-page-v2`.

Scenario catalog entry **S9b** (`prompt_source: ImprovePageTwoStep`) is wired in `bin/run-scenario.php` the same way:

```bash
wp eval-file wp-content/plugins/agent-wordpress-terminal/bin/run-scenario.php S9b
```

### Scenario tests via terminal (preferred for M3 path checks)

Open-ended Improve is a weak test of prepare→replace. Prefer **task-shaped scenarios** that still use the **terminal AgentRuntime** (not the review-queue UI):

Catalog: `evaluation/scenarios.json`

```bash
# Inside WordPress (e.g. docker compose exec wordpress … --allow-root)
# Note: pass flags as bare tokens — WP-CLI swallows unknown --flags on eval-file.

wp eval-file wp-content/plugins/agent-wordpress-terminal/bin/run-scenario.php list

wp eval-file wp-content/plugins/agent-wordpress-terminal/bin/run-scenario.php S1-middle-swap
wp eval-file wp-content/plugins/agent-wordpress-terminal/bin/run-scenario.php S2 post=864
wp eval-file wp-content/plugins/agent-wordpress-terminal/bin/run-scenario.php S5-copy-only no-apply

# Short ids work: S1, S2, S4, S5, S9
```

Each run:

- Creates a **fresh focused session** and sends the scenario prompt as a chat message (same path as the agent terminal)
- Optionally auto-applies review-safe staged actions (omit with `--no-apply` to inspect proposals only)
- Writes `tmp-queue-runs/awpt-scenario-{id}-post-{post_id}.json` (+ `.raw.json`)
- Records soft expected path/tools hits (observation only — **not** hard locks)

Suggested first pack: **S1** (middle swap), **S2** (FAQ), **S4** (insert CTA), **S5** (copy-only control), **S9** (Improve baseline thermometer).

Do **not** treat freehand on S9 as an M3 capability failure. Score S1–S4 on prepare/replace/insert attempt and collateral when materialized.

### Improve / queue audit (M0 + M5 scorecard)

`bin/queue-improve-one.php <post_id>` defaults to a **fresh session** (pass `--reuse-session` for production-like focus reuse). Still useful as an open-ended Improve thermometer. Summaries under `tmp-queue-runs/` include:

- `meta.classifier_version` and `server_materialized` derived from operation + composition manifest (not `pattern_name` alone)
- `path_used` labels such as `pattern_replace`, `pattern_insert`, `pattern_provenance_freehand`
- `raw_trace_path` pointing at the full tool/action dump for classifier replay
- `top_level_section_count` and a per-run `scorecard` object (prepare/replace/freehand flags, eligibility)

Task `replace-section` in the parity corpus maps to the `prepare-pattern-change` → `propose-pattern-replace` path.

#### Cohort scorecard (M5)

Aggregate existing run summaries (no invented targets — rates always include denominators):

```bash
# Offline aggregate of tmp-queue-runs/awpt-queue-*.json
php bin/cohort-scorecard.php --label=post-m3 tmp-queue-runs/

# Live Improve for listed posts, then scorecard
bin/queue-improve-cohort.sh --label=post-m3 848 853 858 841 850

# Scorecard only
bin/queue-improve-cohort.sh --scorecard-only --label=existing tmp-queue-runs/
```

Output: `tmp-queue-runs/cohort-<label>-summary.json` with:

- `n` / `n_structural_eligible`
- `path_counts`
- `structural.rates.*` — `server_materialized`, `prepare_change_success`, `propose_replace_success`, `freehand_provenance` as `{count, denominator, rate}`
- per-run rows for audit tables

**Interpret carefully:** freehand share among structural-eligible Improve tasks is the adoption signal; do **not** set a fixed “70% materialize” target without a clean post-M3 cohort and denominators.

**Living status:** see `ollie_gaps.md` (M1–M3 capabilities shipped; M2b rolled back; M5 scorecard available; M4 gated). Historical: `cohort-m1-audit*`, `cohort-m2-audit*`, `cohort-smoke-rollback*`.

The CivicPress repository also ships a deterministic catalog smoke test (`npm run awpt:evaluate`). It catches metadata and ranking regressions without an AI provider; it is a prerequisite for, not a replacement for, the cross-system exercise.
