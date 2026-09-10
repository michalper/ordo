# MFTF scenario inventory

Every real-world path this module's shipped functionality can take, so MFTF coverage can be planned deliberately instead
of ad hoc. Built by enumerating the actual registered triggers/conditions/actions (`etc/di.xml`), controllers, and cron
jobs — not guessed from memory. Each scenario is marked:

- ✅ **covered** — an existing MFTF test exercises this path end to end (file named).
- ⬜ **not covered** — a real gap, candidate for a new test.
- 🔴 **covered but currently failing** — a test exists and used to (or should) pass, but is reproducibly red in
  real CI right now; see the linked ROADMAP.md note for what's actually wrong.

Cross-reference: `ROADMAP.md`'s "Test coverage" section for the standing priority list this feeds.

**Status: every row below is ✅ except one 🔴 (see §10) and one ⬜ (the frequency-cap structural
case in §1d, unit-tested but no MFTF/integration coverage yet).** Re-audit this against `etc/di.xml`/
`Controller/Adminhtml/*`/`etc/events.xml` periodically rather than trusting it at face value — add a row (⬜)
for anything newly added before considering it done.

**Scope, verified against the codebase:**

- 57 REST routes (`etc/webapi.xml`, 8 resource groups) are out of MFTF's scope by design (no browser to drive) —
  covered instead by `Test/Api/*ApiTest.php`.
- 9 admin form/grid areas (Campaign, ContentBlock, Dashboard, FreeGiftOffer, MessageLog, ReorderCycle, Rfm,
  ScoreRule, Segment) — §§1–10 below. §10 lists every `di.xml`-registered campaign action and every
  `ContentBlock\ProducerPool` type.
- Storefront controllers (`Controller/{Approval,Offer,Track}/`) — §5, §7.
- Credit limit (`Model/CreditLimitCalculator.php`, `/V1/ordo/credit-limit/*`) has a REST API
  (`Test/Api/CreditLimitApiTest.php`), a cron-driven warning email, and a real checkout-blocking plugin
  (`Plugin/Quote/BlockOverLimitCheckout.php`, §12).

## 1. Campaign engine

The core trigger → condition (s) → action (s) chain (`Model/CampaignDispatcher.php`). Full combinatorics (6 triggers ×
11 conditions × 5 actions) isn't a realistic test plan — the goal is covering every trigger at least once, every
condition at least once, every action at least once, and the multi-campaign / multi-trigger / delayed-action structural
cases separately from the type-by-type ones.

### 1a. Triggers (`Model/Config/Source/TriggerEvent.php` / `CampaignTriggerInterface`)

| Trigger                   | Fired from                                                                 | Status                                            |
|---------------------------|----------------------------------------------------------------------------|---------------------------------------------------|
| `order_placed`            | `Observer/DispatchOrderPlacedCampaigns.php` (`sales_order_place_after`)    | ✅ `AdminCampaignScenarioEndToEndTest`            |
| `customer_registered`     | `Observer/DispatchCustomerRegisteredCampaigns.php`                         | ✅ `AdminCampaignCustomerRegisteredTriggerTest`   |
| `tag_added`               | `Observer/DispatchTagAddedCampaigns.php` (`ordo_customer_tag_added`)       | ✅ `AdminCampaignTagAddedTriggerTest`             |
| `cart_abandoned`          | `Cron/SendAbandonedCartReminders.php`'s own dispatch, not a live observer  | ✅ `AdminSendAbandonedCartReminderAndTriggerTest` |
| `visitor_tag_added`       | `Observer/DispatchVisitorTagAddedCampaigns.php` (`ordo_visitor_tag_added`) | ✅ `AdminCampaignVisitorTagConditionTest`         |
| `score_threshold_crossed` | `Observer/DispatchScoreThresholdCampaigns.php` (lead scoring, see §4)      | ✅ `AdminScoreThresholdCampaignTest`              |

### 1b. Conditions (`Model\Campaign\ConditionPool`)

