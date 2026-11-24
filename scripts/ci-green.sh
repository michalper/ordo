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
#
# Excludes check-runs named "release"/"update-site" (this repo's own Auto-release workflow,
# release.yml) - a self-referential trap found empirically cutting 1.12.0: the "gate" step that
# calls this script is itself a step inside the "release" job, so that job's own check-run is
# still status=in_progress (not "completed") at the exact moment this runs, on every single
# invocation, making "every check green" permanently unreachable without this exclusion.
#
# The actual check-run name GitHub reports for a reusable-workflow job is "<calling job> / <called
# job>" - every one of the 4 callers names its calling job trigger-release (see ci.yml's own
# comment: "One of 4 identical calls"), so the real names are "trigger-release / release" and
# "trigger-release / update-site", never the bare "release"/"update-site" this used to match
# against. That meant the exclusion above never actually matched anything - found empirically
# cutting 1.13.0, which sat in CHANGELOG.md for hours, fully CI-green, and never auto-released:
# every firing's own "trigger-release / release" check-run was itself still in_progress at the
# exact moment it ran this gate, so "every other check-run is green" was permanently unreachable,
# the same trap this exclusion was meant to close, just with the wrong name. Matching by suffix
# instead of an exact bare name keeps this working regardless of which of the 4 workflows' own
# calling-job name fires it.
set -euo pipefail

sha="${1:?Usage: scripts/ci-green.sh <sha>}"

check_runs="$(gh api "repos/{owner}/{repo}/commits/$sha/check-runs" --paginate --jq '.check_runs')"
counts="$(jq -s 'add | map(select((.name | endswith("/ release") or endswith("/ update-site")) | not)) | {total: length, not_green: ([.[] | select(.status != "completed" or (.conclusion != "success" and .conclusion != "skipped" and .conclusion != "neutral"))] | length)}' <<<"$check_runs")"
total="$(jq -r '.total' <<<"$counts")"
not_green="$(jq -r '.not_green' <<<"$counts")"

if [[ "$total" -eq 0 || "$not_green" -gt 0 ]]; then
  echo "Not every check-run is green for $sha ($not_green of $total not successful)." >&2
  exit 1
fi
