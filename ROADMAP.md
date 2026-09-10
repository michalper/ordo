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
  secret — every real API call would have failed auth regardless of this gap. Same shape
  as `send_sms` above: unit tests (`GoogleAdsSyncClientTest`/`MetaSyncClientTest`/`GoogleOAuthTokenProviderTest`)
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
- **`send_push` / Web Push has no test against a real browser or push service (FCM, Mozilla autopush, etc.).**
  The RFC 8291/8188 encryption itself is covered thoroughly (`WebPushCryptoTest` round-trips a full encrypt against
  an independent, from-scratch decrypt reimplementation; `DerTest`/`VapidTokenBuilderTest` verify the ECDH/ECDSA
  primitives against real OpenSSL), but no CI run has ever registered a real subscription in an actual browser,
  sent a push through it, and confirmed a notification appeared — the one thing unit tests structurally can't
  exercise here.

### Mutation testing

`mutation-testing` runs in CI on every PR but is non-blocking (Quality Gate/merge never wait on
it) — right now nobody actually reads its output before merging, which raises the question of
whether the line-coverage push above is proving real test *quality* or just exercising lines.
Needs: someone to actually open the mutation-testing report on a few recent PRs and see what
survives (untested edge cases the coverage number hides), decide a realistic minimum mutation
score, and only then flip the job to blocking — flipping it blind, before knowing the current
baseline, would just make every PR red on day one.

### MFTF/scenario coverage

Full inventory with what's covered and why: `Test/Mftf/SCENARIOS.md`. Every row there is currently ✅ — no open
gaps. Kept as the standing scope check for anything newly added to the module (new trigger/condition/action/
controller/cron gets a row there before it's considered done).

## Full-codebase improvement audit (2026-09-10)

Five independent passes over the whole module (campaign engine, segmentation/RFM/scoring,
communication channels, commerce features, admin platform/UX/API), each grounded in the actual
code rather than guesswork. Not yet scoped/prioritized as a team — this is raw input for that
conversation, organized by domain. Items already covered elsewhere in this file aren't repeated.

### Correctness issues found along the way (not "improvements" — real bugs)

- **Free Gift Offer never actually applies to a cart.** `Model/FreeGiftOffer*.php`,
  `FreeGiftOfferSaveProcessor.php` are pure admin CRUD — there is no quote/checkout observer or
  totals plugin anywhere that reads a configured offer and adds a gift to a cart. A merchant can
  fully configure "spend $100, get 2 gifts" today and nothing ever happens at checkout. This is
  the single biggest gap found in this audit: a fully-built admin feature with no runtime effect.
- **Guest checkout bypasses order-approval entirely.** `Observer/HoldOrderForApproval::execute()`
  returns early when `!$order->getCustomerId()` — since the spend-limit/approval attributes only
  exist on registered customers, anyone can dodge approval by checking out as a guest.
- **`Controller/Adminhtml/FreeGiftOffer/Delete.php` is a GET action** — no form-key CSRF
  protection on a destructive one-click-from-a-crafted-URL action.
- **Approval decision paths save inconsistently** — `rejectByToken()` goes through
  `OrderRepositoryInterface::save()`, `approveByToken()` through the raw resource model's
  `save()`. A plugin wired to `OrderRepositoryInterface::save` fires on reject but silently not
  on approve.
- **`approveByToken()` doesn't re-check order state before applying the token.** If an admin
  manually moved the order (e.g. to Complete/Canceled) between hold and decision, a stale approval
  link can blindly revert its status.
- **Multi-store base URL bug repeated at 3 call sites** — `HoldOrderForApproval`,
  `EscalateStalePendingApprovals`, and `getDecisionLinksById` all resolve "current store" via
  `StoreManagerInterface::getStore()` instead of the order's own store, so decision-link emails
  can point at the wrong storefront in a multi-store setup.
- **The "Scheduled Date/Time" trigger type is already selectable in the admin UI with nothing
  behind it.** `Api\Data\CampaignTriggerInterface::TRIGGER_SCHEDULED_AT`/`TRIGGER_RECURRING_SCHEDULE`
  and `Model\Config\Source\TriggerEvent`'s option list already expose these, but no
  `ScheduledTriggerScanner`/dispatch cron exists — an admin can pick it, save the campaign, and it
  will simply never fire, with zero error anywhere. Needs either the real implementation (see
  "Scheduled (date-based) campaigns" below) or pulling the option out of the UI until it's real.

### Campaign engine (`Model/CampaignDispatcher.php`, `Model/Queue/*`, Flow canvas)

- No suppression/frequency capping — `CampaignDispatcher::dispatch()` fires a matched campaign
  every single time its trigger occurs, with no "don't message this customer more than N times per
  period" anywhere. A customer who repeatedly triggers `tag_added`/`order_placed` gets spammed by
  design.
- No campaign entry dedup — nothing stops a customer mid-flow (waiting on a `delay_minutes`
  resume) from re-entering the same campaign from scratch on a repeat trigger; `ordo_campaign_
  scheduled_action` has no uniqueness guard per customer+campaign.
- No A/B/split testing on actions and no campaign-level funnel analytics (open/click/conversion
  tied back to a specific campaign) — dispatch pass/fail is logged, but nothing answers "did this
  campaign actually work."
- No time-zone-aware quiet hours for a campaign as a whole (only per-channel opt-out exists via
  `ConsentManager`) — a trigger-based send can land at 3am local time.
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
- No behavioral/event-based cohort conditions (browsing, cart, wishlist events) — only
  `purchased_sku`/`purchased_category` exist for behavior; a real CDP's segmentation lives on
  events like this.
- No segment exclusion operator ("customers in A but NOT in B") — only inclusion (`in_segment`)
  exists today; cheap to add given the resolver already computes full ID sets.
- Group condition editor's JSON fallback (for `in_segment`, `loyalty_tier_at_least`,
  `nps_score_at_least`) silently becomes `{}` on malformed JSON with no validation feedback — a
  non-technical marketer gets a condition that quietly matches nothing.
