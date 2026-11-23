# Assurance case

A short, honest summary of what this project does to earn trust, what it specifically protects
against, and what it does not - written for the OpenSSF Best Practices Badge's `assurance_case`
criterion, but meant to actually be read, not just to satisfy a checkbox.

## What this software does

Ordo (`Ordo_Automation`) is a Magento 2 module: a campaign-scenario engine (trigger → conditions →
actions) plus the B2B/B2C features built on it. See [README.md](../README.md) for the feature list
and [docs/ARCHITECTURE.md](ARCHITECTURE.md) for the directory/class map. It runs inside a
merchant's own Magento installation, with the same trust level as any other installed module -
it is not a hosted service and does not run code on anyone else's behalf.

## What it protects against, and how

- **Cross-site request forgery (CSRF).** Every `CsrfAwareActionInterface` controller either uses
  Magento's own CSRF token checking, or, for sessionless token-authenticated endpoints (order
  approval/rejection links, unsubscribe links) where the secret token in the URL *is* the
  authentication, is deliberately exempt for the same reason `Controller\Email\Unsubscribe`
  already was - there's no session/cookie-based login for a forged cross-site request to exploit.
- **Email-safe-link prefetch acting on a GET request.** Corporate email-security gateways
  (Microsoft Defender Safe Links, Proofpoint, Mimecast) and some mail clients routinely pre-fetch
  every URL in an inbound email before a human ever clicks it. A real instance of this was found
  and fixed during this project's own security review: order-approval email links used to call
  `approveByToken()`/`rejectByToken()` directly from GET, so a security gateway's prefetch could
  silently burn the single-use decision token before the real recipient saw the email. Fixed by
  making GET render a confirmation page only (never reading/validating the token), with the actual
  decision requiring a POST from that page - see ADR 0078.
- **Forged or unauthorized external requests.** `Controller/Webhook/Receive.php` and
  `Controller/WhatsApp/Webhook.php` reject any request without a valid HMAC-SHA256 signature
  *before* the body is ever decoded - an unauthenticated caller can never reach the campaign
  dispatcher. `Controller/Track/*` endpoints check that a request can only act on data that
  belongs to the requester's own `customer_id`/`visitor_id` (see
  `VisitorIdentityFieldsTrait::belongsTo()`).
- **SQL injection.** All database access goes through Magento's own `AdapterInterface` with bound
  parameters - no raw string-concatenated SQL anywhere in this codebase (enforced in practice by
  PHPStan level `max` plus Magento's own coding-standard rule set, both required CI checks).
- **Race conditions (TOCTOU).** Several real time-of-check-to-time-of-use races were found and
  fixed during this project's own audits: a MySQL named-lock pattern
  (`GET_LOCK()`/`RELEASE_LOCK()`) serializes the check-then-act sequence for credit-limit checkout
  blocking, loyalty-points redemption, and lead routing, so two near-simultaneous requests can't
  both pass a check that should only ever pass once. See `docs/CHANGELOG.md` and the relevant ADRs
  for each.
- **Over-privileged automation.** Every GitHub Actions workflow in this repo defaults to
  `permissions: contents: read` and elevates only the specific job that needs more, only for that
  job (OpenSSF Scorecard's `Token-Permissions` check enforces this stays true). The release
  automation (`release.yml`) can only ever be triggered by a direct push to `main` - unreachable
  from a fork, because a fork cannot push to this repo's `main` branch at all (see ADR 0077 for why
  this replaced an earlier `workflow_run` trigger). The `update-site` job authenticates to the
  Claude API via short-lived Workload Identity
  Federation tokens instead of a long-lived static API key.
- **Credential exposure.** Every credential this module itself handles (Twilio, Google Ads/GA4,
  TikTok, Meta/WhatsApp, VAPID push keys) is declared `type="obscure"` *and* backed by
  `Magento\Config\Model\Config\Backend\Encrypted` in `etc/adminhtml/system.xml` - encrypted at rest
  using Magento's own crypt key (`app/etc/env.php`, never in the database or this repo), editable
  by an admin with no code change or recompilation required. No credential is ever logged, and no
  credential is committed to this repository (verified; see `SECURITY.md`).
- **Unknown/malformed data crashing the dispatcher.** The campaign/segment condition tree-walk
  (`Model/Condition/GroupWalker`) fails closed on an empty or malformed nested group rather than
  matching everyone; an unknown condition/action type fails closed (campaign doesn't run) rather
  than open. This is actively fuzz-tested (`nikic/php-fuzzer`, see ADR 0080) for the specific class
  of bug a crafted payload could trigger here.

## What it does not protect against (residual risk, stated plainly)

- **A single point of failure in the maintainer.** This is a solo-maintained project - see
  [GOVERNANCE.md](../GOVERNANCE.md)'s Bus factor section, stated without euphemism.
- **Vulnerabilities in Magento core, PHP, or any third-party dependency this module relies on.**
  This assurance case covers Ordo's own code; `composer audit` (CI) and Dependabot catch known
  dependency vulnerabilities, but a zero-day in a dependency is outside what this module can
  control.
- **A merchant's own server/hosting misconfiguration.** Ordo runs with the same privileges as the
  rest of the Magento installation it's deployed into; it does not sandbox itself from a
  compromised host, and nothing here claims otherwise.
- **A deliberately malicious store administrator.** Admin-authenticated surfaces (campaign/segment
  condition JSON, `ParamFieldsNormalizer`) are not hardened against someone who already has admin
  access - that's Magento's own admin-user trust boundary, not one this module adds to or weakens.
- **Formal verification.** Nothing here has been proven correct with formal methods. Confidence
  comes from real, enforced CI gates (100% patch/project line coverage, mutation testing gated at
  MSI ≥ 70%, PHPStan `level: max`, SonarCloud, OWASP ZAP nightly DAST, coverage-guided fuzzing) and
  from fixing real issues found during active review - not from a guarantee of absence of bugs.

## How this is kept current

This document is reviewed whenever a security-relevant architectural decision is made (see
"When to write an ADR" in `AGENTS.md`) - new ADRs that materially change a protection or a residual
risk described above should update this file in the same PR.
