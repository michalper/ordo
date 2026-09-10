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
code rather than guesswork. Organized by domain below; the priority tiers here are the first pass
at ordering it into "what do we tackle first."

### Priority order

**Tier 0 is fully closed** — see docs/CHANGELOG.md for each: `FreeGiftOffer/Delete.php` GET→POST
+ form-key; guest checkout bypassing order approval (fallback to email match); the 3-site
multi-store decision-link URL bug (order's own store, not "current store"); `approveByToken()`/
`rejectByToken()`'s save path unified with an atomic claim + order-state re-check; the Flow canvas
now renders `scheduled_at`/`cron_expression` fields for the "Scheduled Date/Time"/"Recurring
Schedule" trigger types.

**Tier 1 and Tier 2 are both fully closed** (SendGrid webhook opt-out handling, channel-send
retry/backoff, the `not_in_segment` exclusion operator, the audience-size unsaved-changes warning,
Reorder Cycle's on-demand recalculation endpoint, and `TagInactiveCustomers`'s O(n²) fix) — see
docs/CHANGELOG.md for each. (Free Gift Offer → cart integration was originally listed as Tier 1's
top item — struck from this list entirely: it turned out to already be a complete, shipped
feature, see docs/CHANGELOG.md's correction entry.)

**Tier 3 is fully closed** — see docs/CHANGELOG.md for each: A/B/split testing (backend, funnel
analytics, and Flow canvas UI), behavioral/event-based segmentation (`event_occurred` condition,
its resolver, `SegmentMemberResolver` bulk wiring, and the segment-form admin UI), scheduled/
recurring campaigns' admin UI, and unified suppression/frequency capping across all channels
(`Model\Campaign\FrequencyCapManager`).

**Tier 4 is fully closed** — see docs/CHANGELOG.md for each: batching in
`GoogleAdsSyncClient::addOperations()`, the unbounded full-table scan in `CalculateReorderCycle`,
the unbounded single-pass memory build in `GoogleMerchantFeedGenerator`, and pagination in
`RfmCalculator`'s `getAllCustomerIds()`/`getAggregatesForAllCustomers()`.

### Campaign engine (`Model/CampaignDispatcher.php`, `Model/Queue/*`, Flow canvas)

- ~~No suppression/frequency capping~~ — **closed**: `Model\Campaign\FrequencyCapManager` now caps
  total cross-channel message volume per customer per rolling window (opt-in). Still open: this
  caps *volume*, not *re-entry* — see the campaign entry dedup item right below, a related but
  distinct gap (a customer can still restart the same campaign's flow from scratch on a repeat
  trigger; capping just limits how many messages that can eventually produce).
- ~~No campaign entry dedup~~ — **closed**: `ordo_campaign_scheduled_action` gained a `customer_id`
  column (denormalized from the dispatch context), and `Model\Campaign\CampaignEntryGuard` checks
  it before `dispatch()`/`dispatchScheduledTrigger()` enter a campaign — a customer with an
  unclaimed (still-pending) resume row in that campaign is skipped rather than re-entering the
  flow from scratch. `resumeScheduledAction()` itself is deliberately unguarded, since it's the
  continuation of an already-entered chain, not a new entry.
- ~~No A/B/split testing on actions and no campaign-level funnel analytics~~ — **closed**, backend
  through admin UI (see docs/CHANGELOG.md): `Model\CampaignFunnelStats`/`CampaignOutcomeLogger`
  track sent → delivered → opened → clicked → converted per campaign, rendered on each campaign's
  edit page plus one dashboard summary card; `CampaignAction` rows with `type = 'split'`
  (`Model\Campaign\SplitVariantSelector` + `CampaignDispatcher::runSplit()`) deterministically
  branch a dispatch into a weighted variant, feeding the funnel's per-variant breakdown; the Flow
  canvas now has a full interactive editor for building one (`campaign-flow-editor.js`'s
  `renderVariantEditor()`). Known remaining limitation: a variant's own action can't carry its own
  `delay_minutes` yet (no schema support for a synthetic action's scheduled-resume FK).
- ~~No time-zone-aware quiet hours for a campaign as a whole~~ — **closed**: opt-in
  `Model\Campaign\QuietHoursGate`, checked from every Send* action right alongside
  `FrequencyCapGate`, defers a send due during the customer's local quiet-hours window until it
  ends instead of sending immediately — reusing the exact `ordo_campaign_scheduled_action`
  mechanism `delay_minutes` already uses (`CampaignDispatcher::deferActionUntil()`), so
  `CampaignEntryGuard`'s dedup and `Cron\RunScheduledCampaignActions`'s resume both apply for
  free. Customer timezone resolved via a new `ordo_timezone` customer attribute, falling back to
  the store's configured `general/locale/timezone` when unset (nothing auto-detects it).
- Flow canvas UX gaps that would frustrate daily use: no undo/redo, no node duplication/copy-paste,
  no inline "send test" before saving an action, no search/filter across the ~20+ condition/action
  types in the palette (`view/adminhtml/web/js/campaign-flow-editor.js`).
- `resumeScheduledAction()` loads and materializes *all* of a campaign's actions just to find one
  row's index, on every single scheduled resume — an indexed lookup would scale better as the
  scheduled-action backlog grows (`Model/CampaignDispatcher.php`).
- Cache invalidation for "which campaigns are active for trigger X" is one flat tag flushed on
  *any* campaign/trigger/condition/action write anywhere — on an install with many campaigns
  edited frequently, this thrashes and reverts to a full DB scan far more than necessary.
- No dead-letter/retry policy for the dispatch queue — `CampaignDispatchConsumer` explicitly drops
  a malformed message rather than requeuing it, and no alerting surfaces a broken campaign (e.g. a
  deleted email template ID) beyond a log line.
- `CampaignDispatcher`'s own AND/OR/nested-group evaluator (`evaluateGroup`/`evaluateList`) is a
  second, independent implementation of the same logic `Model/Segment/SegmentMatcher` already has
  — a fix to one (e.g. "empty group fails closed") can silently drift from the other over time.

### Segmentation, RFM & lead scoring (`Model/Segment/*`, `Model/Rfm/*`, `Model/ScoreRule/*`, `Model/AdAudience/*`)

- No segment membership history — `estimated_audience_size`/`audience_size_computed_at` store only
  the latest snapshot, so "how has this segment grown/shrunk over the last 3 months" isn't
  answerable without external tracking.
- No segment overlap/venn analysis (avoiding message fatigue by seeing "how many customers are in
  both Segment A and B") — would build directly on `SegmentMemberResolver::getMatchingCustomerIds()`,
  no new resolver logic needed.
- ~~No behavioral/event-based cohort conditions (browsing, cart, wishlist events)~~ — **closed**
  (see docs/CHANGELOG.md): `Observer\TrackCartAdd`/`TrackWishlistAdd` capture the events; the new
  `event_occurred` condition type (`Model\Event\EventOccurredResolver` +
  `Model\Campaign\Condition\EventOccurred`) is usable in campaign triggers, with
  `SegmentMemberResolver::resolveEventOccurred()` wired for segment audience size/bulk actions;
  and `ordo_segment_form.xml` now has real `event_type`/`event_key`/`within_days` fields for it —
  a marketer can build "added product X to cart in the last 14 days" without touching the API/DB.
  Known limitation, not yet validated: `within_days` set beyond `PruneVisitorEvents`'s retention
  window silently stops matching pruned rows.
- Group condition editor's JSON fallback (for `in_segment`, `loyalty_tier_at_least`,
  `nps_score_at_least`) silently becomes `{}` on malformed JSON with no validation feedback — a
  non-technical marketer gets a condition that quietly matches nothing.
- `RfmCalculator::getAggregatesForAllCustomers()`/`getAllCustomerIds()` have no pagination/streaming
  — a full `sales_order` GROUP BY and full `customer_entity` SELECT into memory on every resolve;
  fine at 10-20k customers, a real cost driver at 100k+.
- `Cron\SyncAdAudiences`/`GoogleAdsSyncClient::addOperations()` sends every hashed email as one
  single unbatched API call — Google Ads' documented per-request operation limits would make a
  large segment fail outright, not just run slowly.
- Fail-closed semantics for event-only conditions (`order_total_gte`, `visitor_tag`) used inside a
  Segment are invisible to the admin — they silently zero out an AND-segment with no UI
  explanation that these condition types only make sense in Campaign trigger context.

### Communication channels (Email/SMS/WhatsApp/Push)

- ~~No unified suppression/frequency-capping layer across channels~~ — **closed**:
  `Model\Campaign\FrequencyCapManager` caps total messages per customer per rolling window across
  Email/SMS/WhatsApp/Push combined (opt-in, see docs/CHANGELOG.md). Still open: no quiet-hours
  concept (a capped-but-still-eligible send can still land at 3am local time, see the campaign
  engine section above).
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
- ~~No retry/backoff for a failed send anywhere~~ — **closed for the immediate-retry case**:
  `Model/Campaign/Action/SendRetrier.php` now retries the actual provider call up to 3 times with
  exponential backoff inside the same action execution, excluding permanently-invalid outcomes
  (SMS opt-out, dead push subscription) from the retry. Still open: `Cron/RunScheduledCampaignActions.php`'s
  own gap remains real for a failure that survives all 3 in-process retries — "a row that failed
  stays failed" across cron ticks, since there's still no persistent retry queue for that case.
- SendGrid webhook only handles delivered/bounce/dropped and silently discards
  `spamreport`/`unsubscribe`/`group_unsubscribe` — a spam complaint or one-click unsubscribe from
  the mailbox provider never reaches `ConsentManager`, so `send_email` keeps mailing someone who
  opted out through their inbox rather than through this module's own UI (deliverability/CAN-SPAM
  risk).
- Webhook handling has no ordering/idempotency guard against provider redelivery — an
  out-of-order redelivered `delivered` event arriving after a later `failed` one can regress a
  message's logged status backward.
- WhatsApp template admin form is a raw textarea with manual `{{1}}`/`{{2}}` placeholders, no
  character-limit check against Meta's real limits, and no rendered preview — each submission
  costs a real Meta review cycle, so mistakes are expensive.

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
- `Cron/CalculateReorderCycle`'s interval estimate is a plain mean with no outlier resistance — one
  anomalous gap (customer paused 6 months) skews the whole prediction; same-day repeat purchases
  are silently dropped rather than handled distinctly.
- `CalculateReorderCycle`/`GoogleMerchantFeedGenerator` both run as full unbounded scans/single-pass
  memory builds with no incremental/last-run filtering — both get linearly slower as order
  history/catalog size grows, with real memory-exhaustion risk on large stores.
- Product feed is single-format (Google RSS only), single-store, with no admin grid for feed
  health/history — a generation failure only sets an error flag nobody can see without knowing to
  look.
- Dashboard runs 4+ separate uncached COUNT queries on every page load and has no drill-down for
  "N approvals stuck" / "N crons failed" — the KPIs shown aren't actionable.

### Admin platform, UX consistency & API

- No audit log of admin actions anywhere — no way to answer "who changed this campaign last
  Tuesday," despite campaigns/segments/offers directly affecting revenue and customer comms.
- No bulk/mass-action on any of the ~10 listing grids (`grep` across every `*_listing.xml` finds
  zero `massaction` blocks) — enabling/disabling/deleting is strictly one row at a time everywhere.
- No export/import for campaigns or segments — the only export capability in the whole module is
  GDPR customer-data export; nothing lets a merchant move a campaign/segment definition between
  dev/staging/prod or back it up before a risky edit.
- No column filtering on any grid, anywhere (only sorting) — as message log/RFM data grows, an
  admin can't search "messages that failed" without paging through manually.
- Color-token duplication instead of one shared design-system file — `dashboard.css`,
  `segment-form.css`, `flow.css`, and `free-gift-offer-form.css` each independently (re)define
  near-identical but not-identical palettes (e.g. two different purple accent hues); a rebrand
  touches 4+ files with no single source of truth.
- No setup wizard/guided first-run flow across the module — per-grid empty-state CTAs exist, but
  nothing walks a fresh install through the real dependency order (configure a channel → build a
  segment → build a campaign); an admin can build a `send_sms` action before ever configuring
  Twilio credentials and only discovers the gap when sends silently fail.
- ACL resources are shared across functionally distinct screens, weakening least-privilege —
  Message Log, Reorder Cycles, and Product Feed refresh all reuse the `campaigns` resource, RFM
  reuses `segments`; a role can't be scoped to just one of these.
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