- "Estimated Audience Size" panel doesn't warn when the on-screen conditions are unsaved — a click
  on Refresh returns the live count for the *last saved* definition, easy to mistake for reflecting
  current edits.
- `RfmCalculator::getAggregatesForAllCustomers()`/`getAllCustomerIds()` have no pagination/streaming
  — a full `sales_order` GROUP BY and full `customer_entity` SELECT into memory on every resolve;
  fine at 10-20k customers, a real cost driver at 100k+.
- `Cron\SyncAdAudiences`/`GoogleAdsSyncClient::addOperations()` sends every hashed email as one
  single unbatched API call — Google Ads' documented per-request operation limits would make a
  large segment fail outright, not just run slowly.
- `Cron\TagInactiveCustomers`'s untag pass uses `in_array()` against a plain PHP array inside a
  loop — effectively O(n²) in the worst case after a big win-back wave untags most of the inactive
  population; a flipped lookup set fixes it cheaply.
- Fail-closed semantics for event-only conditions (`order_total_gte`, `visitor_tag`) used inside a
  Segment are invisible to the admin — they silently zero out an AND-segment with no UI
  explanation that these condition types only make sense in Campaign trigger context.

### Communication channels (Email/SMS/WhatsApp/Push)

- No unified suppression/frequency-capping layer across channels at all — `ConsentManager` is a
  binary per-channel opt-in/opt-out with no "max N messages/day" or quiet-hours concept; a customer
  matching several campaigns in one dispatch tick can be emailed, texted, WhatsApp'd, and
  push-notified back to back.
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
- No retry/backoff for a failed send anywhere — every channel action catches `Throwable`, logs, and
  moves on permanently; `Cron/RunScheduledCampaignActions.php`'s own docblock admits "a row that
  failed stays failed; there's no retry queue for this yet." A transient provider 5xx permanently
  drops that message.
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
- Reorder cycles has no on-demand recalculation endpoint, unlike the equivalent pattern segments
  just got via `SegmentAudienceSizeRecalculator`/`Controller/Adminhtml/Segment/AudienceSize.php` —
  an inconsistency between two conceptually similar "cached, periodically-recalculated metric"
  features worth reconciling.
