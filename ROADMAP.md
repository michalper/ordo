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

Full inventory with what's covered and why: `Test/Mftf/SCENARIOS.md`. Every row there is currently ✅ — no open
gaps. Kept as the standing scope check for anything newly added to the module (new trigger/condition/action/
controller/cron gets a row there before it's considered done).

## Full-codebase improvement audit (2026-09-10)

Five independent passes over the whole module (campaign engine, segmentation/RFM/scoring,
communication channels, commerce features, admin platform/UX/API), each grounded in the actual
code rather than guesswork. Organized by domain below. Tiers 0-4 from the original pass are all
fully closed — see docs/CHANGELOG.md for the full history of each.

### Campaign engine (`Model/CampaignDispatcher.php`, `Model/Queue/*`, Flow canvas)

- Flow canvas UX gaps that would frustrate daily use: no undo/redo, no node duplication/copy-paste,
  no inline "send test" before saving an action (palette search/filter now exists — see
  docs/CHANGELOG.md).

### Communication channels (Email/SMS/WhatsApp/Push)

- Every send is still one synchronous, unbatched HTTP call per customer inline in the dispatch
  path — no concurrency control (client-side pacing per provider now exists, see docs/CHANGELOG.md
  — that's throttling one process's own call rate, not coordinating concurrency across multiple
  queue consumers/cron processes hitting the same provider at once).
- `SendRetrier`'s 3 in-process retries are still the only retry a per-customer send gets — once
  those are exhausted, `Send{Email,Sms,WhatsApp,Push}` catches the failure, writes an
  `ordo_message_log` row with `STATUS_FAILED`, and moves on; nothing ever revisits that row. (Note:
  a *different*, adjacent gap — a `Cron\RunScheduledCampaignActions` resume itself throwing, e.g. a
  DB error or a deleted campaign — now does get a persisted retry with backoff, see
  docs/CHANGELOG.md; this is specifically about the per-send retry budget inside a still-successful
  resume.)

### Commerce features (free gifts, order approval, reorder cycles, GDPR, product feed, dashboard)

*(the "Free Gift never applies to a cart" and "guest checkout bypasses approval" items are listed
as bugs above, not repeated here)*

- No one-click "build reorder cart" action for a detected reorder cycle (manual per-customer
  reminder trigger now exists — see docs/CHANGELOG.md).
- Product feed is still single-format (Google RSS only) — multi-store and health/history are now
  covered (see docs/CHANGELOG.md). A second feed format (beyond Google Merchant's RSS+g: namespace)
  is a bigger, separate abstraction.

### Admin platform, UX consistency & API

- No bulk/mass-action on MessageLog, ReorderCycle, and Rfm's grids (Campaign, Segment,
  ContentBlock, FreeGiftOffer, ScoreRule, and AdAudience now have enable/disable/delete mass
  actions, and WhatsAppTemplate has mass-delete — see docs/CHANGELOG.md) — those three remaining
  grids are read-only/log/diagnostic views without an "enabled" concept to toggle, so a mass
  action there would need its own new capability first, not just wiring up an existing one.
- No **import** for campaigns or segments yet (export now exists — see docs/CHANGELOG.md) — a
  merchant can back up or move a definition's JSON out of an environment, but there's no way to
  bring it back in; that's still a manual conversation with support/engineering.
- No `fields`/sparse-fieldset support anywhere in `API.md`.

## Scheduled (date-based) campaigns: calendar view

Both the backend (`ScheduledTriggerScanner`/`DispatchScheduledCampaignTriggers`) and the admin UI
to configure `scheduled_at`/`recurring_schedule` triggers are done — see docs/CHANGELOG.md. What's
left is optional polish: an actual date-grid calendar view plotting when each scheduled campaign
will (or did) fire. Worth revisiting whether "Campaign Action Timeline" should grow a
calendar-view toggle for this, or stay a separate screen.

## Localization

- **Native-speaker review of the 10 machine-translated locales** (`de_DE`, `fr_FR`, `es_ES`, `it_IT`, `pt_BR`,
  `zh_Hans_CN`, `ja_JP`, `ru_RU`, `uk_UA`, `nl_NL`) — shipped as a machine-translated first pass (see
  docs/CHANGELOG.md),
  not yet signed off by a human reviewer per locale. Highest priority: launch-blocking strings (error messages,
  delete confirmations) over descriptive/help text.

## Documentation

- **GitHub Wiki covering every feature, bilingual PL/EN, with screenshots.** Not started. One walkthrough page per
  shipped capability (campaigns, segments, RFM, lead scoring, free gifts, order approval, tracking/popups, reorder
  cycles, dashboard), each with a real admin-UI screenshot and PL/EN text. Needs a structure decision first —
  GitHub Wiki has no built-in i18n, so bilingual-per-page vs. a language-split page tree is a real choice.