| Condition                             | Params                                                            | Status                                                                                                 |
|---------------------------------------|-------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------|
| `tag`                                 | `{tag}`                                                           | ✅ (`AdminCampaignInSegmentConditionTest`, exercised as the segment's own condition)                   |
| `order_total_gte`                     | `{amount}`                                                        | ✅ (`AdminCampaignScenarioEndToEndTest`, `AdminCreateCampaignWithConditionsAndActionsTest` as UI-only) |
| `visitor_tag`                         | `{tag}`                                                           | ✅ `AdminCampaignVisitorTagConditionTest`                                                              |
| `score_at_least`                      | `{threshold}`                                                     | ✅ `AdminCampaignScoreAtLeastConditionTest`                                                            |
| `recency_days_at_most`                | `{days}` (RFM)                                                    | ✅ `AdminRecencyDaysAtMostConditionTest`                                                               |
| `order_frequency_at_least`            | `{count}` (RFM)                                                   | ✅ `AdminOrderFrequencyAtLeastConditionTest`                                                           |
| `monetary_total_at_least`             | `{amount}` (RFM)                                                  | ✅ `AdminMonetaryTotalAtLeastConditionTest`                                                            |
| `recency_percentile_at_least`         | `{percentile}` (RFM, needs `Cron\RecomputeRfmScores` to have run) | ✅ `AdminRecencyPercentileConditionTest`                                                               |
| `order_frequency_percentile_at_least` | `{percentile}` (RFM)                                              | ✅ `AdminOrderFrequencyPercentileConditionTest`                                                        |
| `monetary_percentile_at_least`        | `{percentile}` (RFM)                                              | ✅ `AdminMonetaryPercentileConditionTest`                                                              |
| `in_segment`                          | `{segment_id}`                                                    | ✅ `AdminCampaignInSegmentConditionTest`                                                               |
| `loyalty_tier_at_least`               | `{tier}` (bronze/silver/gold, no dedicated field yet — via Params JSON) | ✅ `AdminLoyaltyTierAtLeastConditionTest`                                                        |
| `nps_score_at_least`                  | `{threshold}` (same dedicated "threshold" field as `score_at_least`)    | ✅ `AdminCampaignNpsSurveyActionTest` (customer_id only — no visitor_id path)                     |
| `purchased_sku`                       | `{sku}` (dedicated field, autocompleted)                          | ⬜ unit-tested (`PurchasedSkuTest`, `PurchasedProductResolverTest`), no MFTF yet                       |
| `purchased_category`                  | `{category_id}` (dedicated field, incl. subcategories)             | ⬜ unit-tested (`PurchasedCategoryTest`, `PurchasedProductResolverTest`), no MFTF yet                  |

### 1c. Actions (`Model\Campaign\ActionPool`)

| Action                        | Params                                 | Status                                                                                                                                                                                                                                                                                     |
|-------------------------------|----------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `add_tag`                     | `{tag}`                                | ✅ `AdminCampaignAddTagActionTest`                                                                                                                                                                                                                                                         |
| `send_email`                  | `{template, message}`                  | ✅ `AdminCampaignSendEmailActionTest`                                                                                                                                                                                                                                                      |
| `generate_coupon`             | `{rule_id, prefix}`                    | ✅ `AdminCampaignScenarioEndToEndTest`                                                                                                                                                                                                                                                     |
| `popup`                       | `{headline, body, cta_label, cta_url}` | ✅ `AdminCampaignPopupActionTest` (writes `ordo_pending_popup`; storefront poll — see §7)                                                                                                                                                                                                  |
| `notify`                      | `{headline, body, cta_label, cta_url}` | ✅ `AdminCampaignNotifyActionTest` (writes `ordo_notification`; persists across reload until dismissed — see §7)                                                                                                                                                                           |
| `nps_survey`                  | `{question}`                           | ✅ `AdminCampaignNpsSurveyActionTest` (writes `ordo_survey_prompt`; real answer gates a downstream `nps_score_at_least`-conditioned campaign — see §7)                                                                                                                                     |
| `add_points`                  | `{points}`                             | ✅ `AdminCampaignAddPointsActionTest` (feeds `score_at_least`; does not itself dispatch `score_threshold_crossed` — only `EvaluateCustomerScoreRules` does)                                                               |
| `add_product_recommendations` | `{count}`                              | ✅ `AdminAddProductRecommendationsActionTest` (co-purchase signal empty in a fresh install, exercises the documented store-wide-best-sellers fallback)                                                                                                                                     |
| `add_dynamic_content`         | `{content_block_id, output_key}`       | ✅ `AdminCampaignDynamicContentSnippetActionTest` (`snippet` content-block type only — see §10 for `rss`/`product_feed`)                                                                                                                                                                   |
| `send_sms`                    | `{message}`                            | ✅ `Test/Integration/CampaignSendSmsActionTest.php` — real DI/database, `SmsSenderInterface` swapped for a recording fake (real Twilio account still needed for the actual API call, see ROADMAP.md); correctly out of MFTF's own scope (no browser-visible effect for a browser to check) |
| `send_email` SendGrid delivery tracking (`Controller/Email/StatusCallback.php`) | ✅ `Test/Unit/Controller/Email/StatusCallbackTest.php` / `SendGridSignatureValidatorTest` / `EmailMessageMessageIdPluginTest` / `MessageIdGeneratorTest` — writes to the same `ordo_message_log` `send_sms` already writes to (a per-send `Message-ID` header, set via a plugin since `TransportBuilder` exposes no public seam of its own to reach the message it builds, is what a later SendGrid Event Webhook call correlates against); correctly out of MFTF's own scope, same reasoning as `send_sms`'s own row above (a real SendGrid account is needed for the actual webhook call, see ROADMAP.md) |
| `send_whatsapp`               | `{template_id, params}`                | ✅ `Test/Unit/Model/Campaign/Action/SendWhatsAppTest.php` — approved-template gate, phone/consent/E.164 checks, sender success/failure paths (real Meta account still needed for the actual API call, see ROADMAP.md); correctly out of MFTF's own scope, same reasoning as `send_sms`'s own row above |
| `send_push`                   | `{title, body, url}`                   | ✅ `Test/Unit/Model/Campaign/Action/SendPushTest.php` — consent gate, multi-subscription fan-out, dead-subscription cleanup on 404/410; `Test/Unit/Model/Push/*` covers the RFC 8291/8292 crypto itself in depth (real browser/push service still needed for an actual delivered notification, see ROADMAP.md); correctly out of MFTF's own scope, same reasoning as `send_sms`'s own row above |

### 1d. Structural cases (not type-specific)

| Scenario                                                                                                      | Status                                                      |
|---------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------|
| Single trigger/condition/action, save + grid appearance                                                       | ✅ `AdminCreateCampaignTest`                                |
| Multiple triggers on one campaign (fan-out to the same chain)                                                 | ✅ `AdminCreateMultiTriggerCampaignTest`                    |
| Condition + action together via the dynamicRows form (UI only, no live dispatch)                              | ✅ `AdminCreateCampaignWithConditionsAndActionsTest`        |
| Multiple conditions AND'd together — all pass                                                                 | ✅ `AdminCampaignMultipleConditionsAndTest`                 |
| Multiple conditions AND'd together — one fails, action must NOT run                                           | ✅ `AdminCampaignMultipleConditionsAndTest`                 |
| Multiple campaigns matching the same trigger, only some satisfy their conditions                              | ✅ `AdminMultipleCampaignsOnSameTriggerOnlySomeSatisfyTest` |
| Delayed action (`delay_minutes > 0`) — chain pauses, `Cron\RunScheduledCampaignActions` resumes it later      | ✅ `AdminCampaignDelayedActionTest`                         |
| Chained delays (action pauses, resumes, pauses again)                                                         | ✅ `AdminChainedDelayedActionsTest`                         |
| Disabled campaign — trigger fires, nothing happens                                                            | ✅ `AdminCampaignDisabledNoDispatchTest`                    |
| Campaign edited after creation (trigger/condition/action changed, re-saved, old rows replaced not duplicated) | ✅ `AdminEditCampaignConditionReplacesNotDuplicatesTest`    |
| Campaign deleted — grid no longer lists it, dispatch no longer matches its old triggers                       | ✅ `AdminDeleteCampaignStopsDispatchTest`                   |
| Unknown/removed condition or action type on a campaign (fails closed, logs, doesn't crash the whole dispatch) | ✅ `AdminCampaignUnknownActionTypeFailsClosedTest`          |
| Cross-channel frequency cap (`Model\Campaign\FrequencyCapManager`, opt-in, disabled by default) — a customer over the configured per-window contact-volume cap is skipped by `send_email`/`send_sms`/`send_whatsapp`/`send_push` alike and recorded `suppressed` in `ordo_message_log`, not sent | ⬜ unit-tested (`FrequencyCapManagerTest`, each `Send*Test`'s own `...SuppressedWhenFrequencyCapReached` case), no MFTF/integration test against a real multi-channel dispatch yet |

## 2. Segments (`Model/Segment.php`, `Controller/Adminhtml/Segment/`)

| Scenario                                                                                                                            | Status                                              |
|-------------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------|
| Create a segment (name, enabled, one condition), appears in grid                                                                    | ✅ `AdminCreateSegmentTest`                         |
| Segment with multiple AND'd conditions                                                                                              | ✅ `AdminCreateSegmentWithMultipleConditionsTest`   |
| Segment with a nested "Group (nested AND/OR)" condition, built inline (no modal), persists and re-renders on reload                 | ✅ `AdminCreateSegmentWithNestedGroupConditionTest` |
| Segment referenced by a campaign's `in_segment` condition (real membership match at dispatch time)                                  | ✅ `AdminCampaignInSegmentConditionTest`            |
| Bulk action on a segment's current members — add tag (`SegmentBulkActionConsumer`, async via `ordo.automation.segment.bulk_action`) | ✅ `AdminSegmentBulkActionAddTagTest`               |
| Bulk action on a segment's current members — add points                                                                             | ✅ `AdminSegmentBulkActionAddPointsTest`            |
| Segment edited, condition changed, membership re-evaluates differently                                                              | ✅ `AdminEditSegmentConditionChangesMembershipTest` |
| Segment deleted                                                                                                                     | ✅ `AdminDeleteSegmentTest`                         |

## 3. RFM (`Model/Rfm/`, `Cron/RecomputeRfmScores.php`, `ordo/rfm/index`)

| Scenario                                                                                     | Status                                                                                                                 |
|----------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| Admin RFM report grid (`Controller/Adminhtml/Rfm/Index.php`) renders with real customer data | ✅ `AdminRfmReportGridReflectsRealDataTest`                                                                            |
| `Cron\RecomputeRfmScores` populates `ordo_customer_rfm_score`                                | ✅ `AdminRecencyPercentileConditionTest` (populate half) / `AdminRfmReportGridReflectsRealDataTest` (grid reflects it) |
| RFM Score column shows correct quintile digits (e.g. "555" for best-on-all-three)            | ✅ `AdminRfmReportGridReflectsRealDataTest`                                                                            |
| Percentile-based campaign condition (§1b) reads the precomputed table, not a live scan       | ✅ `AdminRecencyPercentileConditionTest`                                                                               |

## 4. Lead scoring (`Model/ScoreRule.php`, `Controller/Adminhtml/ScoreRule/`)

| Scenario                                                                                                 | Status                                               |
|----------------------------------------------------------------------------------------------------------|------------------------------------------------------|
| Create a score rule (attribute code, operator, value, points) via admin CRUD                             | ✅ `AdminScoreThresholdCampaignTest`                 |
| Operator: `equals`                                                                                       | ✅ `AdminScoreThresholdCampaignTest`                 |
| Operator: `not_equals`                                                                                   | ✅ `AdminScoreRuleNotEqualsAndContainsOperatorsTest` |
| Operator: `contains`                                                                                     | ✅ `AdminScoreRuleNotEqualsAndContainsOperatorsTest` |
| Customer save triggers `Observer/EvaluateCustomerScoreRules.php`, delta applied to `ordo_customer_score` | ✅ `AdminScoreThresholdCampaignTest`                 |
| Crossing the configured threshold fires `score_threshold_crossed` (chains §1a)                           | ✅ `AdminScoreThresholdCampaignTest`                 |
| Score rule edited/disabled — no longer contributes on next customer save                                 | ✅ `AdminDisableScoreRuleStopsContributingTest`      |
| Score rule deleted                                                                                       | ✅ `AdminDeleteScoreRuleStopsContributingTest`       |
| Loyalty tier (`Model/LoyaltyTierCalculator.php`) derived from the same running score, gates a campaign via `loyalty_tier_at_least` | ✅ `AdminLoyaltyTierAtLeastConditionTest` |

## 5. Free gift offers (`Model/FreeGiftOffer.php`, `Model/FreeGiftManagement.php`,

`Controller/Adminhtml/FreeGiftOffer/`, `Controller/Offer/`)

`FreeGiftManagementInterface` (get eligibility / select gifts) has no storefront UI in this repo at all — it's a pure
REST surface, presumably meant for a headless/PWA storefront to call — so the first four rows below are a
`Test/Integration` suite (real DI, real DB, real quote) instead of MFTF: there is nothing for MFTF's browser to click
through. `Controller/Offer/*` (self-extend,
"My Offers") *is* a real storefront page and stays a genuine MFTF candidate.

| Scenario                                                                                                                                                                            | Status                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Create a free gift offer (tiers, product pool) via admin CRUD                                                                                                                       | ✅ `FreeGiftManagementScenarioTest` (Test/Integration — no admin UI exercised, offer/tier/pool built via the repositories directly, see below)                                                                                                                                                                                                                                                                                                                                                                  |
| Real cart crosses a tier's `min_subtotal` — gift slot becomes available on the storefront                                                                                           | ✅ `FreeGiftManagementScenarioTest`                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| Customer selects a free gift, it's added to cart at zero cost                                                                                                                       | ✅ `FreeGiftManagementScenarioTest`                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| Cart drops below the tier threshold — previously-added gift is removed                                                                                                              | ✅ `FreeGiftManagementScenarioTest` |
| Offer self-extension (`Controller/Offer/Extend.php`, `Offer::canSelfExtend()`)                                                                                                      | ✅ `StorefrontMyOffersSelfExtendTest`                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| "My Offers" storefront customer-account page (`Controller/Offer/Index.php`) lists the logged-in customer's offers, self-extend action visible only when `canSelfExtend()` allows it | ✅ `StorefrontMyOffersSelfExtendTest`                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `Cron\SendOfferExpiryReminders` — reminder email before expiry                                                                                                                      | ✅ `AdminOfferExpiryReminderAndExpirationTest`                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| `Cron\ExpireOverdueOffers` — offer past expiry marked expired, no longer redeemable                                                                                                 | ✅ `AdminOfferExpiryReminderAndExpirationTest`                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |

## 6. Order approval (`Model/OrderApproval.php`, `Controller/Approval/`)

| Scenario                                                                                                                             | Status                                                  |
|--------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------|
| Real order over spend limit → held, approve email sent, approve link releases it                                                     | ✅ `AdminApproveOrderViaEmailTest`                      |
| Reject link (`Controller/Approval/Reject.php`) — order canceled, not released                                                        | ✅ `AdminRejectOrderViaEmailTest`                       |
| Token re-use after approve/reject (already covered as the *second* half of `AdminApproveOrderViaEmailTest` — single-use enforcement) | ✅                                                      |
| `Cron\EscalateStalePendingApprovals` — a pending approval past its SLA gets escalated                                                | ✅ `AdminEscalateStalePendingApprovalTest`              |
| Order under spend limit — never held at all (negative case)                                                                          | ✅ `AdminOrderUnderSpendLimitNotHeldTest`               |
| Customer with no spend limit / no approval admin email configured — never held                                                       | ✅ `AdminOrderNeverHeldWithoutSpendLimitConfiguredTest` |

## 7. Tracking & popups (`view/frontend/web/js/tracker.js`, `Controller/Track/`)

| Scenario                                                                                                                                                     | Status                                                                                                                                       |
|--------------------------------------------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------|
| `ordo_visitor_id` cookie issued on first visit, stable across reload                                                                                         | ✅ `StorefrontTrackerSetsVisitorCookieTest`                                                                                                  |
| `page_view` event posted and persisted                                                                                                                       | ✅ `StorefrontTrackerPostsEventsTest`                                                                                                        |
| `product_view` event posted and persisted (scripted stand-in for a theme PDP hook)                                                                           | ✅ `StorefrontTrackerPostsEventsTest`                                                                                                        |
| `category_view` event posted and persisted                                                                                                                   | ✅ `StorefrontTrackerCategoryViewEventTest`                                                                                                  |
| `element_clicked` event posted and persisted (popup-targeting click threshold)                                                                               | ✅ `StorefrontTrackerClickThresholdTagsVisitorTest`                                                                                          |
| View-threshold crossing (default 3) tags the visitor, chains into `visitor_tag_added` (§1a)                                                                  | ✅ `StorefrontTrackerViewThresholdTagsVisitorTest`                                                                                           |
| Click-threshold crossing (default 1) tags the visitor via `element_clicked`                                                                                  | ✅ `StorefrontTrackerClickThresholdTagsVisitorTest`                                                                                          |
| A campaign's `popup` action writes a pending popup, storefront poll (`Controller/Track/Popup.php`) picks it up and renders the banner                        | ✅ `AdminCampaignPopupActionTest`                                                                                                            |
| A campaign's `notify` action writes a persistent notification, storefront poll (`Controller/Track/Notification.php`) renders it, survives a reload, only disappears once dismissed (`Controller/Track/DismissNotification.php`) | ✅ `AdminCampaignNotifyActionTest` |
| Popup dismissed / closed client-side, doesn't reappear on next poll                                                                                          | ✅ `AdminCampaignPopupClaimedOnceTest`                                                                                                       |
| `Cron\PrunePendingPopups` — delivered/expired popups cleaned up                                                                                              | ✅ `AdminPrunePendingPopupsTest` (delivered half only — expired-undelivered half is dead code in production, nothing ever sets `expires_at`) |
| `Cron\PruneNotifications` — read/expired notifications cleaned up                                                                                            | ✅ `AdminPruneNotificationsTest` (read half only, same reasoning as `PrunePendingPopups` above) |
| A campaign's `nps_survey` action writes a survey prompt, storefront poll (`Controller/Track/Survey.php`) renders the 0-10 question, real click posts the answer (`Controller/Track/SubmitSurveyResponse.php`), a downstream `nps_score_at_least`-conditioned campaign reads it | ✅ `AdminCampaignNpsSurveyActionTest` |
| `Cron\PruneSurveyPrompts` — responded/expired-undelivered/stale delivered-unanswered survey prompts cleaned up                                              | ✅ `AdminPruneSurveyPromptsTest` (responded half only, same reasoning as `PrunePendingPopups`/`PruneNotifications` above) |
| `Cron\PruneVisitorEvents` — events past retention window removed                                                                                             | ✅ `StorefrontPruneVisitorEventsTest`                                                                                                        |
| Tracking disabled via config — `window.ordoTrack` calls become no-ops server-side (`reason: tracking_disabled`)                                              | ✅ `StorefrontTrackingDisabledConfigTest`                                                                                                    |
| `Observer/StitchVisitorIdentity.php` — pre-login anonymous events attributed to the customer on login, still counting toward a threshold crossed after login | ✅ `StorefrontVisitorIdentityStitchedOnLoginTest`                                                                                            |

## 8. Reorder cycles (`Model/ReorderCycle.php`, `Cron/CalculateReorderCycle.php`, `Cron/SendReorderReminders.php`)

| Scenario                                                                                  | Status                                |
|-------------------------------------------------------------------------------------------|---------------------------------------|
| Admin diagnostic grid renders                                                             | ✅ `AdminViewReorderCyclesGridTest`   |
| `Cron\CalculateReorderCycle` detects a recurring purchase pattern from real order history | ✅ `AdminReorderCycleAndReminderTest` |
| `Cron\SendReorderReminders` emails a customer whose predicted next-order date has arrived | ✅ `AdminReorderCycleAndReminderTest` |

## 9. Dashboard (`Controller/Adminhtml/Dashboard/`)

| Scenario                                                                                        | Status                                  |
|-------------------------------------------------------------------------------------------------|-----------------------------------------|
| Single "Ordo Automation" menu entry lands on the dashboard, stat cards render                   | ✅ `AdminViewDashboardTest`             |
| Stat cards reflect real data (e.g. campaign count, trigger performance) after creating fixtures | ✅ `AdminDashboardReflectsRealDataTest` |

## 10. Content blocks (`Model/ContentBlock/`, `Controller/Adminhtml/ContentBlock/`) and Message Log (

`Controller/Adminhtml/MessageLog/`)

Both missing from this document's original scope check (see the note at the top of this file) — added here rather
than retrofitted into an existing section, since neither fits §1-§9's shape.

| Scenario                                                                                                                | Status                                                                                                                           |
|-------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------|
| Create a `snippet` content block, resolved by a real `add_dynamic_content` campaign action                              | ✅ `AdminCreateContentBlockSnippetTest` / `AdminCampaignDynamicContentSnippetActionTest`                                         |
| `rss` content block type (`Model/ContentBlock/Producer/RssProducer.php`, `RssFetcher`)                                  | ✅ `AdminContentBlockRssTest`                                                                                                    |
| `product_feed` content block type, `source: category` (`CategoryProductLister`) or `source: rule` (`RuleProductLister`) | ✅ `AdminContentBlockProductFeedTest` (`source: rule`) and `AdminContentBlockProductFeedCategorySourceTest` (`source: category`) |
| `recommendations` content block type (`Model/ContentBlock/Producer/RecommendationProducer.php`)                        | ✅ `Test/Unit/Model/ContentBlock/Producer/RecommendationProducerTest.php` (the producer itself; see the next row for the on-site rendering path) |
| `Cron\RefreshRssContentBlocks` — the 30-minute job that keeps an `rss` block's cache warm                               | ✅ `AdminContentBlockRssTest`                                                                                                    |
| Admin "Refresh now" AJAX action (`Controller/Adminhtml/ContentBlock/RefreshRss.php`)                                    | ✅ `AdminContentBlockRssTest`                                                                                                    |
| A content block rendered directly on-site (not via a campaign action) — `Block/Frontend/ContentBlock/Render.php`, embedded via a real, static layout XML file targeting a specific CMS page's own `cms_page_view_id_{identifier}` handle (`Magento\Cms\Helper\Page`'s own mechanism - see `view/frontend/layout/cms_page_view_id_e2e-onsite-recommendations-page.xml` and `Render.php`'s own docblock for why this, not `{{widget}}` or the CMS page's "Layout Update XML" field, both tried and ruled out first) | ✅ `AdminContentBlockRecommendationsOnSiteTest` |
| Message Log admin grid (`Controller/Adminhtml/MessageLog/Index.php`) lists a real `ordo_message_log` row                | ✅ `AdminMessageLogGridReflectsRealDataTest`                                                                                     |
| Standalone Google Merchant Center shopping feed (`Model/ProductFeed/`, distinct from the `product_feed` content block) — admin refresh + public feed URL serves a real product | ✅ `AdminShoppingFeedRefreshAndServeTest`                                                       |