- No `fields`/sparse-fieldset support and no documented rate limiting anywhere in `API.md`; the
  anonymous order-approval endpoints (`.../approve`, `.../reject`) are token-guarded but not
  rate-limited against brute-forcing a token guess.

## Scheduled (date-based) campaigns and a real calendar view

Raised directly after renaming "Campaign Calendar" to "Campaign Action Timeline" (it showed
relative delay offsets, not dates — every trigger today fires on a customer event, not a fixed
schedule, so a literal calendar would have been empty): **should a campaign be able to fire at a
specific date/time instead of only on a customer event?**

- A new trigger type, e.g. `scheduled_at` (fixed date/time) or `recurring_schedule` (cron-like:
  every Monday, first of the month, etc.) — `CampaignTriggerInterface` and `TriggerEvent`'s option
  source are the two places a new trigger type is wired in.
- A cron that scans for campaigns whose scheduled time has arrived and fires them the same way
  `CampaignDispatcher` fires event-based triggers today, so the rest of the pipeline (conditions,
  actions, delay_minutes chaining) needs no change.
- Only once that exists does an actual date-grid calendar view become meaningful — plotting when
  each scheduled campaign will (or did) fire. Worth revisiting whether "Campaign Action Timeline"
  should grow a calendar-view toggle at that point, or stay a separate screen.

Needs a scoping decision before implementation: is a one-off scheduled send (e.g. "Black Friday
email, Nov 28 9am") or a recurring schedule (e.g. "every Monday") the more valuable first case.

## Visual rule builder for Segment/Campaign conditions (AND/OR groups)

Raised after the Segment condition form got dedicated per-type fields (no more raw JSON for the
common condition types): the remaining gap is structural, not cosmetic. Both `Segment` and
`Campaign` conditions are a **flat list always joined by AND** — `SegmentSaveProcessor`/
`CampaignSaveProcessor` delete-and-reinsert a plain row-per-condition, and the matching logic
(`SegmentMemberResolver`, campaign condition evaluation) has no concept of a nested group or an
OR join. A real "(A AND B) OR (C AND D)" builder needs, in order:

- A schema change: either a `group_id`/`parent_group_id` + `join_type` (AND/OR) column set on the
  condition tables, or a switch to storing the whole tree as one JSON Logic-style blob per
  segment/campaign (trades relational queryability for structural flexibility — worth an explicit
  decision, not a default).
- Matching logic in `SegmentMemberResolver` (and wherever campaign conditions are evaluated) to
  walk the group tree instead of AND-ing a flat list — the actual segment-membership SQL/PHP
  changes shape, not just the form.
- Only then does the admin UI part make sense: a nested drag-and-drop group builder with an
  ALL/ANY toggle per group and an "Add a condition group" action. Off-the-shelf JS toward this:
  `react-querybuilder` (the closest to a de-facto standard; exports directly to JSON Logic) or,
  scoped down to fit Magento's own `Magento_Ui/js` component style rather than pulling in React,
  a bespoke tree UI following the same field-per-type pattern the current switcherConfig already
  uses, just nested.
- A live "estimated audience size" counter next to the segment builder — needs a fast
  count-only path through the same matching logic above; naive re-running the full member
  resolver on every keystroke would be too slow to feel live.
- Separately (independent of the above): the "Bulk actions on current members" block sharing the
  same form/page as the condition builder was flagged as a mis-grouping risk (an action button
  living directly below unrelated condition rows). Worth its own tab/section or a confirmation
  step before this gets built out further, regardless of when/whether the AND/OR rework happens.

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
