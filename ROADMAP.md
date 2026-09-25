# Roadmap

What's still open — for shipped/stable features see [README.md](README.md), for implementation history/verification
detail see [CHANGELOG.md](docs/CHANGELOG.md) and [VERIFICATION.md](VERIFICATION.md), for the REST API reference see
[API.md](API.md), and the `Test/*/README.md` files.

Ownership split: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is
scoped from real hands-on marketing automation experience.

## Test coverage

- **Ad-audience sync (`Cron\SyncAdAudiences`) has no test against a real Google Ads/Meta account.** Note:
  a since-fixed bug (docs/CHANGELOG.md "Fixed") meant `getGoogleAdsClientSecret()`/`getGoogleAdsRefreshToken()`/
  `getGoogleAdsDeveloperToken()`/`getMetaAccessToken()` returned ciphertext at runtime, not the decrypted
  secret — every real API call would have failed auth regardless of this gap. Same shape as
  `send_sms`'s equivalent gap, already closed (see docs/CHANGELOG.md): unit tests
  (`GoogleAdsSyncClientTest`/`MetaSyncClientTest`/`GoogleOAuthTokenProviderTest`)
  drive the real request-building/response-parsing logic via a fake `Curl`, and the integration test
  (`SyncAdAudiencesTest`) uses real DI/database (real segment/tag/customer rows, real `SegmentMemberResolver`
  query, real `PiiHasher`) but swaps `SyncClientInterface` for a `RecordingSyncClient` — so the actual HTTP
  calls to `googleads.googleapis.com`/`graph.facebook.com` (OAuth token exchange, offline user data job
  lifecycle, Custom Audience creation/replace) have never been exercised against live credentials.
- **`send_whatsapp` / WhatsApp templates have no test against a real Meta WhatsApp Business Account.** Note:
  the same since-fixed bug meant `getWhatsAppAccessToken()`/`getWhatsAppAppSecret()` returned ciphertext at
  runtime — every real Graph API call and every webhook signature check would have failed regardless of this
  gap. Same shape again: unit tests (`WhatsAppSenderTest`/`WhatsAppTemplateClientTest`) drive the real Graph API
  request-building/response-parsing logic via a fake `Curl`, and `WhatsAppSignatureValidatorTest`/`WebhookTest`
  use a real HMAC-SHA256 signature — but template submission (`SubmitForReview`), approval polling
  (`RefreshStatus`), and an actual template message send have never been exercised against a live WABA/phone
  number, and the webhook receiver has never received a genuine callback from Meta.

### MFTF/scenario coverage

