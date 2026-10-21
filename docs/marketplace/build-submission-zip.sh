#!/bin/sh
# Builds the zip archive to upload for Adobe Commerce Marketplace technical review.
#
# Base content is exactly what `git archive` already exports for this repo's own composer
# path-repository consumers (see .gitattributes' export-ignore list and its own comment for
# why: vendor/, node_modules/, caches/logs excluded, .git itself never included). On top of
# that, paths that make sense for the path-repo copy (used by this repo's own dev/CI tooling)
# but not for a Marketplace submission are excluded here instead of in .gitattributes, since
# changing that file would also strip them from the local dev sandbox install that still needs
# them present:
#   - .github/            CI workflow configuration, not something a merchant's store needs.
#   - docs/marketplace/   this script's own output and the submission-prep materials
#     (PDF guide, release notes) - shipping the Marketplace-submission paperwork inside the
#     submission itself would be self-referential noise, not module functionality.
#   - .gitignore, .gitattributes   found the hard way: Adobe's technical review rejects a
#     submitted zip containing either, flagged as "Deprecated File" ("Please remove .gitignore
#     from archive", same for .gitattributes) - not documented anywhere in the public
#     guidelines pages, only surfaced by an actual failed submission.
#
# Everything else ships, Test/ included - EQP's technical review scans the whole package
# (see ROADMAP.md item 4), so excluding it would just mean nothing catches a regression
# before Adobe's own review does.
#
# One content change, not just an exclusion: composer.json deliberately has no "version" key
# in this repo (versioning is via git tags only - a real, separate decision, not an oversight;
# see composer.json's git history) but Adobe's technical review statically checks the
# submitted composer.json for name/type/*version*, with no VCS to derive one from the way a
# normal `composer install` would. A version is stamped into the packaged copy only, derived
# from the same `git describe` used for the zip's own filename - the tracked composer.json in
# the repo is never touched.
set -eu

cd "$(dirname "$0")/../.."
MODULE_NAME="michalper-ordo"

# Must run from an exact tag: `git describe`'s fallback form for a commit past the last tag
# (e.g. "1.1.0-23-g6ae2efc") is not a valid Composer version string (confirmed the hard way -
# `composer validate` rejects it against its own version regex), and a Marketplace submission
# needs a real, deliberate version number anyway, not whatever the last commit happens to be.
if ! VERSION="$(git describe --tags --exact-match 2>/dev/null)"; then
    echo "ERROR: HEAD is not exactly on a git tag. Tag the release first:" >&2
    echo "  git tag X.Y.Z && git push origin X.Y.Z" >&2
    echo "then re-run this script." >&2
    exit 1
fi

OUT_DIR="docs/marketplace/build"
STAGE_DIR="$OUT_DIR/stage"
OUT_ZIP="$OUT_DIR/${MODULE_NAME}-${VERSION}.zip"

rm -rf "$STAGE_DIR" "$OUT_ZIP"
mkdir -p "$STAGE_DIR"

git archive HEAD -- . ':!.github' ':!docs/marketplace' ':!.gitignore' ':!.gitattributes' | tar -x -C "$STAGE_DIR"

python3 - "$STAGE_DIR/composer.json" "$VERSION" <<'EOF'
import json, sys
path, version = sys.argv[1], sys.argv[2]
with open(path) as f:
    data = json.load(f, object_pairs_hook=dict)
# Insert right after "type" to match the order Adobe's own examples use (name, type, version).
ordered = {}
for key, value in data.items():
    ordered[key] = value
    if key == "type":
        ordered["version"] = version
if "version" not in ordered:
    ordered["version"] = version
with open(path, "w") as f:
    json.dump(ordered, f, indent=4)
    f.write("\n")
EOF

(cd "$STAGE_DIR" && zip -rq -9 "../$(basename "$OUT_ZIP")" .)
rm -rf "$STAGE_DIR"

SIZE_BYTES=$(wc -c < "$OUT_ZIP" | tr -d ' ')
SIZE_MB=$(echo "scale=2; $SIZE_BYTES / 1048576" | bc)
LIMIT_MB=30

echo "Wrote $OUT_ZIP ($SIZE_MB MB), composer.json version=$VERSION"
if [ "$(echo "$SIZE_MB > $LIMIT_MB" | bc)" = "1" ]; then
    echo "ERROR: exceeds Adobe's ${LIMIT_MB}MB submission limit" >&2
    exit 1
fi
