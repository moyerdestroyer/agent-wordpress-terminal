#!/usr/bin/env bash
# Run a notes-varying Improve cohort from a JSON matrix, then scorecard + audit.
#
# Usage:
#   bin/queue-improve-matrix.sh tmp-queue-runs/autonomy-matrix-v1.json
#   bin/queue-improve-matrix.sh --label=autonomy-v1 tmp-queue-runs/autonomy-matrix-v1.json
#
# Each case: fresh evaluate→act, auto-apply review-safe ops, content audit, rollback.
# Requires docker wp container with plugin mounted (totem-import-testing-wp).

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LABEL="autonomy-$(date -u +%Y%m%d%H%M)"
MATRIX=""
WP_CONTAINER="${WP_CONTAINER:-totem-import-testing-wp}"
PLUGIN_IN_CONTAINER="/var/www/html/wp-content/plugins/agent-wordpress-terminal"

for arg in "$@"; do
  case "$arg" in
    --label=*) LABEL="${arg#--label=}" ;;
    --help|-h)
      sed -n '2,12p' "$0"
      exit 0
      ;;
    *) MATRIX="$arg" ;;
  esac
done

if [[ -z "$MATRIX" || ! -f "$MATRIX" ]]; then
  echo "Matrix JSON required" >&2
  exit 1
fi

OUT_DIR="$ROOT/tmp-queue-runs/${LABEL}"
mkdir -p "$OUT_DIR"
cp "$MATRIX" "$OUT_DIR/matrix.json"

# Copy matrix into container-visible path
docker cp "$OUT_DIR/matrix.json" "$WP_CONTAINER:$PLUGIN_IN_CONTAINER/tmp-queue-runs/${LABEL}-matrix.json"

echo "=== autonomy cohort label=${LABEL} ==="
python3 - <<'PY' "$OUT_DIR/matrix.json" > "$OUT_DIR/cases.tsv"
import json, sys
cases = json.load(open(sys.argv[1]))
for i, c in enumerate(cases):
    notes = c.get("notes", "").replace("\t", " ").replace("\n", " ")
    print(f"{i}\t{c['post_id']}\t{c.get('class','unclassified')}\t{notes}")
PY

PASS=0
FAIL=0
INDEX=0
while IFS=$'\t' read -r idx post_id class notes; do
  INDEX=$((INDEX + 1))
  echo ""
  echo "=== [$INDEX] post=${post_id} class=${class} ==="
  echo "notes=${notes}"
  # notes must stay one argv token
  set +e
  docker exec "$WP_CONTAINER" wp --allow-root eval-file \
    "$PLUGIN_IN_CONTAINER/bin/queue-improve-one.php" \
    "$post_id" \
    "class=${class}" \
    "notes=${notes}" \
    rollback \
    > "$OUT_DIR/run-${post_id}.log" 2>&1
  RC=$?
  set -e
  echo "exit=${RC}"
  tail -20 "$OUT_DIR/run-${post_id}.log" || true
  # Copy newest summary for this post into OUT_DIR
  SUMMARY=$(docker exec "$WP_CONTAINER" bash -lc \
    "ls -1t $PLUGIN_IN_CONTAINER/tmp-queue-runs/awpt-queue-${post_id}-*.json 2>/dev/null | head -1")
  if [[ -n "$SUMMARY" ]]; then
    docker cp "$WP_CONTAINER:$SUMMARY" "$OUT_DIR/" || true
    BASE=$(basename "$SUMMARY")
    # lightweight pass/fail from summary
    python3 - <<PY
import json
from pathlib import Path
p = Path("$OUT_DIR") / "$BASE"
s = json.loads(p.read_text())
actions = s.get("actions") or []
applied = [a for a in actions if a.get("applied") or a.get("status") in ("applied", "rolled_back")]
filler = any((a.get("content_audit") or {}).get("instructional_filler") for a in actions)
err = s.get("error") or {}
tools = s.get("tools") or []
failed_tools = [t for t in tools if ":error" in t or t.endswith(":failed")]
ok = (not err) and bool(applied) and not filler
print(f"audit post={s.get('post_id')} path={s.get('path_used')} applied={len(applied)} filler={filler} failed_tools={len(failed_tools)} OK={ok}")
open("$OUT_DIR/run-${post_id}.verdict", "w").write("pass" if ok else "fail")
PY
    if [[ -f "$OUT_DIR/run-${post_id}.verdict" ]] && grep -q pass "$OUT_DIR/run-${post_id}.verdict"; then
      PASS=$((PASS + 1))
    else
      FAIL=$((FAIL + 1))
    fi
  else
    echo "audit missing summary"
    FAIL=$((FAIL + 1))
  fi
done < "$OUT_DIR/cases.tsv"

# Aggregate scorecard from copied summaries
php "$ROOT/bin/cohort-scorecard.php" --label="$LABEL" --out="$OUT_DIR/cohort-summary.json" "$OUT_DIR" || true
php "$ROOT/bin/queue-improve-report.php" --site="${AWPT_SITE_URL:-http://import-testing.totem:8080}" "$OUT_DIR" || true

echo ""
echo "=== DONE pass=${PASS} fail=${FAIL} out=${OUT_DIR} ==="
echo "Open: ${OUT_DIR}/REPORT.html"
