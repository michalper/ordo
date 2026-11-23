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
# Also now a no-op (not an error) if release.yml already auto-cut this version - see that
# workflow, which does the same thing on its own once a CHANGELOG-update PR merges to main.
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

if scripts/is-tagged.sh "$version"; then
  echo "$version is already tagged - nothing to do (release.yml likely already cut this release automatically)."
  exit 0
fi

if ! scripts/ci-green.sh "$local_sha"; then
  echo "CI is not green for $local_sha - see https://github.com/michalper/ordo/commit/$local_sha/checks" >&2
  exit 1
fi

notes_file="$(mktemp)"
trap 'rm -f "$notes_file"' EXIT

scripts/changelog-section.sh "$version" "$changelog" > "$notes_file"

if [[ ! -s "$notes_file" ]]; then
  echo "No '## [$version]' section found in $changelog - add it first." >&2
  exit 1
fi

scripts/tag-and-release.sh "$version" "$notes_file"

echo "Tagged and published $version:"
echo "https://github.com/michalper/ordo/releases/tag/$version"
