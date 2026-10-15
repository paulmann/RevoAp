#!/bin/bash
# Run every standalone test script under a given PHP binary with E_ALL on.
#
# Pass/fail is decided by TWO signals, because these scripts are hand-rolled
# and their exit codes are not uniform:
#   * the process exit status, and
#   * explicit failure markers in the output (a line whose first non-space
#     token is FAIL/ERROR, or "N failed" with N > 0).
# A summary reading "0 failed" is a PASS; the previous version of this
# harness grepped for the bare word "failed" and reported 72 false failures.
PHP="${PHP:-"$ARENA_WORKSPACE"/tools/php85}"
OUT="${OUT:-/tmp/testrun.txt}"
SHOW_DEPR="${SHOW_DEPR:-1}"
: > "$OUT"
cd "$(dirname "$0")/.." || exit 1
pass=0; fail=0; failed=()
for t in tests/*Test.php; do
  name=$(basename "$t")
  res=$("$PHP" -d error_reporting=E_ALL -d display_errors=1 "$t" 2>&1)
  rc=$?
  reasons=""
  [ "$rc" -ne 0 ] && reasons="exit=$rc"
  if grep -qE '^[[:space:]]*(FAIL|ERROR)[[:space:]]' <<<"$res"; then
    reasons="$reasons markers=$(grep -cE '^[[:space:]]*(FAIL|ERROR)[[:space:]]' <<<"$res")"
  fi
  # "passed: 44   failed: 3" / "ALL OK: 24 passed, 0 failed"
  badcount=$(grep -oE 'failed[: ]+[0-9]+' <<<"$res" | grep -oE '[0-9]+$' | paste -sd+ - | bc 2>/dev/null)
  [ -n "$badcount" ] && [ "$badcount" != "0" ] && reasons="$reasons failedcount=$badcount"
  if grep -qE 'Fatal error|Uncaught' <<<"$res"; then reasons="$reasons fatal"; fi
  if [ -n "$reasons" ]; then
    fail=$((fail+1)); failed+=("$name")
    { echo "FAIL  $name  ($reasons)"
      grep -E '^[[:space:]]*(FAIL|ERROR)[[:space:]]' <<<"$res" | head -6
      grep -E 'Fatal error|Uncaught' <<<"$res" | head -3; } >> "$OUT"
  else
    pass=$((pass+1)); echo "PASS  $name" >> "$OUT"
  fi
  if [ "$SHOW_DEPR" = "1" ]; then
    d=$(grep -cE 'Deprecated:|Warning:' <<<"$res")
    [ "$d" -gt 0 ] && echo "  note: $name emitted $d deprecation(s)/warning(s)" >> "$OUT"
  fi
done
echo "==================================" >> "$OUT"
echo "SUMMARY pass=$pass fail=$fail" >> "$OUT"
printf 'FAILED: %s\n' "${failed[*]}" >> "$OUT"