## 11. Cron jobs not otherwise covered above

| Job                          | What it does                                                 | Status                                                                                                                     |
|------------------------------|--------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------|
| `SendCreditLimitAlerts`      | Emails when a customer's credit exposure crosses a threshold | ✅ `AdminSendCreditLimitAlertTest`                                                                                         |
| `SendSalesRepDigest`         | Digest email to a sales rep                                  | ✅ `AdminSendSalesRepDigestTest`                                                                                           |
| `SendWinBackEmails`          | Emails customers `TagInactiveCustomers` tagged inactive      | ✅ `AdminTagInactiveCustomersAndWinBackEmailTest`                                                                          |
| `TagInactiveCustomers`       | Tags customers inactive past the configured window           | ✅ `AdminTagInactiveCustomersAndWinBackEmailTest` (same test — the two crons are tightly coupled, see its own description) |
| `SendAbandonedCartReminders` | Also the source of the `cart_abandoned` trigger (§1a)        | ✅ `AdminSendAbandonedCartReminderAndTriggerTest`                                                                          |

All four crons above only fire once a day (or, for `SendSalesRepDigest`, once a week) at a fixed
wall-clock time (`etc/crontab.xml`) — no MFTF test can wait that out. `Test/Mftf/Helper/CronScheduleHelper.php`
inserts a `cron_schedule` row directly (status `pending`, `scheduled_at` = now) so the next
`cron:run --group=default` executes the job regardless of its own cron expression — Magento's
`ProcessCronQueueObserver::shouldRunJob()` only checks whether an existing row is due, it never
re-validates the job's schedule at execution time. Same idea as `AdminCampaignDelayedActionTest`'s
`ordo_campaign_scheduled_action` row, just against Magento's own cron table instead of this
module's.