Full inventory with what's covered and why: `Test/Mftf/SCENARIOS.md`. Two open rows as of 2026-09-23, both from
brand-new features (not gaps in existing coverage): `AutoPickCampaignSplitWinner` (§28) and
`SendAbandonedCartFallbackReminders` (§11), both unit-tested only, no MFTF/Integration yet. Kept as the standing
scope check for anything newly added to the module (new trigger/condition/action/controller/cron gets a row
there before it's considered done).

## Full-codebase improvement audit (2026-09-10)

Five independent passes over the whole module (campaign engine, segmentation/RFM/scoring,
communication channels, commerce features, admin platform/UX/API), each grounded in the actual
code rather than guesswork. Organized by domain below. Tiers 0-4 from the original pass are all
fully closed — see docs/CHANGELOG.md for the full history of each.

### Campaign engine (`Model/CampaignDispatcher.php`, `Model/Queue/*`, Flow canvas)

Flow canvas UX (undo/redo, node duplication, palette search/filter, inline "send test") is now
fully closed — see docs/CHANGELOG.md.

### Commerce features (free gifts, order approval, reorder cycles, GDPR, product feed, dashboard)

*(the "Free Gift never applies to a cart" and "guest checkout bypasses approval" items are listed
as bugs above, not repeated here)*

## Documentation

- **GitHub Wiki covering every feature, bilingual PL/EN, with screenshots.** Published at
  `github.com/michalper/ordo/wiki` — all 8 capability pages plus Home/`_Sidebar`, content verified against the
  actual `Controller`/`Block`/`Model`/`Ui` classes and `view/adminhtml/ui_component/*.xml`, not invented, with real
  screenshots (from a running Magento 2.4.9 admin instance) embedded for every page. `docs/wiki/` in this repo is
  the source copy staged for edits before re-publishing. Still open: native PL review of the drafted text.

## Candidate new features

Not gaps in something existing — genuinely new capabilities, proposed after checking they don't already
exist in some form. Not prioritized against each other; listed for later scoping.

- **Campaign template library** — a curated gallery of ready-to-use campaign starting points (e.g. welcome
  series, cart abandonment, win-back, VIP upsell), each a pre-filled trigger/condition/action graph an admin
  can pick and adapt instead of building from scratch. `Model\Campaign\CampaignImporter` already accepts the
  JSON shape a template would need; this is the curated library + picker UI on top of it, not new import
  plumbing. Medium scope, B2C/B2B.

## 2026-09-25 codebase audit — being worked through on `fix/audit-findings-batch`

Eight parallel domain passes (campaign engine, segmentation/scoring/lead-routing, communication
channels, commerce features, admin UI/REST/security, performance, UX/UI, tooling/CI). Being fixed
top-to-bottom on one branch; remove each line here as its fix lands, not after the branch merges -
this section should be empty by the time that PR is done.

**High**
- [ ] `Controller/Adminhtml/WhatsAppTemplate/SubmitForReview.php` + `RefreshStatus.php`: both
  `HttpGetActionInterface` but mutate state (submit template to Meta / overwrite status) via plain
  GET navigation — CSRF risk if `use_form_key` is off. Convert to POST.
- [ ] `Model/ScoreRule/ScoreRuleEvaluator.php:47-49` + `Model/LeadRouting/LeadRoutingRuleEvaluator.php:52-54`:
  `not_equals` against a missing/unset attribute always returns `false` instead of `true` (same
  copy-pasted bug in both). Untested.
- [ ] `Model/CampaignDispatcher.php:294-332` `resumeScheduledAction()` never checks the campaign is
  still `enabled` (unlike `dispatch()`/`dispatchScheduledTrigger()`) — disabling a campaign doesn't
  stop already-scheduled delayed actions from firing.
- [ ] Flow canvas icon-only buttons (`campaign-flow-editor.js`) rely on `title` alone; visible glyph
  content wins accessible-name computation over `title` — screen reader announces the glyph, not
  "Remove"/"Duplicate". Only one `aria-label` exists in the whole admin frontend.
- [ ] `composer.lock` is gitignored — every CI run re-resolves `require-dev` fresh, which is exactly
  what broke `static-analysis` this session (phpstan 2.2.15→2.2.16 mid-session). Commit the lockfile
  (or pin exact versions for baseline/config-sensitive dev tools).

**Medium**
- [ ] `ReminderLogStore` (reorder/credit-limit/offer-expiry crons): claim is a `SELECT COUNT` then a
  separate `insert()`, not atomic; no unique index on the three log tables — two overlapping cron
  runs can double-send. `OrderApprovalManagement` does this correctly elsewhere in the same codebase.
- [ ] `Observer/AssignLeadRoutingRule.php:88-98` + `LeadAssigner`: `hasAssignedRep()` checked outside
  `assign()`'s own `SELECT FOR UPDATE` transaction — two near-simultaneous qualifying events for one
  customer can both pass the guard and consume two round-robin turns.
- [ ] `etc/webapi.xml`: `GET /V1/ordo/customers/:customerId/credit-limit` and
  `GET /V1/ordo/order-approvals/:entityId/decision-links` are gated on the generic
  `Ordo_Automation::config` ACL resource instead of a dedicated one.
- [ ] `Block/Adminhtml/WhatsAppTemplate/Edit/SubmitForReviewButton.php`: re-submitting an
  already-approved/pending template is not actually harmless (its own docblock's claim) —
  `SubmitForReview` never checks current status before re-calling Meta.
- [ ] `Ui/Component/Listing/Column/OrderApprovalActions.php:44`: `getDecisionLinksById()` called
  per-row in a `foreach` — N+1 that scales with grid page size.
- [ ] No CSS file in `view/adminhtml/web/css/` has a single media query; `flow.css`'s canvas is
  fixed-height with no narrower-viewport fallback.
- [ ] Typography scale (`--ordo-font-*`) duplicated in `flow.css` and `dashboard.css` and has already
  drifted (each has tokens the other lacks) — centralize like `_tokens.css` already does for colors.
- [ ] `infection.json5`'s MSI gate (70/70) has ~0 margin against the measured ~70.2% baseline — hit
  exactly 69-70% twice this session from ordinary mutant-selection noise.

## Priority of next steps

Order reflects severity (financial bugs > reliability debt > UX > topics dependent on external
resources):

1. **New features (the "Candidate new features" section above)** — to be prioritized together
   with the business/product side.
2. **Tests against live accounts (Google Ads/Meta/WhatsApp)** — dependent on availability of real
   test credentials.
