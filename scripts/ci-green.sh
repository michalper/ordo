#!/usr/bin/env bash
# Reports whether every CI check-run on a commit is green. Shared by scripts/release.sh (manual
# release) and .github/workflows/release.yml's own "gate" step (automated release) - previously
# duplicated inline in both places. --paginate matters: this repo has 39+ check-runs per commit,
# past the GitHub API's 30-per-page default, so a plain (unpaginated) call silently misses up to 9
# of them (found while building release.yml's own version of this check).
#
# Usage: scripts/ci-green.sh <sha>
# Exits 0 silently if every check-run for <sha> is completed and success/skipped/neutral.
# Exits 1 and prints a diagnostic to stderr otherwise, including when no check-runs exist yet.
set -euo pipefail

sha="${1:?Usage: scripts/ci-green.sh <sha>}"

check_runs="$(gh api "repos/{owner}/{repo}/commits/$sha/check-runs" --paginate --jq '.check_runs')"
counts="$(jq -s 'add | {total: length, not_green: ([.[] | select(.status != "completed" or (.conclusion != "success" and .conclusion != "skipped" and .conclusion != "neutral"))] | length)}' <<<"$check_runs")"
total="$(jq -r '.total' <<<"$counts")"
not_green="$(jq -r '.not_green' <<<"$counts")"

if [[ "$total" -eq 0 || "$not_green" -gt 0 ]]; then
  echo "Not every check-run is green for $sha ($not_green of $total not successful)." >&2
  exit 1
fi
