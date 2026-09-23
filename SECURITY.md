# Security Policy

## Supported Versions

This module tracks the latest release only — install the newest tag via Composer
(`composer require michalper/ordo`) and keep it up to date.

## Automated Scanning

`composer audit` (dependency vulnerabilities), SonarCloud (SAST) and a nightly OWASP ZAP
baseline scan (DAST, against a live install) run in CI. None of this replaces a manual pentest.

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for a security vulnerability.

Instead, email **michal.per@outlook.com** with:

- A description of the vulnerability and its potential impact.
- Steps to reproduce (Magento version, PHP version, relevant configuration).

You should receive a response within a few business days. Once a fix is confirmed, a new
release will be published and the issue disclosed responsibly.
