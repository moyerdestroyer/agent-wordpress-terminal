#!/usr/bin/env bash
# Run Improve queue audits for one or more posts, then write an M5 cohort scorecard.
#
# Usage:
#   bin/queue-improve-cohort.sh 848 853 858
#   bin/queue-improve-cohort.sh --class=structural_replace --label=post-change 848 853 858
#   bin/queue-improve-cohort.sh --scorecard-only tmp-queue-runs/
#
# Requires `wp` on PATH for live runs. Scorecard-only mode needs only PHP.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LABEL=""
SCENARIO_CLASS="unclassified"
SCORECARD_ONLY=0
POST_IDS=()
EXTRA_PATHS=()

usage() {
  cat <<'EOF'
Usage: bin/queue-improve-cohort.sh [--label=NAME] [--class=CLASS] [--scorecard-only] <post_id...> [paths...]

  --label=NAME       Label embedded in cohort summary filename/metadata
  --class=CLASS      structural_replace, additive_insert, surgical_copy, or no_change
  --scorecard-only   Skip wp runs; only aggregate existing awpt-queue-*.json
  post_id            Numeric post IDs to improve via bin/queue-improve-one.php
  paths              Optional dirs/files/globs for scorecard inputs
EOF
}

for arg in "$@"; do
  case "$arg" in
    --help|-h)
      usage
      exit 0
      ;;
    --scorecard-only)
      SCORECARD_ONLY=1
      ;;
    --label=*)
      LABEL="${arg#--label=}"
      ;;
    --class=*)
      SCENARIO_CLASS="${arg#--class=}"
      ;;
    ''|*[!0-9]*)
      EXTRA_PATHS+=("$arg")
      ;;
    *)
      POST_IDS+=("$arg")
      ;;
  esac
done

if [[ "$SCORECARD_ONLY" -eq 0 ]]; then
  if [[ ${#POST_IDS[@]} -eq 0 ]]; then
    echo "Provide post IDs or --scorecard-only" >&2
    usage >&2
    exit 1
  fi
  if ! command -v wp >/dev/null 2>&1; then
    echo "wp CLI not found; use --scorecard-only to aggregate existing JSON" >&2
    exit 1
  fi
  for id in "${POST_IDS[@]}"; do
    echo "=== queue improve post ${id} ==="
    wp eval-file "$ROOT/bin/queue-improve-one.php" "$id" "class=${SCENARIO_CLASS}"
  done
fi

SCORE_ARGS=()
if [[ -n "$LABEL" ]]; then
  SCORE_ARGS+=("--label=${LABEL}")
fi

if [[ ${#EXTRA_PATHS[@]} -gt 0 ]]; then
  SCORE_ARGS+=("${EXTRA_PATHS[@]}")
else
  SCORE_ARGS+=("$ROOT/tmp-queue-runs")
fi

php "$ROOT/bin/cohort-scorecard.php" "${SCORE_ARGS[@]}"
