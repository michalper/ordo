# ADR 0081: Sign releases keylessly with cosign/Sigstore

## Status

Accepted.

## Context

OpenSSF Best Practices Badge's `signed_releases` criterion (silver tier) requires releases
intended for widespread use to be cryptographically signed, with a documented verification
process, and explicitly **forbids** keeping the private signing key on any site used to directly
distribute the software - this repo's releases (GitHub Releases, consumed via Composer and the
Adobe Commerce Marketplace) are intended for widespread use, so this isn't skippable via "not
applicable".

A traditional GPG key would need to live somewhere to sign automated releases (`release.yml`
tags and publishes with no human in the loop - see ADR 0077). Storing it as a GitHub Actions
secret is a real, ongoing key-management burden (generation, rotation, revocation, loss risk) for
a solo-maintained project (see `GOVERNANCE.md`'s bus-factor note), and raises exactly the
"where does the private key live" question the criterion is worried about, even if a repo secret
isn't literally part of what gets distributed to end users.

## Decision

- **cosign's keyless signing (Sigstore)** instead of a long-lived GPG/PGP key. There is no
  persistent private key anywhere to manage, rotate, or leak: Fulcio (Sigstore's CA) issues a
  short-lived certificate bound to the signer's own OIDC identity - the GitHub Actions run's
  identity for an automated release, or the invoking maintainer's GitHub/Google identity (via an
  interactive browser flow) for a manual one - and the signature is recorded in the public Rekor
  transparency log. This sidesteps the private-key-location question entirely rather than
  answering it with a secret that needs ongoing care.
- **Signs a source archive of the tagged commit (`git archive`), not the git tag itself** -
  `scripts/sign-release.sh`, shared by both `scripts/release.sh` (manual) and `release.yml`
  (automated), same pattern `scripts/tag-and-release.sh` already established (one script, not
  duplicated logic). GPG-signing the git tag object itself (the OpenSSF `version_tags_signed`
  SUGGESTED criterion) was considered and left undone for now - it would need its own persistent
  key (git's tag-signing support doesn't have a keyless/Sigstore equivalent the way `cosign` does
  for blobs), reopening the exact key-management question this ADR avoids for the actual release
  artifact. `version_tags_signed` is marked Unmet with this reasoning on the Best Practices Badge
  entry rather than worked around.
- **`cosign sign-blob --bundle`**, not the older `--output-signature`/`--output-certificate`
  flags (removed from current cosign) - one Sigstore bundle JSON file per release, containing the
  signature, certificate, and transparency-log entry together.
- **Verification is documented in `SECURITY.md`** with a runnable `cosign verify-blob` command,
  using `--certificate-identity-regexp` scoped to this repo rather than hardcoding the exact
  identity string GitHub Actions' OIDC token produces for a `workflow_call`-invoked job (untested
  until a real release runs through this path - see "Known limitation" below).

## Known limitation (accepted, not fixed here)

This hasn't been exercised against a real release yet - the `release.yml` job's own `if:` guards
mean nothing in this ADR's change path runs until the next actual version bump. If the exact
`--certificate-identity` GitHub Actions produces for a `workflow_call`-invoked job differs from
what `--certificate-identity-regexp 'https://github\.com/michalper/ordo/'` in `SECURITY.md`
expects, that's a verification-documentation fix, not a signing fix - the signing step itself does
not depend on knowing the identity string in advance.
