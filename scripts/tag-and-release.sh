#!/usr/bin/env bash
# Tags <version> at HEAD and publishes the matching GitHub Release from <notes-file>. Shared by
# scripts/release.sh (manual) and .github/workflows/release.yml's "release" job (automated) -
# previously duplicated inline in both places.
#
# Usage: scripts/tag-and-release.sh <version> <notes-file>
set -euo pipefail

version="${1:?Usage: scripts/tag-and-release.sh <version> <notes-file>}"
notes_file="${2:?Usage: scripts/tag-and-release.sh <version> <notes-file>}"

git tag -a "$version" -m "$version"
git push origin "$version"
gh release create "$version" --title "$version" --notes-file "$notes_file"
