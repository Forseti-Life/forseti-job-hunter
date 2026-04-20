#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

JOBHUNTER_UID="${JOBHUNTER_UID:-1}"
JOBHUNTER_LIMIT="${JOBHUNTER_LIMIT:-10}"
JOBHUNTER_ROUNDS="${JOBHUNTER_ROUNDS:-3}"
JOBHUNTER_QUEUE_TIME_LIMIT="${JOBHUNTER_QUEUE_TIME_LIMIT:-180}"
JOBHUNTER_RETRY_MANUAL="${JOBHUNTER_RETRY_MANUAL:-1}"

ARGS=(
  "--uid=${JOBHUNTER_UID}"
  "--limit=${JOBHUNTER_LIMIT}"
  "--rounds=${JOBHUNTER_ROUNDS}"
  "--queue-time-limit=${JOBHUNTER_QUEUE_TIME_LIMIT}"
)

if [[ "$JOBHUNTER_RETRY_MANUAL" == "1" ]]; then
  ARGS+=("--retry-manual")
else
  ARGS+=("--no-retry-manual")
fi

LOCK_FILE="/tmp/jh_cio_auto_apply.lock"
LOG_FILE="/var/log/drupal/jh_cio_auto_apply.log"
LOG_DIR="$(dirname "$LOG_FILE")"
if ! mkdir -p "$LOG_DIR" 2>/dev/null; then
  LOG_FILE="/tmp/jh_cio_auto_apply.log"
fi

flock -n "$LOCK_FILE" "$PHP_BIN" "$SITE_ROOT/scripts/jobhunter-cio-auto-apply.php" "${ARGS[@]}" >> "$LOG_FILE" 2>&1
