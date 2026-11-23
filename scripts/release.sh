#!/usr/bin/env bash
# Cuts a release: tags the current main commit and publishes a matching GitHub
# Release, using that version's own section of docs/CHANGELOG.md as the
# release notes. Versioning is via git tags only (see AGENTS.md) - this script
# exists so tagging and publishing the GitHub Release always happen together;
# doing only the former (as happened for 1.6.0) leaves the tag with no visible
# entry on https://github.com/michalper/ordo/releases.
#
# Refuses to run unless local main matches origin/main (never tag a commit
# nobody else can see) and every CI check run on that commit is green (never
# publish a release off a broken build) - both gaps found while cutting 1.11.1.
#
# Usage: scripts/release.sh <version>   (e.g. scripts/release.sh 1.7.0)
set -euo pipefail

version="${1:?Usage: scripts/release.sh <version>, e.g. scripts/release.sh 1.7.0}"
changelog="docs/CHANGELOG.md"

branch="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$branch" != "main" ]]; then
  echo "Must be on main (currently on $branch)." >&2
  exit 1
fi
if [[ -n "$(git status --porcelain)" ]]; then
  echo "Working tree is not clean." >&2
  exit 1
fi

git fetch origin main --quiet
local_sha="$(git rev-parse HEAD)"
remote_sha="$(git rev-parse origin/main)"
if [[ "$local_sha" != "$remote_sha" ]]; then
  echo "main is out of sync with origin/main (local $local_sha, origin $remote_sha) - pull or push first." >&2
  exit 1
fi

# --paginate: this repo has 39+ check-runs per commit, past the API's 30-per-page default - a
# plain (unpaginated) call here silently missed up to 9 of them, which could let a release ship
# despite one of the uncounted checks having actually failed (found while building release.yml's
# own automated version of this same check).
check_runs="$(gh api "repos/{owner}/{repo}/commits/$local_sha/check-runs" --paginate --jq '.check_runs')"
total_checks="$(jq -s 'add | length' <<<"$check_runs")"
if [[ "$total_checks" -eq 0 ]]; then
  echo "No CI check runs found for $local_sha yet - wait for CI to start." >&2
  exit 1
fi
not_green="$(jq -s 'add | [.[] | select(.status != "completed" or (.conclusion != "success" and .conclusion != "skipped" and .conclusion != "neutral"))] | length' <<<"$check_runs")"
if [[ "$not_green" -gt 0 ]]; then
  echo "CI is not green for $local_sha ($not_green of $total_checks check(s) not successful) - see https://github.com/michalper/ordo/commit/$local_sha/checks" >&2
  exit 1
fi

notes_file="$(mktemp)"
trap 'rm -f "$notes_file"' EXIT

awk -v ver="$version" '
  $0 ~ "^## \\[" ver "\\]" { flag=1; next }
  flag && /^## \[/ { exit }
  flag { print }
' "$changelog" > "$notes_file"

if [[ ! -s "$notes_file" ]]; then
  echo "No '## [$version]' section found in $changelog - add it first." >&2
  exit 1
fi

git tag -a "$version" -m "$version"
git push origin "$version"
gh release create "$version" --title "$version" --notes-file "$notes_file"

echo "Tagged and published $version:"
echo "https://github.com/michalper/ordo/releases/tag/$version"
