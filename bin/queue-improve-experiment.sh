#!/usr/bin/env bash
# Broad Improve experiment: arms (provider+model) × prompts × paths × N reps.
#
# Usage:
#   bin/queue-improve-experiment.sh tmp-queue-runs/experiment-wave1.json
#   bin/queue-improve-experiment.sh --reps=3 tmp-queue-runs/experiment-wave1.json

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_CONTAINER="${WP_CONTAINER:-totem-import-testing-wp}"
PLUGIN="/var/www/html/wp-content/plugins/agent-wordpress-terminal"
REPS_OVERRIDE=""
MATRIX=""
JOB_TIMEOUT="${AWPT_EXPERIMENT_TIMEOUT:-300}"

for arg in "$@"; do
  case "$arg" in
    --reps=*) REPS_OVERRIDE="${arg#--reps=}" ;;
    --help|-h) sed -n '2,10p' "$0"; exit 0 ;;
    *) MATRIX="$arg" ;;
  esac
done

[[ -n "$MATRIX" && -f "$MATRIX" ]] || { echo "Matrix JSON required" >&2; exit 1; }

LABEL=$(python3 -c "import json,sys; print(json.load(open(sys.argv[1])).get('label','experiment'))" "$MATRIX")
OUT="$ROOT/tmp-queue-runs/$LABEL"
mkdir -p "$OUT"
cp "$MATRIX" "$OUT/matrix.json"

python3 - <<'PY' "$MATRIX" "$REPS_OVERRIDE" > "$OUT/jobs.tsv"
import json, sys
m = json.load(open(sys.argv[1]))
reps = int(sys.argv[2]) if sys.argv[2] else int(m.get("reps", 5))
arms = m.get("arms")
if not arms:
    arms = [{"provider": "openrouter", "model": model} for model in (m.get("models") or ["deepseek/deepseek-v4-pro"])]
for cell in m.get("cells") or []:
    prompts = cell.get("prompts") or ["default"]
    for arm in arms:
        provider = arm.get("provider") or "openrouter"
        model = arm.get("model") or ""
        for prompt in prompts:
            for rep in range(1, reps + 1):
                notes = (cell.get("notes") or "").replace("\t", " ").replace("\n", " ")
                print("\t".join([
                    str(cell.get("path", "")),
                    str(cell["post_id"]),
                    str(cell.get("class", "unclassified")),
                    provider,
                    model,
                    prompt,
                    str(rep),
                    notes,
                ]))
PY

TOTAL=$(wc -l < "$OUT/jobs.tsv" | tr -d ' ')
echo "=== experiment label=$LABEL jobs=$TOTAL timeout=${JOB_TIMEOUT}s out=$OUT ==="

annotate_cell() {
  local dest="$1" path="$2" provider="$3" model="$4" prompt="$5" rep="$6" job="$7" label="$8"
  python3 - <<PY
import json
from pathlib import Path
p = Path("$dest")
s = json.loads(p.read_text())
cache = s.get("cache") or (s.get("meta") or {}).get("cache") or {}
s["experiment"] = {
    "label": "$label",
    "path": "$path",
    "provider": "$provider",
    "model": "$model",
    "prompt": "$prompt",
    "rep": int("$rep"),
    "job": int("$job"),
}
actions = s.get("actions") or []
filler = dup = applied = False
quality_explicit = None
pattern_name = ""
path_used = str(s.get("path_used") or "")
for a in actions:
    audit = (a or {}).get("content_audit") or {}
    if a.get("applied") or a.get("status") in ("applied", "rolled_back"):
        applied = True
    if audit.get("instructional_filler"):
        filler = True
    if audit.get("duplication_suspect") or int(audit.get("duplication_repeats") or 0) >= 4:
        dup = True
    if "quality_ok" in audit:
        quality_explicit = bool(audit.get("quality_ok"))
    if a.get("pattern_name"):
        pattern_name = str(a.get("pattern_name") or "")
    elif (a.get("payload") or {}).get("pattern_name"):
        pattern_name = str((a.get("payload") or {}).get("pattern_name") or "")
outcome = (s.get("turn_outcome") or {}).get("status")
quality_ok = bool(applied and not filler and not dup and outcome != "failed")
if quality_explicit is False:
    quality_ok = False
if "$path" == "docs":
    docs_ok = (
        "pattern_replace" in path_used or "pattern_insert" in path_used
    ) and ("layout-page" in pattern_name or "documentation" in pattern_name)
    if not docs_ok:
        quality_ok = False
prompt_tokens = int(cache.get("prompt_tokens") or 0)
cached_tokens = int(cache.get("cached_tokens") or 0)
hit = cache.get("cache_hit_rate")
if hit is None and prompt_tokens > 0:
    hit = round(100 * cached_tokens / prompt_tokens, 1)
s["experiment_verdict"] = {
    "applied": applied,
    "filler": filler,
    "duplication": dup,
    "quality_ok": quality_ok,
    "outcome": outcome,
    "path_used": path_used,
    "pattern_name": pattern_name,
    "docs_layout": ("layout-page" in pattern_name),
    "prompt_tokens": prompt_tokens,
    "cached_tokens": cached_tokens,
    "cache_hit_rate": hit,
    "provider_rounds": int(cache.get("rounds") or 0),
}
p.write_text(json.dumps(s, indent=2))
v = s["experiment_verdict"]
print(f"verdict quality_ok={v['quality_ok']} applied={v['applied']} cache_hit={v['cache_hit_rate']} path_used={v['path_used']}")
PY
}

