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
  no inline "send test" before saving an action, no search/filter across the ~20+ condition/action
  types in the palette (`view/adminhtml/web/js/campaign-flow-editor.js`).
- No dead-letter/retry policy for the dispatch queue — `CampaignDispatchConsumer` explicitly drops
  a malformed message rather than requeuing it, and no alerting surfaces a broken campaign (e.g. a
  deleted email template ID) beyond a log line.
- `Model\Segment\SegmentMemberResolver`'s own, separate (set-level, `int[]`-returning)
  reimplementation of the same AND/OR/nested-group-walk shape `Model\Condition\ConditionGroupEvaluator`
  already covers for the per-customer boolean case — a bigger unification question than that one
  was, since its leaf resolution (aggregate/set queries) is genuinely different, not attempted yet.

### Segmentation, RFM & lead scoring (`Model/Segment/*`, `Model/Rfm/*`, `Model/ScoreRule/*`, `Model/AdAudience/*`)

- No segment membership history — `estimated_audience_size`/`audience_size_computed_at` store only
  the latest snapshot, so "how has this segment grown/shrunk over the last 3 months" isn't
  answerable without external tracking.
- Group condition editor's JSON fallback (for `in_segment`, `loyalty_tier_at_least`,
  `nps_score_at_least`) silently becomes `{}` on malformed JSON with no validation feedback — a
  non-technical marketer gets a condition that quietly matches nothing.

### Communication channels (Email/SMS/WhatsApp/Push)

- No template preview or test-send anywhere in admin, for any channel — merchants routinely typo
  `{{var}}`/WhatsApp `{{1}}` placeholders and only discover it once a real customer gets the
  broken message.
- Product recommendations are effectively email-only — `AddProductRecommendations` only renders
  HTML; SMS/WhatsApp/Push actions have no plain-text equivalent, even though the underlying
  `ProductRecommender` data would support it.
- Every send is one synchronous, unbatched HTTP call per customer inline in the dispatch path — no
  concurrency control and no respect for provider rate limits (Twilio, Graph API, push services);
  a campaign matching thousands of customers in one tick will serially hammer the provider API or
  start hitting 429s with no handling for it.
- `Cron/RunScheduledCampaignActions.php` has no persistent retry queue for a send that fails all 3
  of `SendRetrier`'s in-process retries — "a row that failed stays failed" across cron ticks.

### Commerce features (free gifts, order approval, reorder cycles, GDPR, product feed, dashboard)

*(the "Free Gift never applies to a cart" and "guest checkout bypasses approval" items are listed
as bugs above, not repeated here)*

- Order approval is single-level with a hard escalation ceiling (`MAX_ESCALATIONS = 3` in
  `Cron/EscalateStalePendingApprovals.php`) and then the order sits pending forever — no second
  approver, no delegate-when-absent, no auto-approve/auto-cancel fallback.
- No admin grid for order approvals at all — only email tokens + REST API; an admin who loses the
  original email has no in-backend way to browse or act on a pending approval, unlike every other
  domain entity in this module.
- GDPR erasure/export hand-maintain two independent table lists with no single source of truth —
  the same "quietly goes stale" pattern already bit `SetConsent`'s channel list once (since fixed);
  a new customer-keyed table can silently be omitted from erasure.
- No consent audit trail — `SetConsent` overwrites current state with no timestamped history, which
  is what most real GDPR audits actually ask for ("was this customer opted in for SMS on date X").
- No persisted cron-run log/grid — `Model/Cron/CronRunLogger.php` only writes to `var/log`; "did
  today's escalation cron even run" is invisible without log-tailing.
- Reorder Cycle is detection-only — `Cron/CalculateReorderCycle.php` computes `next_expected_date`
  but there's no one-click "build reorder cart" action and no manual per-customer reminder trigger.
- Product feed is single-format (Google RSS only), single-store, with no admin grid for feed
  health/history — a generation failure only sets an error flag nobody can see without knowing to
  look.
- Dashboard has no drill-down for "N approvals stuck" / "N crons failed" — the KPIs shown aren't
  actionable.

### Admin platform, UX consistency & API

- No audit log of admin actions anywhere — no way to answer "who changed this campaign last
  Tuesday," despite campaigns/segments/offers directly affecting revenue and customer comms.
- No bulk/mass-action on 8 of the ~10 listing grids (Campaign and Segment now have
  enable/disable/delete mass actions — see docs/CHANGELOG.md; ContentBlock, FreeGiftOffer,
  MessageLog, ReorderCycle, Rfm, ScoreRule, WhatsAppTemplate, and AdAudience still don't) —
  enabling/disabling/deleting is strictly one row at a time on those.
- No export/import for campaigns or segments — the only export capability in the whole module is
  GDPR customer-data export; nothing lets a merchant move a campaign/segment definition between
  dev/staging/prod or back it up before a risky edit.
- No setup wizard/guided first-run flow across the module — per-grid empty-state CTAs exist, but
  nothing walks a fresh install through the real dependency order (configure a channel → build a
  segment → build a campaign); an admin can build a `send_sms` action before ever configuring
  Twilio credentials and only discovers the gap when sends silently fail.
- No `fields`/sparse-fieldset support and no documented rate limiting anywhere in `API.md`; the
  anonymous order-approval endpoints (`.../approve`, `.../reject`) are token-guarded but not
  rate-limited against brute-forcing a token guess.

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
