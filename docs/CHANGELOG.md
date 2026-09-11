# Changelog

All notable changes to this module are documented here. Format loosely
follows [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Changed

- **Centralized the admin CSS color tokens duplicated across `dashboard.css`, `segment-form.css`,
  `flow.css`, and `free-gift-offer-form.css` into one shared file.** New
  `view/adminhtml/web/css/_tokens.css` defines `:root` custom properties (`--ordo-color-primary`,
  `--ordo-color-accent`, `--ordo-color-accent-dark`, `--ordo-color-ink`, `--ordo-color-muted`,
  `--ordo-color-border`), loaded via a `<css src="Ordo_Automation::css/_tokens.css"/>` layout
  declaration ahead of each of the 4 files (this module's existing CSS-inclusion mechanism — no
  `@import` precedent existed to follow instead). Each token's value is the hex that was already
  the most-used one for that role across the 4 files (`#7c3aed` for primary purple: 11 occurrences
  vs. 1 each for `#4f46e5`/`#4338ca`; `#1b1f2a`, `#6b7180`, `#e4e7ee` for ink/muted/border, all
  already identical across files), so this is purely structural — no computed color changed
  anywhere.

### Corrected

- **ROADMAP.md's "Free Gift Offer never actually applies to a cart" audit finding was wrong.**
  That audit pass grepped only for `*FreeGiftOffer*`-named files and missed the real cart
  integration, which lives under different names: `Model/FreeGiftManagement.php` (the
  `FreeGiftManagementInterface` API — `getEligibility`/`selectGifts`), `Model/FreeGiftEligibility.php`,
  `Model/FreeGiftSelection.php`, and `Observer/TrimExcessFreeGifts.php` (drops gifts that no longer
  fit after the cart total drops). Fully wired (`etc/webapi.xml`, `etc/di.xml`) and already tested
  (`Test/Unit/Model/FreeGiftManagementTest.php`, `Test/Integration/FreeGiftManagementScenarioTest.php`,
  `Test/Api/FreeGiftApiTest.php`) — this was a complete, shipped feature the whole time. Removed
  from ROADMAP.md's audit findings and Tier 1 priority list.

### Added

- **New Segment Overlap admin page**, closing the segmentation ROADMAP.md gap where there was no
  way to see "how many customers are in both Segment A and B" — useful for avoiding message
  fatigue from campaigns that unknowingly target overlapping audiences. A new
  `Controller\Adminhtml\Segment\Overlap` page (linked from a new dashboard card) lets an admin pick
  any two segments; `Controller\Adminhtml\Segment\OverlapCompute` (an AJAX endpoint following the
  same shape as the existing `Segment\AudienceSize` one) calls
  `SegmentMemberResolver::getMatchingCustomerIds()` once per segment and returns each segment's
  size plus the `array_intersect()`/`array_diff()`-derived intersection and unique-remainder
  counts — no new resolver logic needed, purely new UI + a thin controller.

- **Time-zone-aware campaign quiet hours**, closing the campaign engine's "No time-zone-aware
  quiet hours" gap. New `ordo_timezone` customer attribute (`AddCustomerTimezoneAttribute`, an
  IANA zone string, e.g. `Europe/Warsaw`) and a `quiet_hours` admin config section
  (enabled/start_hour/end_hour, opt-in and off by default). `Model\Campaign\CustomerTimezoneResolver`
  resolves a customer's timezone from that attribute, falling back to the store's configured
  `general/locale/timezone` when unset — nothing auto-detects a real timezone (no geo-IP/browser
  reporting), that's an explicit, named boundary. `Model\Campaign\QuietHoursCalculator` is pure
  hour-of-day window logic (handles overnight-wraparound windows like 21-8). New
  `Model\Campaign\QuietHoursGate`, called from every Send* action right alongside the existing
  `FrequencyCapGate` — a send due during the customer's local quiet hours is deferred until they
  end instead of sending immediately, by reusing the exact `ordo_campaign_scheduled_action`
  mechanism `delay_minutes` actions already use (`CampaignDispatcher::deferActionUntil()`, a new
  public counterpart to the existing private `scheduleResume()`, sharing the same underlying
  write path). Because it's the same mechanism, `CampaignEntryGuard`'s pending-entry dedup and
  `Cron\RunScheduledCampaignActions`'s resume both apply to a deferred send automatically, with no
  extra code either side. `CampaignDispatcher::runOneAction()` now stamps the action's own
  `entity_id` into `$context['ordo_action_id']` — the one piece of plumbing a Send* action needed
  to identify itself to the gate. Known limitation (same shape as `delay_minutes` inside split
  variants): a synthetic split-variant action has no real `ordo_campaign_action` row to defer
  against, so quiet hours don't apply to those — the gate proceeds with the send rather than
  silently dropping it.
- **Campaign entry dedup**, closing the campaign engine's "No campaign entry dedup" gap:
  `ordo_campaign_scheduled_action` gained a nullable `customer_id` column (denormalized from the
  dispatch context at `scheduleResume()` time, since the customer identity previously only lived
  inside the row's opaque JSON `context` blob) plus a `(campaign_id, customer_id, executed_at)`
  index. New `Model\Campaign\CampaignEntryGuard::hasPendingEntry()` queries for a still-unclaimed
  row via a new `Collection::addPendingForCampaignAndCustomerFilter()`, and is checked by
  `CampaignDispatcher::dispatch()`/`dispatchScheduledTrigger()` before entering a campaign from
  action index 0 — a customer already mid-flow (waiting on a `delay_minutes` resume) in that exact
  campaign is skipped for this dispatch instead of accumulating a second, independent action chain
  in parallel. Deliberately not a DB unique constraint (unlike this codebase's usual dedup
  pattern): a completed chain leaves behind a row with `executed_at` set, and a customer can
  legitimately re-enter the same campaign on a future, unrelated trigger — only *unexecuted* rows
  count as "still in-flight". `resumeScheduledAction()` itself remains unguarded, since it
  continues an already-entered chain rather than starting a new one.
- **Tier 4 scale-hardening fixes — now fully closed**: `GoogleAdsSyncClient::addOperations()` now
  chunks a segment's hashed emails into batches of 10,000 identifiers (Google Ads' documented
  per-request Customer Match limit) instead of sending the whole segment as a single, oversized
  (and rejected) `:addOperations` call — an empty segment now makes zero `:addOperations` calls
  instead of one no-op call. `CalculateReorderCycle`'s order-history query is now bounded to a
  730-day lookback (`MAX_LOOKBACK_DAYS`) instead of rescanning a store's entire historical order
  volume on every cron run. `GoogleMerchantFeedGenerator::generate()` now pages through the
  product collection 500 products at a time (`fetchProductsByPage()`) instead of loading the whole
  enabled/visible catalog into memory in a single query before rendering a single `<item>`.
  `RfmCalculator::getAllCustomerIds()`/`getAggregatesForAllCustomers()` now page through
  `customer_entity`/`sales_order` in 5,000-row chunks (`SCAN_PAGE_SIZE`) instead of a single
  unbounded `fetchCol()`/`fetchAll()` — the final in-memory id list/aggregate map is unchanged in
  shape (percentile ranking inherently needs the whole set at once), this only bounds each SQL
  round trip's size.
