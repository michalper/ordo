#!/usr/bin/env bash
# Builds a source archive for <version> and signs it with cosign's keyless flow (Sigstore), then
# uploads the archive + its Sigstore bundle (signature, certificate, and Rekor transparency-log
# entry, all in one JSON file - cosign's current sign-blob/verify-blob interface, replacing the
# older separate --output-signature/--output-certificate flags) as release assets. Shared by
# scripts/release.sh (manual - signs using the invoking human's own GitHub/Google identity via an
# interactive browser flow) and .github/workflows/release.yml's "release" job (automated - signs
# using the GitHub Actions run's own OIDC identity, no browser needed). Either way there is no
# persistent private key anywhere: Sigstore's Fulcio issues a short-lived certificate bound to
# that identity. See "Verifying a release" in SECURITY.md for how a user checks this. Addresses
# the OpenSSF Best Practices Badge's signed_releases criterion; see docs/ASSURANCE_CASE.md.
#
# Requires: cosign (https://docs.sigstore.dev/cosign/installation/), gh.
#
# Usage: scripts/sign-release.sh <version>   (e.g. scripts/sign-release.sh 1.13.0)
set -euo pipefail

version="${1:?Usage: scripts/sign-release.sh <version>, e.g. scripts/sign-release.sh 1.13.0}"
archive="ordo-$version.tar.gz"
bundle="$archive.sigstore.json"

trap 'rm -f "$archive" "$bundle"' EXIT

git archive --format=tar.gz --prefix="ordo-$version/" -o "$archive" "$version"
cosign sign-blob --yes --bundle "$bundle" "$archive"
gh release upload "$version" "$archive" "$bundle"

echo "Signed and uploaded $archive (+ $bundle) to the $version release."
