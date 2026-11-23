#!/usr/bin/env bash
# Extracts one dated section (everything between its own "## [X.Y.Z]" heading and the next
# "## [" heading) out of a Keep-a-Changelog-shaped changelog file. Shared by scripts/release.sh
# and .github/workflows/release.yml's "release" job - previously duplicated inline in both
# places (and a third time in release.yml itself).
#
# Usage: scripts/changelog-section.sh <version> [changelog-path]
# changelog-path defaults to docs/CHANGELOG.md. Prints the section's body to stdout; prints
# nothing if that version's heading isn't found.
set -euo pipefail

version="${1:?Usage: scripts/changelog-section.sh <version> [changelog-path]}"
changelog="${2:-docs/CHANGELOG.md}"

# $version is interpolated into an awk regex below - reject anything that isn't a plain X.Y.Z so a
# version containing a regex metacharacter (+, *, (, [, ...) can't change what the pattern matches.
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "scripts/changelog-section.sh: '$version' isn't a plain X.Y.Z version - refusing." >&2
  exit 1
fi

awk -v ver="$version" '
  $0 ~ "^## \\[" ver "\\]" { flag=1; next }
  flag && /^## \[/ { exit }
  flag { print }
' "$changelog"