- **`event_occurred` admin form UI (behavioral segmentation, Phase 4)**, closing the ROADMAP.md
  Tier 3 "behavioral/event-based segmentation" item in full — `ordo_segment_form.xml` gained
  dedicated `event_type` (select, `Model\Config\Source\EventType`), `event_key` (optional SKU),
  and `within_days` fields, plus a new switcherConfig rule showing/hiding them (mechanical:
  every one of the existing 17 rules also gained 3 new hide-actions for these fields, same
  "switcherConfig has no else semantics" pattern already documented on the form). A marketer can
  now actually build "added product X to cart in the last 14 days" without touching the API/DB
  directly. `Model\Segment\SegmentSaveProcessor::DEDICATED_PARAM_FIELDS` gained the 3 new plain
  fields (no special JSON handling needed, unlike `split`'s `variants`). The nested-group inline
  editor (`segment-group-modal.js`) needed no change — an unlisted type in its `VALUE_FIELD_BY_TYPE`
  map already falls back to its "Advanced (JSON)" textarea, which is exactly right for a 3-field
  condition like this one.
- **`event_occurred` condition type (behavioral segmentation, Phase 2)**, closing the second phase
  of ROADMAP.md's Tier 3 "behavioral/event-based segmentation" item — usable in campaign triggers
  immediately; segment-form admin UI and `SegmentMemberResolver` bulk-membership wiring are later
  phases (the bulk case is actually already wired below, ahead of the form fields). Params:
  `{"event_type": "cart_add"|"wishlist_add", "event_key": "24-MB01" (optional), "within_days": 14}`.
  New `Model\Event\EventOccurredResolver` (per-customer `hasEventOccurred()` + bulk
  `getCustomerIdsWithEvent()`), mirroring `Model\Purchase\PurchasedProductResolver`'s own
  single-customer/whole-base pairing, queried live against `ordo_visitor_event` (populated by
  Phase 1's `TrackCartAdd`/`TrackWishlistAdd`). New `Model\Campaign\Condition\EventOccurred`
  (per-customer, wired into `ConditionPool` via `etc/di.xml`) and a new
  `SegmentMemberResolver::resolveEventOccurred()` case (set-level, for segment audience
  size/bulk actions). Caveat documented in code: `within_days` beyond
  `Cron\PruneVisitorEvents`'s retention window (default 7 days) silently stops matching pruned
  rows — not validated/capped in this pass.
- **Flow canvas UI for A/B/split testing**, closing Part A Phase 3 of the ROADMAP.md Tier 3 "A/B
  testing" item — the backend (Phase 2) already ran a `type = 'split'` action correctly, but a
  campaign could only get one via a direct API/DB row insertion; there was no way to actually
  build one in the admin UI. `Block\Adminhtml\Campaign\Edit\Flow::getActionTypes()` now appends
  the reserved `split` pseudo-type to the Flow canvas's own action type list (it's still
  deliberately not an `ActionPool` entry — `CampaignDispatcher::runSplit()` still intercepts it
  before that lookup), and `getFieldsConfig()['action']['split']` describes its one field
  (`variants`) with a new `type: 'variant_list'` marker. `campaign-flow-editor.js` gained a whole
  interactive sub-editor for it (`renderVariantEditor()`/`renderVariantBlock()`/
  `renderVariantActionRow()`) — add/remove variant, each with its own weight and a nested action
  list (a type `<select>` covering every real action type except `split` itself, plus a JSON
  params field per nested action) — serialized into one hidden `data-field="variants"` input kept
  in sync on every change, so `collectRows()` needs no changes of its own. Deliberately does NOT
  reuse the per-type dedicated-field rendering `renderFields()` gives top-level nodes for this
  nested editor: that would emit `data-field`-marked inputs nested inside the split node's own
  subtree, which `collectNodeFields()`'s deep `[data-field]` search would then incorrectly fold
  into the split action's own row. `Model\Campaign\CampaignSaveProcessor`'s
  `DEDICATED_PARAM_FIELDS` gained `variants` with special JSON-decode handling (every other
  dedicated field is a plain string) so the posted JSON lands in `params.variants` as a real
  array, not a JSON-string-inside-JSON. New label: `Model\Campaign\TypeLabels::ACTION_LABELS['split']`.
- **Cart-add/wishlist-add event tracking (behavioral segmentation, Phase 1 — event capture
  only)**, closing the first phase of ROADMAP.md's Tier 3 "behavioral/event-based segmentation"
  item. New `Observer\TrackCartAdd` (on `checkout_cart_product_add_after`) and
  `Observer\TrackWishlistAdd` (on `wishlist_product_add_after`) log `cart_add`/`wishlist_add` rows
  into the existing `ordo_visitor_event` table via `Model\VisitorEventLogger` — the same table
  `page_view`/`product_view`/`category_view` already populate, no schema change. Logged-in
  customers only for v1 (an anonymous cart-add has no campaign use case yet — there's no channel
  to message an anonymous visitor through), keyed by SKU (`event_key`), matching `purchased_sku`'s
  own identity choice. No condition type or admin UI yet to actually segment on this data — that's
  the next phase; this closes purely the "is anything captured at all" gap.
- **A/B/split testing on campaign actions (backend only, no Flow canvas UI yet)**, closing Part A
  Phase 2 of the ROADMAP.md Tier 3 "A/B testing" item. A `CampaignAction` row with `type = 'split'`
  carries `{"variants": [{"key": "a", "weight": 50, "actions": [...]}]}` in `params` — the same
  reserved-pseudo-type shape the `group` condition type already uses, not a new `ActionPool`
  entry or schema change. `Model\Campaign\SplitVariantSelector::selectVariant()` picks a variant
  deterministically per `(campaign_id, split_action_id, customer/visitor/email identity)` via a
  stable hash, so the same customer always lands in the same variant on a repeat dispatch; the
  chosen key is written into `$context['ordo_split_assignments']`, which survives a
  `delay_minutes` resume elsewhere in the chain (persisted in
  `ordo_campaign_scheduled_action.context`) without ever re-rolling. `CampaignDispatcher::runSplit()`
  builds synthetic (never-persisted) `CampaignAction` rows for the chosen variant's own action
  list and runs them through the existing `runActionsFrom()` pipeline, stamping
  `$context['ordo_split_variant']` so `Send*` actions attribute their `ordo_message_log` row to
  the right variant (populating the funnel's per-variant breakdown added in Phase 1). Known
  limitation: a variant's own actions can't have their own `delay_minutes` yet (no schema support
  for a synthetic action's scheduled-resume FK) — only top-level actions after a split can pause
  the chain. Campaigns using `split` are configurable for now only via direct API/DB row
  insertion, same carve-out `group` conditions already have — Flow canvas UI is Phase 3.
- **Per-campaign funnel analytics (sent → delivered → opened → clicked → converted)**, closing
  Part A Phase 1 of the ROADMAP.md Tier 3 "A/B testing + per-campaign funnel analytics" item
  (split/variant testing itself is Phase 2, still open). `ordo_message_log` gained nullable
  `campaign_id`/`variant` columns, populated via `CampaignDispatcher::runActionsFrom()`/
  `resumeScheduledAction()` stamping `$context['campaign_id']` before every action runs, read by
  `SendEmail`/`SendSms`/`SendWhatsApp`/`SendPush` and `FrequencyCapGate` and passed through to
  `Model\Sms\MessageLogWriter`. A new `ordo_message_log_event` table (via `MessageLogEvent`/
  `MessageLogEventWriter`) records `opened`/`clicked` events — `Controller\Email\StatusCallback`
  now handles SendGrid's `open`/`click` events (previously silently discarded) by writing a new
  event row rather than overwriting `ordo_message_log.status`, so an earlier `delivered` signal
  survives. A new `ordo_campaign_outcome_log` table + `Model\CampaignOutcomeLogger` (modeled on
  `Model\TriggerOutcomeLogger`) + `Observer\RecordCampaignOutcome` (on `sales_order_place_after`,
  same first-plausible-match attribution as `RecordTriggerOutcome`) track conversions —
  `MessageLogWriter::recordSent()` calls `CampaignOutcomeLogger::logSent()` whenever a send is
  campaign-attributed. `Model\CampaignFunnelStats` composes both sources into a funnel view,
  rendered on each campaign's edit page (`Block\Adminhtml\Campaign\FunnelViewModel` +
  `campaign/funnel.phtml`) and summarized as one new dashboard card
  (`DashboardViewModel::getCampaignFunnelSummary()`) — no new menu item, per the existing flat-menu
  convention.
- **Flow canvas UI for the "Scheduled Date/Time"/"Recurring Schedule" trigger types**, closing the
  ROADMAP.md Tier 0 item. `Block\Adminhtml\Campaign\Edit\Flow::getFieldsConfig()` now has a
  `'trigger'` entry (a `scheduled_at` datetime input, a `cron_expression` text input for
  `recurring_schedule`), and `campaign-flow-editor.js` renders/collects them the same way
  condition/action nodes already do — `triggerNodeHtml()` gained the same `data-params` +
  `.ordo-flow-fields` shape `editableNodeHtml()` uses, `bindNode()` no longer early-returns for
  trigger nodes, and `collectRows('trigger')` now reads a row's dedicated fields instead of only
  its `trigger_event`. The backend (`ScheduledTriggerScanner`/`DispatchScheduledCampaignTriggers`)
  and `CampaignSaveProcessor::DEDICATED_PARAM_FIELDS` already supported both fields; picking either
  trigger type in admin previously saved a trigger that could never become due, since there was no
  way to type in the date/cron expression.
- **Cross-channel frequency cap for campaign sends**, closing the ROADMAP.md Tier 3 "unified
  suppression/frequency capping across all channels" item. New `Model\Campaign\FrequencyCapManager::hasCapacity()`
  - opt-in (disabled by default), counts `ordo_message_log` rows across Email/SMS/WhatsApp/Push
  combined for a configurable rolling window (default: max 5 messages / 24h), since the point is
  capping total contact volume, not per-channel volume. Checked in
  `SendEmail`/`SendSms`/`SendWhatsApp`/`SendPush` right after the existing consent check; a
  customer over the cap is skipped and recorded as a new `MessageLog::STATUS_SUPPRESSED` outcome
  (distinct from `opted_out` - they didn't withdraw consent, they're just over the volume cap for
  now). New config: Stores > Configuration > Ordo Automation > Frequency Cap (cross-channel).
- **`not_in_segment` condition type** — the exclusion counterpart to `in_segment` ("customers in A
  but NOT in B"), closing the ROADMAP.md Tier 1 "segment exclusion operator" item. New
  `Model\Campaign\Condition\NotInSegment` (per-customer, shared by Campaign conditions and
  `SegmentMatcher` via `ConditionPool`) and `Model\Segment\SegmentMemberResolver::resolveNotInSegment()`
  (set-level, for bulk actions/audience size — the full customer universe minus the target
  segment's members). Both fail closed on a self-referencing cycle rather than matching everyone.
  Picked up automatically by the admin Type dropdown (`Model\Config\Source\ConditionType` reads
  `ConditionPool`).
- **On-demand "Recalculate Now" for Reorder Cycle**, closing the ROADMAP.md Tier 2 inconsistency
  with segments' own on-demand refresh. `Controller\Adminhtml\ReorderCycle\RecalculateNow` mirrors
  `Controller\Adminhtml\ProductFeed\RefreshNow`'s existing pattern; `Cron\CalculateReorderCycle::execute()`
  now returns the processed count (was `void`) so the controller can report it.
- **Retry/backoff for failed channel sends**, closing the ROADMAP.md Tier 1 item. New
  `Model\Campaign\Action\SendRetrier` retries the actual provider call (SMTP send, Twilio/Graph
  API/push HTTP call) up to 3 times with exponential backoff, used by
  `SendEmail`/`SendSms`/`SendWhatsApp`/`SendPush`. Excludes exceptions that mean "this destination
  is permanently invalid" (SMS opt-out, dead push subscription) from the retry via an optional
  `shouldRetry` predicate — those fail fast on the first attempt instead. Covers the in-process
  retry case only; `Cron\RunScheduledCampaignActions.php`'s cross-cron retry-queue gap remains open.
- **Nested AND/OR condition groups for Segment and Campaign conditions**, closing the
  ROADMAP.md "Segment/Campaign condition builder follow-ups" item (the "Bulk actions" mis-grouping
  sub-item is covered separately below). A reserved `'group'` pseudo-type holds its own nested
  `{"logic": "all"|"any", "conditions": [...]}` blob (one level of nesting), matched by
  `SegmentMemberResolver`/`CampaignDispatcher` recursing into `resolveGroup()`, and built in the
  admin UI by `segment-group-modal.js` (inline, not an actual modal despite the filename — nested
  `dynamicRows` didn't work, see its own docblock). Covered by
  `Test/Mftf/Test/AdminCreateSegmentWithNestedGroupConditionTest`.
- **Estimated audience size for segments**, closing the ROADMAP.md follow-up. Two parts:
  - `ordo_segment.estimated_audience_size`/`audience_size_computed_at` (new columns) back a
    grid column pair, refreshed every 15 minutes by
    `Cron\RecalculateSegmentAudienceSizes`/`Model\Segment\SegmentAudienceSizeRecalculator` for
    every segment at once — a cached snapshot rather than a live per-row resolve, since the grid
    can list many segments at a time and `SegmentMemberResolver` runs real aggregate queries per
    condition.
  - The segment edit page gets its own live, on-demand counter
    (`Block`/`Controller\Adminhtml\Segment\AudienceSize`, `segment-audience-size.js`) that
    re-resolves the segment's saved conditions via the same `SegmentMemberResolver` on an
    explicit refresh click — exact rather than a snapshot, cheap enough for one segment in view.

### Changed

- **`resumeScheduledAction()` no longer loads and linear-scans every action in a campaign to find
  the one row it's resuming**, closing the campaign engine's "loads and materializes *all* of a
  campaign's actions just to find one row's index" audit finding. It now runs one small extra
  query first (`campaign_id` + `entity_id` filter, `getFirstItem()`) to get just the resume row's
  own `sort_order`, then filters the main `ordo_campaign_action` query to `sort_order >=` that
  value instead of the whole campaign — actions that already ran before the resume point are never
  loaded at all this time around, rather than being fetched and then discarded by the scan. Same
  behavior otherwise: `runActionsFrom()` still gets the full ordered remainder from the resume
  point onward.
- **`CampaignRepository::save()`/`delete()` now flush per-trigger-event cache tags instead of one
  flat tag**, closing the campaign engine's "thrashes and reverts to a full DB scan far more than
  necessary" gap. `CampaignDispatcher::campaignIdsForTrigger()`'s cached lookup used to be tagged
  only with the flat `CampaignDispatcher::CACHE_TAG`, so saving or deleting *any* campaign flushed
  *every* trigger event's cached lookup, even ones the saved campaign has nothing to do with — on
  an install with many campaigns edited frequently, this thrashed the whole cache far more than
  necessary. Each cached entry is now also tagged with a new
  tag built from the now-public `CampaignDispatcher::CACHE_KEY_PREFIX . $triggerEvent`
  (`ordo_campaign_trigger_{$triggerEvent}`),
  and `CampaignRepository` reads the saved/deleted campaign's own `ordo_campaign_trigger` rows
  before AND after the write, unions the two trigger-event sets (covering a trigger event added
  and removed in the same save), and flushes only those tags. The flat tag is still stamped on
  every cache entry too, so `CampaignTriggerRepository`, `Controller\Adminhtml\Campaign\Delete`,
  and `Campaign\CampaignSaveProcessor` — which still flush it wholesale — keep invalidating
  everything they always did; only `CampaignRepository`'s own save/delete path got narrower.
- **`Block\Adminhtml\Dashboard\DashboardViewModel` now caches its five `->getSize()` collection
  counts** (total/enabled campaign count, reorder cycle count, free gift offer count, and
  per-trigger campaign count) behind a new `cachedCount()` helper backed by
  `Magento\Framework\App\CacheInterface`, 60-second TTL per key (`ordo_dashboard_count_*`) —
  closing the "4+ separate uncached COUNT queries on every page load" half of the dashboard
  ROADMAP.md item; the drill-down half of that item is still open.
- **`Cron/CalculateReorderCycle::execute()` now estimates the reorder interval as a median of the
  per-SKU order-to-order gaps instead of a plain arithmetic mean**, closing the ROADMAP.md gap
  where a single anomalous gap (a customer pausing for months, or a one-off bulk restock that
  skips several normal cycles) skewed the whole prediction disproportionately, since a mean has no
  resistance to outliers. No new dependency — sorts the (already-in-memory) interval list and
  takes the middle value, or the average of the two middle values for an even count. The `< 1`
  same-day-purchase skip, `MIN_ORDERS_TO_DETECT_PATTERN`, and `upsertCycle()` call are unchanged.
- **SendGrid webhook status updates are now rank-based instead of last-write-wins**, closing the
  "no ordering/idempotency guard against provider redelivery" gap: `Controller\Email\StatusCallback`
  has no per-event timestamp column to compare against, and SendGrid's account-wide Event Webhook
  can redeliver events out of order, so a late-arriving redelivered `delivered` event used to be
  able to regress an already-`bounced`/`failed`/`opted_out` message's status backward. A new
  `STATUS_RANK` precedence table (`sent` < `delivered` < the three terminal outcomes, which rank
  equal to each other) plus a small `isStatusDowngrade()` helper now make `applyStatus()` skip
  (and log) any incoming status that ranks below the log row's current one, while a same-or-higher
  rank — including the normal in-order `sent` → `delivered` → `bounced` sequence — still updates as
  before.
- **Separated 4 admin screens from the broader ACL resources they were incorrectly reusing**,
  closing the ROADMAP.md "ACL resources are shared across functionally distinct screens" finding.
  Message Log, Reorder Cycles (index + recalculate-now), and Product Feed refresh no longer gate
  on `Ordo_Automation::campaigns`; RFM no longer gates on `Ordo_Automation::segments`. Each now has
  its own dedicated `etc/acl.xml` resource: `Ordo_Automation::message_log`,
  `Ordo_Automation::reorder_cycle`, `Ordo_Automation::product_feed`, `Ordo_Automation::rfm`.
  **Behavior change, not a silent no-op**: any existing custom admin role granted only the
  broader `campaigns` or `segments` resource will lose access to these 4 screens until an admin
  explicitly re-grants the corresponding new resource.
- **Unified `CampaignDispatcher`/`SegmentMatcher`'s duplicated AND/OR/nested-group condition
  evaluator**, closing the campaign engine's "second, independent implementation" gap. Both
  `evaluateList`/`evaluateOne`/`evaluateGroup`/`asStringKeyedArray` were textually identical
  (down to comments) in each class — now extracted into a new
  `Model\Condition\ConditionGroupEvaluator`, injected into both. A pre-extraction audit found no
  accidental drift between the two: they already agreed on every tested case except one
  deliberate, documented asymmetry (a campaign with zero top-level conditions fires
  unconditionally; a segment with zero conditions never matches), which each caller still applies
  itself before ever delegating to the shared evaluator — pure refactor, no behavior change.
  `Model\Segment\SegmentMemberResolver`'s own separate, third (set-level, `int[]`-returning)
  reimplementation of the same group-walk shape is a distinct, bigger unification question, not
  attempted here.
- **SendGrid webhook `unsubscribe`/`spamreport`/`group_unsubscribe` now record a real consent
  opt-out**, closing the ROADMAP.md Tier 1 item. These three used to fall into the "unhandled,
  silently skipped" bucket — a one-click unsubscribe or spam complaint from the recipient's own
  mailbox provider never reached `ConsentManager`, so `send_email` kept mailing someone who had, in
  every real sense, opted out. Mapped to `MessageLog::STATUS_OPTED_OUT` and, when the log row has
  a known `customer_id`, calls `ConsentManager::setConsent(..., ConsentChannel::Email, false, ...)`.
- **Unsaved-changes warning on the "Estimated Audience Size" refresh panel**, closing the
  ROADMAP.md Tier 2 item. The count this panel shows is always resolved from the segment's *saved*
  conditions, so an admin who edits a condition then clicks Refresh without saving first used to
  see a live-looking number that actually still reflected the old, saved definition. Any
  input/change event anywhere on the page outside this panel (and the unrelated bulk-actions
  panel) now marks the page dirty and shows a warning next to the count.
- **"Bulk actions on current members" no longer reads as one continuous step with the segment's
  condition builder**, closing the ROADMAP.md mis-grouping follow-up. `bulkactions.phtml`'s panel
  is now a native `<details>`/`<summary>` (collapsed by default, no JS needed for the
  expand/collapse itself), visually separated with a red top border and extra margin, and its
  `+`/`-` toggle icon reinforces that it's a distinct, deliberate action rather than the next
  field in the form above it.
- **Mutation testing is now blocking**, closing the ROADMAP.md follow-up. 5 consecutive CI runs
  across main and feature branches all landed at the identical 4943/7043 killed+errored+timed-out
  mutants (~70.2% MSI, `coveredMsi` the same since `Not Covered` is 0) — a stable baseline, not
  noise. `infection.json5`'s `minMsi`/`minCoveredMsi` set to 70 (a couple points under that
  baseline, so ordinary mutant-selection variance across runs doesn't fail a PR with no real
  regression); `coverage.yml`'s `mutation-testing` job no longer has `continue-on-error`, and
  `main`'s required status checks now include it.
- `.github/workflows/coverage.yml`: PHP and JS coverage used to run sequentially as two halves
  of one `coverage` job, gating the PR check on their combined runtime. Split into parallel
  `php-coverage` / `js-coverage` jobs, plus a `sonar` job that `needs` both and downloads their
  `clover.xml`/`lcov.info` artifacts to run the scan. `mutation-testing` was already a separate,
  parallel job.
- `main` branch protection now requires `unit-tests`, `static-analysis`, `rector`,
  `coding-standard`, `php-coverage`, `js-coverage`, and `sonar` to pass (`mutation-testing`
  stays non-blocking, `continue-on-error: true`); `allow_auto_merge` and
  `delete_branch_on_merge` are on. See AGENTS.md's "PRs auto-merge once CI is green" for the
  workflow this enables.

### Fixed

- `Cron\TagInactiveCustomers`'s untag pass used `in_array()` against a plain PHP array inside a
  loop, effectively O(n²) after a big win-back wave untags most of the inactive population.
  Closes the ROADMAP.md Tier 2 item — flipped into a lookup set once before the loop instead.
- `Test/js/free-gift-offer-form.test.js`'s `sleep()` test asserted `Date.now() - start >= 10`,
  which depends on real wall-clock timing and was flaky on a loaded CI runner (failed at least
  once in CI). Rewritten to stub `setTimeout` and assert the actual contract instead: `sleep()`
  schedules a callback with the given delay, and its promise resolves only once that callback
  fires — no dependency on real elapsed time.
- `AdminCreateSegmentWithNestedGroupConditionTest`'s `dontSeeElement` used a bare CSS class
  selector (`.ordo-group-manage-button`), which routes Codeception's WebDriver module through
  Selenium's native "class name" locator strategy — that strategy threw `MalformedLocatorException`
  on this CI's driver/Selenium combination even though the class name itself was syntactically
  valid. Fixed by rewriting the selector as `[class~='ordo-group-manage-button']`, an attribute
  selector that forces the CSS selector engine instead. Verified locally against a live
  Magento/Selenium stack (`vendor/bin/mftf run:test AdminCreateSegmentWithNestedGroupConditionTest`
  now passes).
- `send_push` was missing from `Block\Adminhtml\Campaign\Edit\Flow::getFieldsConfig()` and
  `Model\Campaign\TypeLabels` — the action itself worked end to end (confirmed against a real
  browser subscription and a real push service delivery), but the Flow canvas editor had no
  dedicated title/body/url fields for it, only the generic JSON fallback every unlisted type gets.
- The Flow canvas's connection arrowheads (`campaign-flow-editor.js`'s injected SVG `<marker>`)
  rendered detached from, and misaligned with, the connection line — `markerUnits` defaulted to
  `strokeWidth`, which Drawflow's connection paths don't set consistently, so the marker's actual
  rendered size (and therefore its position relative to the path's endpoint) varied per
  connection. Fixed with `markerUnits="userSpaceOnUse"` and an explicit absolute size.
- **SSRF via a customer-controlled Web Push `endpoint`** — `Controller\Track\RegisterPushSubscription`
  persisted whatever URL a client supplied with zero validation, and `Model\Push\PushSender` later made a
  real server-side HTTP request to it (carrying a VAPID `Authorization` header) on every subsequent
  `send_push` campaign send — an attacker could register an internal-only host (e.g. a cloud metadata
  endpoint) as their "push subscription" and have the server request it later. Fixed with
  `Model\Push\PushEndpointValidator` (HTTPS-only, rejects private/loopback/link-local IP ranges after
  resolving the hostname), enforced both at registration and again immediately before every send (defends
  against DNS rebinding between the two).
- **Push-subscription hijack via CSRF for logged-in customers** — `RegisterPushSubscription` disabled CSRF
  entirely, matching `Controller\Track\Event`'s own anonymous-analytics trust model, but unlike `Event` it
  binds an attacker-supplied endpoint/keys to a real, authenticated `customer_id` — a cross-site page could
  register its own device against a logged-in victim's account, then receive every personalized `send_push`
  campaign (cart reminders, discount codes) meant for that customer. Fixed by requiring the request's
  Origin (falling back to Referer) to match this site's own host whenever a customer is logged in;
  anonymous registrations are unaffected.
- Response bodies from arbitrary (customer-controlled) push endpoints no longer land verbatim in this
  module's own logs on a failed send — capped to 200 characters, reducing the information-disclosure
  amplification the SSRF issue above would otherwise have had.
- Unbounded Web Push subscription growth — `PushSubscriptionManager::register()` now caps each
  customer/visitor at 20 registered devices, evicting the least-recently-active one on overflow.
- A race between two concurrent `register()` calls for the same brand-new push endpoint (e.g. a service
  worker's own `pushsubscriptionchange` firing at the same moment `tracker.js`'s `subscribeToPush()`
  resolves) surfaced an unhandled unique-constraint error instead of the second call cleanly updating the
  row the first one just inserted.
- `push-sw.js`'s `pushsubscriptionchange` handler did nothing when the browser omitted
  `event.newSubscription` (observed on both Chrome and Firefox even when a replacement genuinely is
  needed) — it now re-subscribes itself using the old subscription's own options, per spec.
- `Controller\Track\PushServiceWorker` returned an empty `200 application/javascript` body instead of a
  `404` when the underlying file was missing, silently registering a no-op service worker.
- **N+1 query patterns found via a performance audit**, each fixed with a batch method mirroring
  `CreditLimitCalculator::getUsedCreditForCustomers()`'s existing shape:
  - `ConsentManager::hasConsentForCustomers()` (new) — one query per cron run instead of one `hasConsent()`
    call per candidate, used by `SendAbandonedCartReminders`, `SendCreditLimitAlerts`,
    `SendOfferExpiryReminders`, `SendReorderReminders`, and `SendWinBackEmails`.
  - `CustomerTagManager::getCustomerIdsWithTagFromSet()` (new) — same shape for tag-membership checks in
    `TagInactiveCustomers` and `SendWinBackEmails`.
  - `SalesRepEmailContext::getForLoadedCustomer()` (new) — skips a redundant second EAV load in
    `SendCreditLimitAlerts`/`SendOfferExpiryReminders`/`SendReorderReminders`, which already have the
    customer loaded by the time they build the email's sales-rep signature block.
  - `EscalateStalePendingApprovals` now batch-loads every stale approval's order in one `IN (...)` query
    instead of one query per approval.

### Changed

- Admin UX, Phases 1-2 of the "become the best-in-market admin experience" initiative
  (see ROADMAP.md history / git log for the full rationale):
  - Dashboard's 13 nav cards regrouped from one flat list into merchant-goal-oriented sections
    (Campaigns & Automation / Audience & Targeting / Channels & Content / Compliance & Settings),
    with Reorder Cycles and Message Log — the two genuinely bare engine-diagnostic screens —
    demoted into a visually distinct "Diagnostics" group instead of competing for attention.
  - Reorder Cycles now resolves customer name/email and product name (no EAV join needed —
    sourced from `sales_order_item.name`), and adds a real outcome column: reminders sent, last
    sent, and whether a reminder actually led to a reorder (from `ordo_reorder_reminder_log`'s
    own `reacted` flag) — turning a raw diagnostic dump into a report that shows whether the
    automation is actually working.
  - Message Log now resolves `customer_id` to a real name/email.
  - RFM Report gained a "Qualifies For" column showing which of the merchant's own enabled
    segments each customer's current RFM standing qualifies them for, turning the report into
    a lead-in to action instead of a dead-end number.
  - Every standalone admin screen (all 10 listing pages plus GDPR/Consent and Campaign
    Calendar) now has a "Back to Dashboard" link/button — previously the only way back from a
    page reached via a dashboard card was the browser's own back button, since this module has
    a single flat admin menu entry rather than a menu tree.
  - Dashboard's disabled "Shopping feed" state got a real empty-state treatment and a button
    into Configuration instead of a bare sentence.
  - GDPR/Consent and Campaign Calendar's visual style unified with the rest of the module (same
    toolbar-bar "Back to Dashboard" look, same card/badge language) instead of bare default
    Magento admin markup.
- Admin UX, Phases 3-4 of the same initiative (completes it):
  - Every listing screen gets a real in-context intro (what it is, why it exists, what to do
    here), plus a guided first-run empty state with a "create your first X" call to action on
    all 7 CRUD listings (Campaigns, Free Gift Offers, Segments, Score Rules, Content Blocks,
    WhatsApp Templates, Ad Audiences) instead of a bare "We couldn't find any records."
  - Dashboard's response-rate column and loyalty tiers now use functional color (status badges,
    tier-colored dots) instead of flat monochrome numbers.
  - Flow editor's Triggers/Conditions/Actions palette is now collapsible, with each group and
    item color-coded to match that kind's own Flow-canvas node color.
  - Flow canvas connection curves no longer balloon into a large loop when nodes are placed
    close together (Drawflow's curvature tuned down from its 0.5 default).
  - Native campaign edit form's Triggers/Conditions/Actions dynamicRows tables get real styling
    (card border, zebra striping, monospace JSON textarea, brand-purple "Add" buttons) instead
    of stock unthemed Magento admin markup.
- Extracted `Model\Http\JsonApiClient` (POST JSON, check status, decode JSON response) out of
  `GoogleAdsSyncClient`, `MetaSyncClient`, and `WhatsAppSender`, which each had an identical, independently
  hand-rolled copy of that same HTTP-mechanics shape. Each class keeps its own request-building and
  response-interpreting logic (that genuinely differs per API); only the duplicated boilerplate underneath
  moved. `Model\Push\PushSender` was deliberately left as-is — its pre-encrypted binary payload doesn't fit
  a "postJson" abstraction.

### Added

- Web Push notifications — a full third messaging channel (`send_push` campaign action), closing the
  ROADMAP.md "Push notifications" gap. No vendor web-push library: real browser/OS notifications via RFC 8291
  (Message Encryption for Web Push) and RFC 8292 (VAPID), implemented by hand on top of PHP's own `openssl_*`
  functions (P-256 ECDH via `openssl_pkey_derive`, HKDF via `hash_hmac`, AES-128-GCM via `openssl_encrypt`) —
  the same no-SDK stance this module already takes for Twilio/Meta/Google/SendGrid, extended here to
  asymmetric crypto rather than just HMAC signature verification.
  - `Model\Push\Der` — a minimal hand-rolled ASN.1 DER encoder/parser turning the raw EC points/scalars every
    part of this feature deals with (browser subscription keys, this module's own stored VAPID keys, ECDSA
    signatures) into something `openssl_pkey_get_public()`/`openssl_pkey_get_private()`/`openssl_sign()`
    accept, and back — every length is computed from actual content, never a hardcoded byte-offset table.
  - `Model\Push\WebPushCrypto` — the RFC 8291/8188 encryption pipeline itself (ephemeral ECDH, two-stage HKDF,
    single-record `aes128gcm` framing). Verified by `WebPushCryptoTest`, which round-trips a real `encrypt()`
    call against an independent, from-scratch reimplementation of the *receiving* side of the same RFCs,
    rather than trusting the implementation's own internal consistency.
  - `Model\Push\VapidTokenBuilder` — builds the `Authorization: vapid t=<JWT>, k=<key>` header (RFC 8292),
    including converting `openssl_sign()`'s DER ECDSA signature into JWS's required raw `r || s` format.
  - New `ordo_push_subscription` table + `Model\Push\PushSubscriptionManager` — one row per browser/device
    (a customer can have several), upserted by `endpoint_hash` so a browser silently rotating its own
    subscription updates the existing row instead of accumulating duplicates. Keyed by `customer_id` OR
    `visitor_id`, same anonymous-then-stitched shape as `ordo_visitor_tag`/`ordo_pending_popup` —
    `Observer\StitchVisitorIdentity` now also backfills `customer_id` onto pre-login subscriptions on login.
  - `Controller\Track\RegisterPushSubscription`/`UnregisterPushSubscription` — public endpoints `tracker.js`
    (and `push-sw.js`'s own `pushsubscriptionchange` handler) call to register/remove a subscription.
  - `Controller\Track\PushServiceWorker` — serves `view/frontend/web/js/push-sw.js` from a plain, unversioned
    controller URL with a `Service-Worker-Allowed: /` response header, instead of Magento's usual deep
    `/static/version.../frontend/...` static asset path, which would otherwise limit the service worker's
    scope to that same deep path and make it unable to control real storefront pages at all.
  - `Model\Campaign\Action\SendPush` — same skeleton as `send_sms`/`send_whatsapp` (consent gate via the
    already-existing, previously-unused `ConsentChannel::Push`, `MessageLogWriter` into the shared
    `ordo_message_log`), but sends to every one of a customer's registered devices rather than a single
    phone number, and deletes a subscription outright (`SubscriptionGoneException`) on an HTTP 404/410 from
    the push service instead of just logging a failure.
  - New CLI command `bin/magento ordo:push:vapid:generate` — an admin has no other reasonable way to produce
    a VAPID key pair in the exact base64url-encoded raw-EC-point/scalar format the config fields need.
  - New "Push Notifications (Web Push)" config section (VAPID public/private key, contact subject).

- WhatsApp Business Platform integration (Meta Cloud API v20.0) — a full second messaging channel alongside
  the existing `send_sms`. Not "same API, `whatsapp:` prefix": outside a 24-hour customer-service window
  (which is most campaign sends), Meta only allows a pre-approved message template, so this ships:
  - A new admin CRUD entity, WhatsApp Templates (`admin/ordo/whatsapptemplate`), tracking each template
    through Meta's own approval lifecycle (`Model/WhatsAppTemplate::STATUS_DRAFT/PENDING/APPROVED/REJECTED/
    DISABLED`) — `SubmitForReview` registers a draft with Meta (`Model/WhatsApp/WhatsAppTemplateClient`,
    plain `Curl`, no vendor SDK, same pattern as `GoogleAdsSyncClient`/`MetaSyncClient`), `RefreshStatus`
    polls Meta for the current status, and editing an already-submitted template's body text resets it back
    to draft (same rule Meta's own template editor applies, since the old submission no longer matches).
  - A new `send_whatsapp` campaign action (`Model/Campaign/Action/SendWhatsApp`) — always sends a template
    message (never free-form text, since a campaign dispatch has no reliable way to know a 24h window is
    open for a given recipient), picking an APPROVED template and filling its `{{1}}, {{2}}, ...`
    placeholders from a comma-separated `params` field. Reuses the existing `ordo_sms_phone` customer
    attribute (one phone number serving both channels), `ConsentManager` (new `CHANNEL_WHATSAPP`), and the
    channel-generic `ordo_message_log`/`MessageLogWriter` already shared by sms/email.
  - `Controller\WhatsApp\Webhook` — a single public endpoint handling both halves of Meta's real webhook
    contract: the one-time GET subscription handshake, and POST event delivery (signature-verified via
    `X-Hub-Signature-256`/HMAC-SHA256, `Model/WhatsApp/WhatsAppSignatureValidator`) carrying either message
    delivery-status updates (correlated to `ordo_message_log` by provider message id) or template
    approval-status updates (correlated to `ordo_whatsapp_template` by Meta's own template id) — the same
    "unauthenticated, signature-verified, single registered URL" shape as `Controller\Sms\StatusCallback`/
    `Controller\Email\StatusCallback`.
  - New config section `Stores > Ordo Automation > WhatsApp (Meta Cloud API)`: enable flag, access token,
    app secret, webhook verify token (all encrypted), phone number id, business account id.
  - Not yet exercised against a real Meta/WhatsApp Business Account end to end — see ROADMAP.md.
- Campaign calendar view (`admin/ordo/campaign/calendar`) — every campaign's trigger(s) and action-chain
  timing (cumulative offset, not raw per-step `delay_minutes`) in one place.
- Dedicated admin fields for the 6 RFM-based campaign conditions (`days`/`count`/`percentile`), replacing the
  raw "Params (JSON)" fallback.
- MFTF coverage for the reminder/alert crons (`lifecycle` group): `TagInactiveCustomers`/`SendWinBackEmails`,
  `SendCreditLimitAlerts`, `SendSalesRepDigest`.
- `Test/Integration/CampaignDispatchLoadTest.php` — load/soak test for campaign dispatch (200 campaigns/trigger,
  600-row scheduled-action backlog).
- Message Log admin grid (`Controller/Adminhtml/MessageLog/Index.php`) over `ordo_message_log`.
- E.164 validation for `ordo_sms_phone` before a `send_sms` action spends a Twilio API call.
- On-site product recommendation content block (`recommendations` type) — reuses the same
  `ProductRecommender`/`ProductRecommendationRenderer` pair `add_product_recommendations` already uses for
  email, embeddable anywhere via a new `Block\Frontend\ContentBlock\Render` (resolves a content block by its
  `identifier`), registered as a real Magento widget (`etc/widget.xml`, id `ordo_content_block`) so it's usable
  from any CMS block/page via the `{{widget}}` directive (including the CMS WYSIWYG's own "Insert Widget"
  dialog, currently unverified end-to-end - see `Render.php`'s own docblock) or, the real proven mechanism,
  a plain layout XML file targeting a CMS page's own `cms_page_view_id_{identifier}` handle (`Magento\Cms\
  Helper\Page`'s own convention). `ProducerInterface::render()` gained an optional `$context` parameter so a
  producer can personalize by `customer_id`. MFTF coverage (`AdminContentBlockRecommendationsOnSiteTest`)
  verified by hand against a real local Magento install first (real admin UI, real guest order, real
  anonymous storefront visit) before being written, after `{{widget}}` and the CMS page's own "Layout Update
  XML" field (the latter turned out to be a dead end - `Magento\Cms\Model\Page::beforeSave()` unconditionally
  wipes that field to null unless a custom layout file is already registered and selected) both failed.
- Product feed export to shopping channels — a real, standalone Google Merchant Center-compatible
  XML feed (`Model/ProductFeed/GoogleMerchantFeedGenerator.php`), distinct from the existing
  `product_feed` content block (a small curated HTML grid inside campaigns/on-site, not an
  exportable file). `Cron/RefreshProductFeed.php` regenerates a cached copy every 6 hours
  (`ordo_product_feed_cache`, same "generate on a schedule, serve the cache" split as the RSS
  content-block cache); `Controller/ProductFeed/Index.php` (public, unauthenticated) serves it at
  `/ordo/productfeed/index`; an admin "Refresh Now" link on the dashboard
  (`Controller/Adminhtml/ProductFeed/RefreshNow.php`) regenerates it synchronously. Config under
  "Shopping Feed" (enabled/title/description).
- Ad-audience sync (Google Ads / Meta) — new `ordo_ad_audience` admin CRUD entity (segment +
  platform + external audience id), `Cron\SyncAdAudiences` resolves a segment's real current
  members (`Model\Segment\SegmentMemberResolver`, the same resolver `in_segment`/segment bulk
  actions already use), hashes their emails to the shared SHA-256 spec both platforms require
  (`Model\AdAudience\PiiHasher`), and hands the list to the configured platform's
  `SyncClientInterface`: `GoogleAdsSyncClient` (OAuth refresh-token exchange +
  create/addOperations/run against the OfflineUserDataJobService REST endpoints) or
  `MetaSyncClient` (Custom Audience create + `usersreplace`). Plain HTTP via
  `Magento\Framework\HTTP\Client\Curl`, no new SDK dependency — same choice `RssFetcher` already
  made for its own external call.
- GDPR/consent manager — new `ordo_customer_consent` table (per-customer, per-channel: email/
  sms/push), `Model/ConsentManager.php` is the single source of truth `send_email`/`send_sms`
  both check before sending anything (opt-out register, not opt-in — a customer with no row is
  treated as consented, so this never retrofits existing customers into a breaking prior-opt-in
  requirement). New admin page (`admin/ordo/gdpr/index`, linked from the dashboard) to search a
  customer by email, toggle their per-channel consent, download a full data-subject export
  (`Controller/Adminhtml/Gdpr/Export.php`), and erase every row this module holds about them
  (`Controller/Adminhtml/Gdpr/Erase.php`).
- Single-question satisfaction/NPS survey action (`nps_survey`) — same queue-and-poll delivery
  shape as `popup`/`notify`: new `ordo_survey_prompt` table holds both the queued 0-10 question
  and its eventual response in one row, `Controller/Track/Survey.php` claims and hands it out
  (claim-before-use, same as `popup`), a real click posts to `Controller/Track/
  SubmitSurveyResponse.php` which records the answer once and never overwrites it. New
  `nps_score_at_least` segment/campaign condition (`Model/Campaign/Condition/
  NpsScoreAtLeast.php`) reads a customer's most recent answered score, reusing the same
  dedicated "threshold" field `score_at_least` already has — no new admin field needed for the
  condition side. `Cron/PruneSurveyPrompts.php` cleans up responded/expired/stale-delivered rows.
- Persistent in-site notification action (`notify`) — non-modal, sibling to the existing `popup` action, but
  never claimed-and-gone: new `ordo_notification` table, `Controller/Track/Notification.php` returns every
  unread row on every poll (unlike `Popup.php`'s one-shot claim), `Controller/Track/DismissNotification.php` is
  the only thing that marks one read, and `Cron/PruneNotifications.php` cleans up read/expired rows. Reuses
  `popup`'s own admin fields (headline/body/cta_label/cta_url) and `tracker.js`'s existing poll-loop pattern.
- Loyalty tiers on top of lead scoring — `Model/LoyaltyTierCalculator.php` maps the existing `ordo_customer_score`
  running total (the same score `score_at_least` already reads) into Bronze/Silver/Gold, configurable via two new
  `lead_scoring` config thresholds. New `loyalty_tier_at_least` campaign/segment condition (`{tier}`, no dedicated
  admin field yet — via the Params JSON fallback) and a new dashboard stat showing the customer count per tier.
- SendGrid-backed `send_email` delivery tracking — closes the gap this file's own ROADMAP.md previously
  flagged. Writes to the same channel-generic `ordo_message_log` `send_sms` already writes to: a per-send
  `Message-ID` header (`Model/Email/MessageIdGenerator.php`) is set via `Plugin/Email/
  EmailMessageMessageIdPlugin.php` on `Magento\Framework\Mail\EmailMessageInterfaceFactory::create()` — the
  only reachable interception point, since `TransportBuilder` itself exposes no public seam to reach the
  message it builds (no `getMessage()`, `prepareMessage()` is protected) — then `Controller/Email/
  StatusCallback.php` (public, unauthenticated, ECDSA-signature-verified via `Model/Email/
  SendGridSignatureValidator.php`, same trust model as `send_sms`'s own Twilio callback) correlates a later
  SendGrid Event Webhook delivery/bounce/dropped event back to that row. Scoped to the `send_email` campaign
  action only, not every `TransportBuilder` call site in this Magento install (see that class's own docblock).
  New "Email Delivery Tracking (SendGrid)" config section holds the webhook's verification key.

### Verification

- `VERIFICATION.md`'s manual checklist re-run end to end against PHP 8.4.25 / Magento Open Source
  2.4.9 (previous full pass was against 2.4.7/PHP 8.2 — see below) — install, static checks, admin UI
  (real browser via Playwright, not just HTTP status codes), campaign engine wiring, and real
  on-site tracking (`page_view` event through `tracker.js`, `POST /ordo/track/event`, DB row with
  `customer_id IS NULL`) all pass. Found and fixed along the way:
  - This module's own `vendor/` (dev tooling — phpunit/phpstan/codeception) was being copied into
    consumer installs via Composer's `path` repository (`options.symlink: false` copies the whole
    directory, not just the package), duplicate-declaring classes and crashing
    `setup:di:compile`. Fixed with `.gitattributes` `export-ignore` rules.
  - `phpstan.neon`'s `includes:` referenced `vendor/bitexpert/phpstan-magento/extension.neon`
    relative to itself — only ever worked because of the bug above. Switched to `%rootDir%`-relative
    so it resolves correctly wherever PHPStan itself is installed.
  - `VERIFICATION.md`'s static-checks step instructed installing PHPStan into the *consuming*
    Magento project — conflicts with Magento 2.4.9's own pinned dev tooling
    (`magento/magento-coding-standard` → `rector` → `phpstan/phpstan ^1.12`, incompatible with this
    module's `bitexpert/phpstan-magento ^0.43` → `phpstan/phpstan ^2.0`). Corrected to run from this
    module's own checkout instead, matching what CI (`.github/workflows/ci.yml`) already does.
  - `system.xml`'s comment on `tracking/enabled` was stale, claiming `tracker.js` "loads sitewide
    regardless of this setting" — `view/frontend/layout/default.xml` was since changed to gate the
    block on that setting (a real, deliberate improvement; the comment just never caught up).

### Fixed

- **Double-execution race in `Cron\RunScheduledCampaignActions`** — claiming a due row was a plain
  load()-then-save(), so two overlapping cron runs could both "win" the same row and both dispatch
  its action (email/SMS/coupon) twice. `Model\ResourceModel\Campaign\ScheduledAction::claim()` now
  does the claim as a single atomic conditional `UPDATE ... WHERE executed_at IS NULL`.
- **Same race in order approval** — `OrderApprovalManagement::approveByToken()`/`rejectByToken()`
  loaded then saved without a lock, so two concurrent requests for the same token (a double click,
  a forwarded email opened twice) could both pass the "still pending" check and one order could
  end up both approved and rejected. `Model\ResourceModel\OrderApproval::claimPending()` now
  atomically transitions `status` from `pending` via a conditional `UPDATE`, checked *before* the
  order is ever touched.
- **Double-counting in lead scoring** — `Observer\EvaluateCustomerScoreRules` read
  `getDemographicScore()`/`getScore()` then wrote `addPoints()`/`setDemographicScore()` as four
  separate, unlocked calls, so two overlapping `customer_save_after` events could both read the
  same stale old value and each apply the same delta, inflating the total by 2x. New
  `CustomerScoreManager::applyDemographicScore()` does the whole read-modify-write atomically
  inside one transaction (`SELECT ... FOR UPDATE` on both rows).
- **Resend-on-crash in 6 reminder/alert crons** — `SendWinBackEmails`, `SendOfferExpiryReminders`,
  `SendReorderReminders`, `SendCreditLimitAlerts`, `SendAbandonedCartReminders`,
  `EscalateStalePendingApprovals` all wrote their "already sent" record *after* the send call, so a
  crash in between caused a duplicate send on the next cron tick. All six now claim (write the
  dedupe record) *before* sending, and roll the claim back if the send itself then genuinely
  fails, so a real failure is still retried next run without risking a duplicate on a crash.
- **Ad-audience sync bypassed consent entirely** — `Cron\SyncAdAudiences` uploaded every matching
  segment member's hashed email to the configured ad platform with no consent check anywhere in
  the path. New `ConsentManager::CHANNEL_ADS` (now `ConsentChannel::Ads`), checked per customer
  before hashing/upload.
- **Win-back email bypassed consent entirely** — `Cron\SendWinBackEmails` had no `ConsentManager`
  check at all; a customer who opted out of email still received it. Added the same consent gate
  `send_email`/`send_sms`/`send_whatsapp` already apply, and extended it to
  `SendOfferExpiryReminders`/`SendReorderReminders`/`SendCreditLimitAlerts`/
  `SendAbandonedCartReminders` for consistency.
- **RFM scored a zero-order customer as "555" (the best possible score) on a degenerate dataset**
  (a brand-new/empty store, or any customer base where every customer's metrics happen to be
  identical) — the count-based percentile formula counted such a customer as "≤/≥ itself" and
  landed them at percentile 100 on all three axes, the opposite of "a zero-order customer is never
  a top spender." `RfmCalculator::computePercentileRanks()` now scores a zero-order customer at
  exactly percentile 0 on all three axes directly, bypassing the formula for that customer while
  still counting them as a data point for everyone else's percentile.
- **Google Ads/Meta ad-audience sync silently accepted partial API failures** — both
  `GoogleAdsSyncClient` (`partialFailureError` in an HTTP 200 `addOperations` response) and
  `MetaSyncClient` (`num_invalid_entries` in an HTTP 200 `usersreplace` response) previously never
  inspected these fields, so `Cron\SyncAdAudiences` recorded a full sync success even when the
  platform rejected some or all of the batch.
- **`Controller\Adminhtml\Gdpr\SetConsent`'s channel allow-list had quietly gone stale** — a
  hand-maintained array of valid channel strings was missing `whatsapp` entirely (added when that
  channel shipped, this list wasn't updated), silently blocking admins from ever opting a customer
  out of WhatsApp from this screen. `ConsentManager`'s channels are now a backed enum
  (`Ordo\Automation\Model\ConsentChannel`) instead of string constants, and `SetConsent` validates
  via `ConsentChannel::tryFrom()` against that one source of truth instead of keeping a second,
  independently-maintained list that can go stale the same way again.
- Plaintext phone number logged on an E.164-validation failure in `Model\Campaign\Action\
  SendWhatsApp` — inconsistent with the rest of the module's PII handling (see
  `Model\AdAudience\PiiHasher`); the customer id alone is enough to look the record up.
- Missing indexes found via a code audit: `ordo_ad_audience.enabled` (scanned every
  `Cron\SyncAdAudiences` run), `ordo_free_gift_offer.enabled` (checkout hot path),
  `ordo_whatsapp_template.status` (scanned every campaign flow editor load), and a composite
  `ordo_order_approval(status, created_at)` (`Cron\EscalateStalePendingApprovals`' own stale-filter
  query).

All of the above found via a dedicated correctness/security/data-integrity code-audit pass (three
parallel subagent reviews); every fix above ships with new regression test coverage reproducing
the original bug.

- Stored XSS in the campaign flow editor — `Block/Adminhtml/Campaign/Edit/Flow.php`'s
  `getFieldsConfigJson()`/`getFlowDataJson()` are embedded raw (`@noEscape`) directly inside a
  `<script>` block, not an HTML attribute `escapeHtmlAttr()` would cover. A literal `</script>`
  in any admin-authored text reaching that JSON (action/condition params, content block names)
  would close the script tag early. Fixed with `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|
  JSON_HEX_QUOT` on both `json_encode()` calls. Found via a dedicated security-audit subagent pass.
- `Cron\SendCreditLimitAlerts` re-loaded every customer via EAV a second time inside its own loop
  (`CreditLimitCalculator::getCreditLimit()`/`getUsedCredit()`), on top of the batch load
  `CustomerMapBuilder` already did — real impact at a few thousand credit-limit customers. New
  `CreditLimitCalculator::getCreditLimitFromCustomer()` (works off an already-loaded customer)
  and `getUsedCreditForCustomers()` (one `GROUP BY` query for every customer instead of one query
  per customer) close this. `Cron\SyncAdAudiences` had the same shape for resolving segment
  members' emails — switched to the existing `CustomerMapBuilder`. Found via a dedicated
  performance-audit subagent pass.
- `SendSalesRepDigest`'s email always rendered an empty customer list — Magento's `{{for}}` directive silently
  skips non-array loop items; `customer_names` was a plain `string[]`. Fixed by using `array{name: string}[]`.
- `setup:install`/`setup:upgrade` crashed on this module's data patches — `AbstractCustomerAttributePatch`
  lived alongside its concrete subclasses in `Setup/Patch/Data/`, and Magento's `PatchReader` globs every file
  there as a patch class with no abstract check. Moved to `Setup/Patch/AbstractCustomerAttributePatch.php`.
- Campaigns grid still showed the deprecated single `trigger_event` column (empty since the multi-trigger
  migration) — now joins `ordo_campaign_trigger` and shows every trigger, comma-separated.

### Changed

- `send_sms` (`TwilioSmsSender`) now authenticates outbound Twilio API calls with a Restricted API Key
  (new `Twilio API Key SID`/`Twilio API Key Secret` config fields) instead of the Account Auth Token, per
  Twilio's own recommendation (https://www.twilio.com/docs/iam/api-keys) — limits the blast radius of a
  leaked credential to Programmable Messaging instead of full account access. The Auth Token itself stays in
  config: `Controller\Sms\StatusCallback`'s webhook signature check always requires it (Twilio signs
  `X-Twilio-Signature` with the Auth Token regardless of how outbound calls are authenticated), so it
  couldn't be retired. Verified against a real Twilio trial account end to end, both directly
  (`TwilioSmsSender::send()` against the live API, not just the unit tests' faked HTTP transport — message
  delivered, status `delivered` per the Twilio API) and through the full campaign action
  (`Model\Campaign\Action\SendSms::execute()` against a real customer/`ordo_sms_phone`/`ConsentManager`,
  writing a real `ordo_message_log` row with `status=sent` and the live provider message id), and the
  `Controller\Sms\StatusCallback` webhook (tunneled the local Docker setup's port through ngrok, pointed
  `web/secure|unsecure/base_url` at the public URL so `CallbackUrlBuilder` produces a real reachable
  callback, and confirmed Twilio's own genuine signed POST updated the `ordo_message_log` row from `sent` to
  `delivered` — a real DB round trip driven by a real X-Twilio-Signature, not a computed-in-test one).

### Fixed

- **Every `type="obscure"` config field in `Helper\Config` was returning ciphertext, not the decrypted
  secret, when read at runtime.** `Magento\Config\Model\Config\Backend\Encrypted` only decrypts when its
  own `Value` object is loaded (the admin config edit form) — a plain `ScopeConfigInterface::getValue()`
  call, which is what every getter in this class used, returns the raw encrypted string. Affected all 10
  obscure fields: Twilio Auth Token, Twilio API Key Secret, the 3 Google Ads OAuth secrets, the Meta access
  token, the SendGrid webhook verification key, and the 3 WhatsApp (Meta) secrets. In production this meant
  every outbound API call using one of these credentials would have authenticated with ciphertext (Google
  Ads/Meta/Twilio would reject it, as reproduced live via `TwilioSmsSender` before this fix — HTTP 401), and
  every inbound webhook signature check (SMS, WhatsApp, SendGrid) would have compared against ciphertext,
  silently rejecting every genuine callback. Fixed by routing every obscure-field getter through a new
  `Config::decryptedConfig()` helper that explicitly calls `EncryptorInterface::decrypt()`. Found while
  live-testing the Twilio API Key change above — never caught by the existing unit tests because they mock
  `Config` directly with already-decrypted fixture values, never exercising the real encrypt/decrypt round
  trip.
- Deduplicated the `*PercentileAtLeast` campaign conditions and the customer-attribute Setup patches into shared
  base classes (`AbstractPercentileAtLeast`, `AbstractCustomerAttributePatch`) — no behavior change.
- Extracted `Model/Cron/CronRunLogger.php` for the shared per-item-failure/run-summary log shape, adopted across
  all 15 crons that had it duplicated inline.
- Extracted `Model/Campaign/Action/ContextTargetResolver.php` (+ `ContextTarget` value object) for the
  byte-for-byte-identical customer_id-or-visitor_id resolution block duplicated across `ShowPopup`, `Notify`,
  and `NpsSurvey` — no behavior change. Found via a design-review subagent pass.

## [1.0.0]

First full pass verified end to end against a real Magento Open Source 2.4.7 instance, including a real order
placed through storefront checkout, held for approval, approved via the token link, and released.

### Added

- Multi-trigger campaigns — trigger event moved from a single `ordo_campaign.trigger_event` column to its own
  child entity, `ordo_campaign_trigger` (`CampaignTriggerInterface`); REST: `/V1/ordo/campaign-triggers`.
- Editable Drawflow scenario canvas on the campaign edit page — visual trigger(s) → conditions → actions graph,
  drag-and-drop palette, dedicated fields per condition/action type instead of a raw JSON textarea.
- Product recommendations (`add_product_recommendations` campaign action) — co-purchase affinity via SQL against
  order history, falling back to store-wide best-sellers.
- Lead scoring — demographic-attribute scoring rules (`ordo_score_rule`), applied on `customer_save_after`, with
  a `score_threshold_crossed` campaign trigger.
- Popup targeting — frequency capping and an `element_clicked` tracked event type.
- Dynamic content blocks (`ordo_content_block`: snippet/RSS/product-feed), resolved by a new
  `add_dynamic_content` campaign action.
- Segments — bulk actions on current members (add tag/points), a standalone RFM report, percentile-based RFM
  conditions, and `Cron\RecomputeRfmScores` precomputing quintiles nightly.
- Full i18n coverage — `en_US`/`pl_PL` rebuilt to the module's full string surface; 10 machine-translated locales
  added (`de_DE`, `fr_FR`, `es_ES`, `it_IT`, `pt_BR`, `zh_Hans_CN`, `ja_JP`, `ru_RU`, `uk_UA`, `nl_NL`).
- SMS campaign action (Twilio) — `send_sms`, delivery tracked via `ordo_message_log` and a signature-verified
  status webhook; opted-out recipients recorded distinctly from a generic failure.

### Fixed

- `HoldOrderForApproval` recorded `order_id = 0` on every `ordo_order_approval` row — read
  `$order->getEntityId()` before the order's own save assigned it. Fixed by reordering the two saves.

## [0.9.4]

### Fixed

- Audited and fixed all 12 instances of a `?: $default` config-getter bug in `Helper/Config.php` that treated an
  explicit `0` as unset. Extracted a single `intConfig()` helper.

## [0.9.3]

### Fixed

- `VisitorEventLogger::attributeVisitorToCustomer()` never re-ran aggregation after backfilling a visitor's
  events on login — a threshold crossed pre-login stayed untagged until the next scheduled run.
- `Config::getTrackingRetentionDays()` treated `0` ("prune everything") as unset, falling back to the 7-day
  default.

## [0.9.2]

### Fixed

- `etc/di.xml` wired `CheapestItemFree` against the wrong extension point (`Validator::calculators` doesn't
  exist in Magento 2.4.x; the real one is `CalculatorFactory::discountRules`).
- `QualifyingSetTracker` gave every item in the cart 100% off, not just the cheapest — `Quote\Address\Item::
  getItemId()` is null during real discount collection, and the null cast to `0` made every item match. Switched
  identity from item id to SKU.

## [0.9.1]

### Fixed

- Every custom customer attribute this module defines (`ordo_credit_limit`, `ordo_order_spend_limit`,
  `ordo_approval_admin_email`, the 3 `ordo_sales_rep_*` fields) silently failed to persist — the setup patches
  set `user_defined => true` without `group`, so `EavSetup::addAttribute()` never attached them to an attribute
  set, and `AbstractEntity::_collectSaveData()` silently drops values for attributes outside the entity's set.
  Fixed by adding `'group' => 'General'` to all 6 attribute definitions.

## [0.9.0]

### Added

- Dedicated fields per condition/action type (`tag`, `amount`, `rule_id`, `prefix`, `template`, `message`) in
  `ordo_campaign_form.xml`, shown/hidden via `<switcherConfig>`. `params_json` remains as fallback.

### Fixed

- `<switcherConfig>` was on the target fields instead of the controlling `type` select.
- `ordo_campaign_form.xml`'s `<dataSource>` was missing `<submitUrl path="ordo/campaign/save"/>`.
- `Save.php` read `$data['conditions']`/`$data['actions']` directly, but the dynamicRows posted structure is
  double-nested (`conditions[conditions][0][...]`) — conditions/actions never actually persisted before this fix.

## [0.8.5]

### Added

- Custom admin dashboard (`ordo/dashboard/index`) — server-rendered, not a UI Component.

### Changed

- Admin menu is now a single flat entry pointing at the dashboard, with Campaigns/Reorder Cycles/Configuration
  linked as cards from it.

## [0.8.4]

### Fixed

- Several admin controllers didn't implement `HttpGetActionInterface`/`HttpPostActionInterface` — Magento's
  `BackendValidator` silently rejected the requests before `execute()` ran.
- `ordo_campaign_form.xml`'s knockout template resolved to `templates/form/default.xhtml`, which binds to an
  `areas` scope nothing in this form ever creates — permanent silent hang. Fixed via
  `templates/form/collapsible`.

## [0.8.3]

First run against a live Magento Open Source 2.4.7 instance. 12 bugs found and fixed.

### Fixed

- `Api/CampaignRepositoryInterface.php`, `Api/OfferRepositoryInterface.php` — incomplete `@return` docblocks
  broke the WebAPI reflection generator.
- `Model/Campaign.php`, `Model/Offer.php` — `setEntityId()` was parameter-incompatible with `AbstractModel`.
- `Model/CampaignRepository.php`, `Model/OfferRepository.php` — `getList()` missing its declared return type.
- Three toolbar button blocks implemented a nonexistent Magento interface.
- `etc/acl.xml` — missing `Magento_Backend::stores_settings` ancestor created a conflicting ACL resource.
- Grid collections needed `mainTable`/`resourceModel` via `di.xml`, not `_init()`.
- `ordo_campaign_form.xml`'s `save` button referenced a nonexistent core class.
- `Model/Campaign/DataProvider.php` — undeclared dynamic property.
- `QualifyingSetTracker.php` called `$rule->getRuleId()`, which doesn't exist (only `getId()`).
- `Model/SalesRepEmailContext.php` called `->getFrontendName()` on a `StoreInterface`-typed value (only on the
  concrete `Store` model) — switched to `getName()`.
- `phpstan.neon` was missing `includes:`/using the wrong parameter key — PHPStan never actually ran.

## [0.8.2]

### Added

- `VERIFICATION.md` — install/test checklist for a fresh Magento Open Source instance.

## [0.8.1]

### Added

- `HasTagTest`, `AddTagTest` unit tests.
- First MFTF test, `AdminCreateCampaignTest.xml`.

## [0.8.0]

### Added

- On-site behavior tracking core — `tracker.js` (visitor cookie, `page_view`/`product_view`/`category_view`),
  `POST /ordo/track/event`, `customer_login` identity stitching, `VisitorAggregator`.
- `ordo_visitor_event` table with `PruneVisitorEvents` retention cron (default 7 days).

## [0.7.0]

### Added

- Campaign builder admin UI — grid and edit form with dynamicRows conditions/actions.
- Read-only "Reorder Cycles" admin grid.

## [0.6.0]

### Added

- `cart_abandoned` campaign event, dispatched from `SendAbandonedCartReminders`.
- `CheapestItemFree` custom SalesRule discount calculator (+ `QualifyingSetTracker`).

## [0.5.0]

### Added

- Campaign engine (`ordo_campaign`/`_condition`/`_action`, `CampaignDispatcher`, `ConditionPool`/`ActionPool`
  plug-in registry). Ships with `tag`/`order_total_gte` conditions and `add_tag`/`send_email`/`generate_coupon`
  actions. Triggers: `order_placed`, `customer_registered`, `tag_added`.
- `CouponGenerator` — mints single-use SalesRule coupon codes.
- REST service contract for campaigns (`/V1/ordo/campaigns`).

## [0.4.0]

### Added

- Sales-rep signature on automated emails, falling back to the store name when unassigned.
- Weekly sales-rep digest email grouping inactive customers by rep.
- Quality standards adopted: PHPStan `level: max`, unit tests per non-trivial class, planned MFTF/API coverage.
- Localization scaffold — `i18n/en_US.csv`, `i18n/pl_PL.csv`.

### Fixed

- Two email templates used an invalid `{{depend}}{{else}}` construct — replaced with independent `{{depend}}`
  blocks.

## [0.3.0]

### Added

- Order approval workflow — per-customer spend limit, `Pending Approval` order status, token-based
  approve/reject email.
- Escalation cron for stale pending approvals (capped at 3 resends).

## [0.2.0]

### Added

- B2C lifecycle automation — welcome email, nightly inactivity tagging, self-clearing win-back email.
- `CustomerTagManager` — shared add/remove/check/list-by-tag service.

## [0.1.1]

### Added

- Proactive credit limit alerts — cron warning at a configurable threshold (default 80%).

## [0.1.0]

### Added

- First-party B2B offer/quote entity (`ordo_offer`) with a proactive expiry reminder.

## [0.0.1] — initial release

### Added

- Reorder reminders — recurring purchase pattern detection per customer/SKU.
- Abandoned cart recovery — inactive carts above a configurable subtotal, capped per cart.
- Module skeleton.
