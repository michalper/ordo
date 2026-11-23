#!/usr/bin/env bash
# Exits 0 if <version> is already tagged on origin, exits 1 otherwise. Shared by scripts/release.sh
# and .github/workflows/release.yml's "check" step - previously duplicated inline in both places.
#
# Usage: scripts/is-tagged.sh <version>
set -euo pipefail

version="${1:?Usage: scripts/is-tagged.sh <version>}"

git ls-remote --tags origin "refs/tags/$version" | grep -q .
