#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
PYTHON_BIN="${PYTHON_BIN:-python3}"

JOBHUNTER_UID="${JOBHUNTER_UID:-1}"
JOBHUNTER_LIMIT="${JOBHUNTER_LIMIT:-10}"
JOBHUNTER_ROUNDS="${JOBHUNTER_ROUNDS:-3}"
JOBHUNTER_QUEUE_TIME_LIMIT="${JOBHUNTER_QUEUE_TIME_LIMIT:-180}"
JOBHUNTER_RETRY_MANUAL="${JOBHUNTER_RETRY_MANUAL:-1}"

INTERVAL_SECONDS="${INTERVAL_SECONDS:-300}"
MAX_RUNS="${MAX_RUNS:-0}"
TARGET_SUBMITTED_INCREASE="${TARGET_SUBMITTED_INCREASE:-0}"

LOG_FILE="${LOG_FILE:-/var/log/drupal/jh_cio_growth_loop.log}"
if ! mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null; then
  LOG_FILE="/tmp/jh_cio_growth_loop.log"
fi

parse_submitted_total() {
  local json_input="$1"
  JH_JSON_PAYLOAD="$json_input" "$PYTHON_BIN" -c 'import json, os
raw = os.environ.get("JH_JSON_PAYLOAD", "").strip()
try:
    payload = json.loads(raw)
    print(int(payload.get("submitted_total_for_user", -1)))
except Exception:
    print(-1)
'
}

run_once() {
  local retry_arg="--retry-manual"
  if [[ "$JOBHUNTER_RETRY_MANUAL" != "1" ]]; then
    retry_arg="--no-retry-manual"
  fi

  "$PHP_BIN" "$SITE_ROOT/scripts/jobhunter-cio-auto-apply.php" \
    "--uid=${JOBHUNTER_UID}" \
    "--limit=${JOBHUNTER_LIMIT}" \
    "--rounds=${JOBHUNTER_ROUNDS}" \
    "--queue-time-limit=${JOBHUNTER_QUEUE_TIME_LIMIT}" \
    "$retry_arg"
}

run_count=0
baseline_submitted=""

while true; do
  run_count=$((run_count + 1))
  now="$(date '+%Y-%m-%d %H:%M:%S')"

  set +e
  output="$(run_once 2>&1)"
  rc=$?
  set -e

  submitted_total="$(parse_submitted_total "$output")"

  if [[ -z "$baseline_submitted" && "$submitted_total" -ge 0 ]]; then
    baseline_submitted="$submitted_total"
  fi

  increase="N/A"
  if [[ -n "$baseline_submitted" && "$submitted_total" -ge 0 ]]; then
    increase=$((submitted_total - baseline_submitted))
  fi

  {
    echo "[$now] run=$run_count rc=$rc submitted_total=$submitted_total increase=$increase"
    echo "$output"
    echo "---"
  } | tee -a "$LOG_FILE"

  if [[ "$rc" -ne 0 ]]; then
    echo "[$now] warning: run returned non-zero rc=$rc" | tee -a "$LOG_FILE"
  fi

  if [[ "$TARGET_SUBMITTED_INCREASE" -gt 0 && "$increase" != "N/A" && "$increase" -ge "$TARGET_SUBMITTED_INCREASE" ]]; then
    echo "[$now] target reached: increase=$increase" | tee -a "$LOG_FILE"
    break
  fi

  if [[ "$MAX_RUNS" -gt 0 && "$run_count" -ge "$MAX_RUNS" ]]; then
    echo "[$now] max runs reached: $MAX_RUNS" | tee -a "$LOG_FILE"
    break
  fi

  sleep "$INTERVAL_SECONDS"
done