## 12. Lifecycle & identity events not otherwise covered above

| Scenario                                                                                                                                             | Status                                                   |
|------------------------------------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------|
| `Plugin/Quote/BlockOverLimitCheckout.php` — real checkout blocked once credit utilization reaches 100%                                               | ✅ `StorefrontCreditLimitBlocksCheckoutTest`             |
| `Observer/SendWelcomeEmail.php` — new-customer tag + welcome email on `customer_register_success`, independent of any `customer_registered` campaign | ✅ `StorefrontCustomerRegistrationSendsWelcomeEmailTest` |

## 13. GDPR / consent (`Model/ConsentManager.php`, `Controller/Adminhtml/Gdpr/`)

| Scenario                                                                                                                        | Status                                    |
|-----------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------|
| Admin-recorded email opt-out (`Controller/Adminhtml/Gdpr/SetConsent.php`) genuinely blocks a real `send_email` campaign action    | ✅ `AdminGdprConsentAndErasureTest`        |
| `Controller/Adminhtml/Gdpr/Erase.php` genuinely deletes a real `ordo_customer_consent` row (data-subject erasure)                 | ✅ `AdminGdprConsentAndErasureTest`        |
| `Controller/Adminhtml/Gdpr/Export.php` (data-subject access request JSON download)                                                | ✅ `Test/Unit/Controller/Adminhtml/Gdpr/ExportTest.php` — correctly out of MFTF's own scope, same reasoning as `send_sms`'s own row above (no browser-observable effect a browser-driven test can assert on a file download) |
| SMS opt-out via `ConsentManager` (as opposed to Twilio's own STOP-reply opt-out) — same `recordOptedOut` code path `send_sms` already exercises for Twilio's own opt-out | ✅ `SendSmsTest::testExecuteSkipsAndRecordsOptedOutWhenConsentWithdrawn` |

## 14. Ad audiences (`Model/AdAudience/`, `Controller/Adminhtml/AdAudience/`, `Cron/SyncAdAudiences.php`)

| Scenario                                                                                                          | Status                                                          |
|-------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| Admin CRUD (create/grid/delete) for an `ordo_ad_audience` row                                                      | ✅ `AdminCreateAdAudienceTest`                                    |
| `Cron\SyncAdAudiences` resolves a real segment's current members and hands their hashed emails to the configured platform's `SyncClientInterface` | ✅ `Test/Integration/SyncAdAudiencesTest.php` (real segment/DB, fake sync client) |
| `Model/AdAudience/PiiHasher` — email normalization + SHA-256 hashing matches the shared Google/Meta spec           | ✅ `PiiHasherTest` (known hash vector)                           |
| `GoogleAdsSyncClient`/`MetaSyncClient`/`GoogleOAuthTokenProvider` — real request-building/response-parsing         | ✅ `GoogleAdsSyncClientTest` / `MetaSyncClientTest` / `GoogleOAuthTokenProviderTest` (fake `Curl`) |
| A real sync against a live Google Ads/Meta account                                                                | See ROADMAP.md's own note — out of MFTF's scope, same reasoning as `send_sms`'s equivalent gap |

## 15. WhatsApp templates (`Model/WhatsAppTemplate`, `Controller/Adminhtml/WhatsAppTemplate/`, `Controller/WhatsApp/Webhook.php`)

| Scenario                                                                                                          | Status                                                          |
|---------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| Admin CRUD (create/grid/delete) for an `ordo_whatsapp_template` row                                                | ✅ `AdminCreateWhatsAppTemplateTest`                              |
| `SubmitForReview`/`RefreshStatus` controllers — approval-lifecycle transitions (draft→pending, pending→approved/rejected/disabled), body-text edit resets an already-submitted template back to draft | ✅ `Test/Unit/Controller/Adminhtml/WhatsAppTemplate/{SubmitForReviewTest,RefreshStatusTest,SaveTest}.php` |
| `WhatsAppTemplateClient`/`WhatsAppSender` — real Graph API request-building/response-parsing                       | ✅ `WhatsAppTemplateClientTest` / `WhatsAppSenderTest` (fake `Curl`) |
| `WhatsAppSignatureValidator` — real HMAC-SHA256 signature verification                                             | ✅ `WhatsAppSignatureValidatorTest` (real crypto, not a hand-faked signature) |
| `Controller\WhatsApp\Webhook` — GET verification handshake, POST signature rejection, message/template status-update correlation | ✅ `Test/Unit/Controller/WhatsApp/WebhookTest.php` |
| An actual template submission/approval/send against a live Meta/WhatsApp Business Account                          | See ROADMAP.md's own note — out of MFTF's scope, same reasoning as `send_sms`'s equivalent gap |

## Suggested next batch (highest signal per test written)

Empty — every scenario this list ever tracked is now ✅ (see the sections above). Re-populate this when a new
gap is found (a newly added trigger/condition/action/controller/cron, or a re-audit catching something missed).
