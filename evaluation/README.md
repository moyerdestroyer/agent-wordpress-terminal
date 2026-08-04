# AWPT comparison evaluations

`ollie-parity-tasks.json` is a shared task corpus for evaluating AWPT + CivicPress against Ollie Pro + its skill. Run each prompt from a clean, equivalent WordPress fixture and record tool traces, the first proposal, validation findings, correction turns, and the human-facing approval state.

Do not score a fast direct write as safer than an approval-gated proposal. Measure task success and approval clarity separately. A pattern-selection success requires a suitable pattern in the top three candidates; a composition success requires preserved dynamic blocks, registered design tokens, valid block grammar, and no unresolved blocking findings.

The CivicPress repository also ships a deterministic catalog smoke test (`npm run awpt:evaluate`). It catches metadata and ranking regressions without an AI provider; it is a prerequisite for, not a replacement for, the cross-system exercise.
