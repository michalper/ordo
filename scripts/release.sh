#!/usr/bin/env bash
# Cuts a release: tags the current main commit and publishes a matching GitHub
# Release, using that version's own section of docs/CHANGELOG.md as the
# release notes. Versioning is via git tags only (see AGENTS.md) - this script
# exists so tagging and publishing the GitHub Release always happen together;
# doing only the former (as happened for 1.6.0) leaves the tag with no visible
# entry on https://github.com/michalper/ordo/releases.
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
