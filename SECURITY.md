# Security Policy

## Supported Versions

This module tracks the latest release only — install the newest tag via Composer
(`composer require michalper/ordo`) and keep it up to date.

## Automated Scanning

`composer audit` (dependency vulnerabilities), SonarCloud (SAST) and a nightly OWASP ZAP
baseline scan (DAST, against a live install) run in CI. None of this replaces a manual pentest.

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for a security vulnerability.

Instead, email **<michal.per@outlook.com>** with:

- A description of the vulnerability and its potential impact.
- Steps to reproduce (Magento version, PHP version, relevant configuration).

You should receive a response within a few business days. Once a fix is confirmed, a new
release will be published and the issue disclosed responsibly.

## Verifying a release

Every GitHub Release from `X.Y.Z` onward includes a source archive
(`ordo-X.Y.Z.tar.gz`) and a Sigstore bundle (`ordo-X.Y.Z.tar.gz.sigstore.json`) signed keylessly
with [cosign](https://docs.sigstore.dev/cosign/installation/) - there is no long-lived private
signing key anywhere; the certificate is short-lived and tied to the identity that signed it
(normally this repo's own `release.yml` workflow run, via GitHub Actions' own OIDC token). See
[docs/ASSURANCE_CASE.md](docs/ASSURANCE_CASE.md) for why.

To verify a downloaded archive:

```sh
cosign verify-blob \
  --bundle ordo-X.Y.Z.tar.gz.sigstore.json \
  --certificate-identity-regexp 'https://github\.com/michalper/ordo/' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  ordo-X.Y.Z.tar.gz
```

`cosign` reports the exact signing identity from the certificate if you'd rather confirm it by eye
than match a regexp - open `ordo-X.Y.Z.tar.gz.sigstore.json` and inspect the embedded certificate,
or run the command above with `-d`/`--verbose`.