kill_orphans() {
  docker exec "$WP_CONTAINER" bash -lc 'pkill -9 -f queue-improve-one.php 2>/dev/null || true' >/dev/null 2>&1 || true
  docker exec "$WP_CONTAINER" wp --allow-root eval \
    'update_option("awpt_openrouter_model","deepseek/deepseek-v4-pro",false); update_option("awpt_provider","openrouter",false);' \
    >/dev/null 2>&1 || true
}

JOB=0
while IFS=$'\t' read -r path post_id class provider model prompt rep notes; do
  JOB=$((JOB + 1))
  SAFE_MODEL=$(echo "${provider}_${model}" | tr '/:' '__')
  EXISTING=$(ls -1 "$OUT"/cell-"${path}"-p"${post_id}"-"${SAFE_MODEL}"-"${prompt}"-r"${rep}"-*.json 2>/dev/null | head -1 || true)
  if [[ -n "$EXISTING" ]]; then
    echo "=== [$JOB/$TOTAL] SKIP $(basename "$EXISTING") ==="
    continue
  fi

  echo ""
  echo "=== [$JOB/$TOTAL] path=$path post=$post_id provider=$provider model=$model prompt=$prompt rep=$rep ==="
  LOG="$OUT/run-${path}-${post_id}-${prompt}-r${rep}-${SAFE_MODEL}.log"
  MARKER="$OUT/.marker-${JOB}"
  date > "$MARKER"
  : > "$LOG"

  EXTRA_ARGS=( "provider=${provider}" "prompt=${prompt}" "notes=${notes}" )
  if [[ -n "$model" ]]; then
    EXTRA_ARGS=( "model=${model}" "${EXTRA_ARGS[@]}" )
  fi

  set +e
  timeout --signal=TERM --kill-after=20s "${JOB_TIMEOUT}s" \
    docker exec "$WP_CONTAINER" wp --allow-root eval-file \
      "$PLUGIN/bin/queue-improve-one.php" \
      "$post_id" \
      "class=${class}" \
      "${EXTRA_ARGS[@]}" \
      rollback \
      >> "$LOG" 2>&1
  RC=$?
  set -e

  if [[ "$RC" -eq 124 || "$RC" -eq 137 ]]; then
    echo "exit=$RC TIMEOUT/KILL"
    echo "TIMEOUT after ${JOB_TIMEOUT}s" >> "$LOG"
    kill_orphans
    continue
  fi
  echo "exit=$RC"

  SUMMARY=$(find "$ROOT/tmp-queue-runs" -maxdepth 1 \
    -name "awpt-queue-${post_id}-*.json" ! -name '*.raw.json' \
    -newer "$MARKER" 2>/dev/null | sort | tail -1 || true)
  if [[ -z "$SUMMARY" ]]; then
    echo "audit missing new summary for this job"
    continue
  fi
  BASE=$(basename "$SUMMARY")
  DEST="$OUT/cell-${path}-p${post_id}-${SAFE_MODEL}-${prompt}-r${rep}-${BASE}"
  cp "$SUMMARY" "$DEST"
  annotate_cell "$DEST" "$path" "$provider" "$model" "$prompt" "$rep" "$JOB" "$LABEL"
done < "$OUT/jobs.tsv"

# Ensure schema has cached_tokens before report (idempotent upgrade on host mount)
docker exec "$WP_CONTAINER" wp --allow-root eval \
  'AWPT\Database\Installer::maybe_upgrade(); echo "schema=".get_option("awpt_schema_version")."\n";' \
  >/dev/null 2>&1 || true

php "$ROOT/bin/queue-improve-experiment-report.php" "$OUT" || true
echo "=== DONE jobs=$TOTAL report=$OUT/EXPERIMENT.html ==="
