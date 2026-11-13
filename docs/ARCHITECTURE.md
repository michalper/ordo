# Architecture

Directory/class map for anyone working on the code. Not shipped documentation for end users — see
[README.md](../README.md) for that.

## Configuration

- `etc/` — `module.xml`, `di.xml`, `crontab.xml`, `db_schema.xml`, `events.xml`, `email_templates.xml`, `acl.xml`,
  `webapi.xml`
- `etc/adminhtml/system.xml` — store configuration
- `etc/frontend/routes.xml` — `/ordo/approval/*` (token-based, no login)
- `Api/`, `Api/Data/` — service contracts: `Offer*`, `Campaign*`, `Campaign/ConditionInterface`,
  `Campaign/ActionInterface`

## Cron jobs

- `CalculateReorderCycle.php`, `SendReorderReminders.php`
- `SendAbandonedCartReminders.php`
- `SendOfferExpiryReminders.php`, `ExpireOverdueOffers.php`
- `SendCreditLimitAlerts.php`
- `TagInactiveCustomers.php`, `SendWinBackEmails.php`
- `TagReorderCycleAtRiskCustomers.php`, `TagChurnRiskCustomers.php` — nightly "classify, don't send"
  passes feeding `SendSalesRepDigest.php`'s per-signal grouping
- `TagLoyaltyMilestones.php`, `ExpireLoyaltyPoints.php`
- `SendStalledPaymentReminders.php`
- `DispatchCustomerAnniversaryCampaigns.php` — birthday/signup-anniversary trigger
- `DetectAnomalies.php` — per-campaign revenue-drop/conversion-outlier alerts
- `AutoPickCampaignSplitWinner.php`
- `EscalateStalePendingApprovals.php`
- `SendSalesRepDigest.php`
- `RecomputeRfmScores.php`, `SyncAdAudiences.php`
- `RunScheduledCampaignActions.php` (delay_minutes chaining)
- `DispatchScheduledCampaignTriggers.php` (scheduled_at / recurring_schedule campaign triggers)
- `RefreshProductFeed.php`, `RefreshRssContentBlocks.php`
- `PruneNotifications.php`, `PrunePendingPopups.php`, `PruneSurveyPrompts.php`, `PruneVisitorEvents.php`

## Observers

- `SendWelcomeEmail.php` — `customer_register_success`
- `HoldOrderForApproval.php` — `sales_order_place_after`
- `DispatchOrderPlacedCampaigns.php` — `sales_order_place_after`
- `DispatchCustomerRegisteredCampaigns.php` — `customer_register_success`
- `DispatchTagAddedCampaigns.php` — `ordo_customer_tag_added` (custom event)
- `DispatchVisitorTagAddedCampaigns.php` — on-site-tracking-derived tag threshold crossing
- `DispatchScoreThresholdCampaigns.php` — lead-score threshold crossing
- `EvaluateCustomerScoreRules.php` — recomputes `ScoreRule` matches on relevant customer events
- `RecordTriggerOutcome.php` — logs campaign dispatch outcomes for diagnostics
- `TrimExcessFreeGifts.php` — drops gifts that no longer qualify when subtotal falls
- `StitchVisitorIdentity.php` — attributes pre-login visitor events to the customer on login

## Campaign engine

- `Model/Campaign/` — `ConditionPool`, `ActionPool`, `Condition/*`, `Action/*` (the plug-in registry)
- `Model/CampaignDispatcher.php` — trigger event + context in, matching campaigns run out
- `Block/Adminhtml/Campaign/Edit/Flow.php` — builds the Drawflow trigger/condition/action graph for the campaign
  edit page
- `Block/Adminhtml/Campaign/Calendar/` — read-only trigger/action-timing overview across all campaigns
- `view/adminhtml/web/lib/drawflow/` — vendored Drawflow (MIT) — https://github.com/jerosoler/Drawflow
- `Controller/Adminhtml/Campaign/`, `ReorderCycle/`, `FreeGiftOffer/` — admin grid/form controllers
- `Block/Adminhtml/Campaign/Edit/`, `FreeGiftOffer/Edit/` — toolbar button blocks (Back/Delete/Save & Continue)
- `Ui/Component/Listing/Column/` — `CampaignActions`, `FreeGiftOfferActions` (Edit/Delete row links)
- `view/adminhtml/ui_component/` — `ordo_campaign_listing/form`, `ordo_reorder_cycle_listing`,
  `ordo_free_gift_offer_listing/form`

## Send-time optimization

- `Model/Campaign/SendTimeOptimizer.php` — histogram of a customer's own past email
  opens/clicks (`ordo_message_log_event` joined to `ordo_message_log`, `channel = 'email'` only —
  the only channel with open/click timestamps, via `Controller/Email/StatusCallback`'s SendGrid
  webhook) bucketed into their local hour (`Model/Campaign/CustomerTimezoneResolver`); returns the
  hour with the most events, or `null` below a minimum sample size (never delays a send for lack
  of data)
- `Model/Campaign/SendTimeOptimizationGate.php` — same "check, defer via
  `CampaignDispatcher::deferActionUntil()`, return false" shape as `QuietHoursGate`; opt-in per
  action via `"use_optimal_send_time": true` in that action's `params` JSON (same place
  `send_email` already stores `template`/`message` — no new `ordo_campaign_action` column), so
  existing campaigns are unaffected by default. Checked before `QuietHoursGate` in
  `Action/SendEmail`; a resumed attempt re-checks both gates, so a predicted hour landing inside
  quiet hours self-corrects via `QuietHoursGate`'s own re-defer rather than needing any
  coordination between the two

## Segmentation

- `Model/Segment.php`, `Model/SegmentCondition.php` — a segment's own condition rows (flat list,
  AND-joined by default) plus one reserved `'group'` pseudo-type per row holding its own nested
  `{"logic": "all"|"any", "conditions": [...]}` blob (one level of nesting)
- `Model/Segment/SegmentSaveProcessor.php` — delete-and-reinsert persistence for a segment's conditions,
  including `normalizeGroupRow()` for the nested-group blob
- `Model/Segment/SegmentMemberResolver.php`, `Model/Segment/SegmentMatcher.php` — segment-membership
  matching, recursing into nested groups the same way `CampaignDispatcher` does for campaign conditions
  (see "Campaign engine" below)
- `view/adminhtml/web/js/segment-group-modal.js` — admin UI for a group's own nested condition list,
  rendered inline (not a modal despite older naming — see the file's own docblock)
- `Controller/Adminhtml/Segment/`, `Block/Adminhtml/Segment/BulkActions.php` — admin grid/form,
  "Bulk actions on current members" (add tag / add points)

## Lead scoring & RFM

- `Model/ScoreRule.php`, `Model/ScoreRule/ScoreRuleEvaluator.php` — point rules matched against
  customer attributes (including core `CustomerInterface` getters like `group_id`) plus EAV custom
  attributes; `Observer/EvaluateCustomerScoreRules.php` recomputes on relevant events,
  `Observer/DispatchScoreThresholdCampaigns.php` fires campaigns on threshold crossing
- `Model/CustomerScoreManager.php` — running customer score state
- `Model/Rfm/RfmCalculator.php`, `Cron/RecomputeRfmScores.php` — Recency/Frequency/Monetary
  percentile scoring, feeding the `recency_percentile_at_least`/`order_frequency_percentile_at_least`/
  `monetary_percentile_at_least` segment condition types
- `Controller/Adminhtml/Rfm/Index.php` — read-only RFM report grid

## Ad-audience sync

- `Model/AdAudience.php`, `Api/AdAudience/SyncClientInterface.php` — audience definitions synced to
  an external ad platform
- `Model/AdAudience/GoogleAdsSyncClient.php`, `Model/AdAudience/MetaSyncClient.php`,
  `Model/AdAudience/TikTokSyncClient.php` — one client per platform, registered in
  `Model/AdAudience/SyncClientPool.php`
- `Model/AdAudience/PiiHasher.php` — hashes customer PII (email/phone) before it ever leaves this
  module, per each platform's Customer Match / Custom Audience upload format
- `Model/AdAudience/GoogleOAuthTokenProvider.php` — OAuth token exchange/refresh for Google Ads
- `Cron/SyncAdAudiences.php` — periodic sync driving the clients above
- `Controller/Adminhtml/AdAudience/` — admin CRUD
- `Model/Campaign/Action/SendGa4Event.php`, `Model/Campaign/Action/SendMetaConversionEvent.php` —
  server-side conversion reporting (GA4 Measurement Protocol / Meta Conversions API), independent
  of the audience-sync accounts above

## Automated anomaly alerts

- `Model/Anomaly/AnomalyAlert.php`, `Cron/DetectAnomalies.php` — daily per-campaign revenue-drop
  (vs. its own trailing baseline) and conversion-rate-outlier (vs. its peers) detection, gated by a
  minimum sample size and deduplicated via `Model/Cron/ReminderLogStore`'s cooldown pattern
- `Controller/Adminhtml/AnomalyAlert/Index.php` — read-only admin grid

## Loyalty, milestones & referrals

- `Model/LoyaltyPointsManager.php`, `Model/LoyaltyPointsManagement.php`,
  `Api/LoyaltyPointsManagementInterface.php` — ongoing earn/redeem points balance, distinct from
  the order-count/spend-based `Model/LoyaltyTierCalculator.php`
- `Observer/EarnLoyaltyPointsForOrder.php`, `Cron/ExpireLoyaltyPoints.php`
- `Model/GraphQl/Resolver/LoyaltyPointsBalance.php`, `Model/GraphQl/Resolver/RedeemLoyaltyPoints.php`
- `Cron/TagLoyaltyMilestones.php` — tags a customer at a fixed order-count milestone or loyalty
  tier reached, feeding the existing tag-based trigger (no new trigger type)
- `Model/ReferralManager.php` — `referral_converted` (referrer, on purchase) and
  `referral_signed_up` (referred customer, on signup) triggers

## Churn-risk signal

- `Model/ChurnRisk/ChurnRiskCalculator.php` — a customer-level "elapsed days since last order ÷
  own median order interval" ratio, generalizing `Model/ReorderCycle/ReorderCycleDriftCalculator`'s
  self-relative signal to every customer with ≥2 orders (not just one with an already-detected,
  SKU-specific reorder cycle)
- `Model/Campaign/Condition/ChurnRiskAtLeast.php` — the segment/campaign condition backed by it
- `Cron/TagChurnRiskCustomers.php` — see "Cron jobs" above

## WhatsApp & Web Push

- `Model/WhatsAppTemplate.php`, `Model/WhatsApp/WhatsAppTemplateClient.php` — Meta WhatsApp Business
  Cloud API template submission/approval-polling (`SubmitForReview`/`RefreshStatus`)
- `Model/WhatsApp/WhatsAppSender.php`, `Model/Campaign/Action/SendWhatsApp.php` — the `send_whatsapp`
  campaign action
- `Model/WhatsApp/WhatsAppSignatureValidator.php`, `Controller/WhatsApp/Webhook.php` — HMAC-SHA256
  signature-verified delivery-status webhook
- `Model/Push/WebPushCrypto.php`, `Model/Push/Der.php`, `Model/Push/VapidTokenBuilder.php` — RFC
  8291/8188/8292 Web Push encryption and VAPID signing (no vendor SDK)
- `Model/Push/PushSender.php`, `Model/Push/PushSubscriptionManager.php` — the `send_push` campaign
  action and per-customer/visitor subscription storage (capped at 20 devices, LRU eviction)
- `Model/Push/PushEndpointValidator.php` — HTTPS-only, rejects private/loopback/link-local IP ranges
  (SSRF guard on the customer-supplied push endpoint), enforced at registration and again before
  every send
- `Controller/Adminhtml/WhatsAppTemplate/` — admin CRUD + approval lifecycle actions
- `Controller/WhatsApp/Webhook.php` also writes a `MessageLogEvent::TYPE_READ` row for Meta's "seen"
  receipt, making `message_engaged` usable for WhatsApp sends

## RCS messaging

- `Model/Rcs/RcsSenderInterface.php`, `Model/Rcs/TwilioRcsSender.php` — the `send_rcs` campaign
  action, reusing SMS's own Twilio Account SID / API Key and all of `SendSms`'s channel-agnostic
  consent/quiet-hours/frequency-cap plumbing

## Price-drop & back-in-stock alerts

- `ordo_price_watch_subscription` table — one row per customer/visitor watch on a single product
  (`watch_type` `price_drop`|`back_in_stock`), keyed by customer_id OR visitor_id, same
  anonymous-then-stitched shape as `ordo_push_subscription`
- `Model/PriceWatch/PriceWatchSubscription.php`, `Model/PriceWatch/PriceWatchSubscriptionManager.php`
  — CRUD, idempotent by identity + product_id + watch_type, capturing the product's current
  price/stock state at registration time
- `Controller/Track/RegisterPriceWatch.php` — public endpoint a PDP "Notify me" button posts to
  (`window.ordoSubscribeToPriceWatch()` in `view/frontend/web/js/tracker.js`)
- `Cron/ScanPriceDropAlerts.php`, `Cron/ScanBackInStockAlerts.php` — batched scan crons dispatching
  the `price_drop`/`back_in_stock` campaign triggers the first time a watched product's price
  drops or its stock status transitions false→true relative to the row's own last-known state;
  claim-before-dispatch (`notified_at`) same as every other reminder cron in this module. A guest
  watch (no `customer_id`) can't go through the campaign engine — every condition/action assumes a
  `customer_id` — so a guest who gave an email at registration (`guest_email`) instead gets
  notified directly via `Model/PriceWatch/GuestPriceWatchNotifier`; a guest with no email on file
  just has its snapshot refreshed, same as before this existed

## GDPR

- `Model/Gdpr/CustomerDataExporter.php`, `Model/Gdpr/CustomerDataEraser.php` — per-customer data
  export/erasure across this module's own tables
- `Model/Gdpr/ConsentStates.php`, `Model/CustomerConsent.php`, `Model/ConsentManager.php`,
  `Model/ConsentChannel.php` — per-channel (email/SMS/WhatsApp/push/RCS) consent tracking, plus a
  second, additive per-topic register (`Model/ConsentTopic.php`, promotional/account-updates)
- `Controller/Adminhtml/Gdpr/` — admin export/erase/set-consent actions
- `Api/ConsentPreferencesManagementInterface.php` — the one service every self-service transport
  calls: `GET/PUT /V1/ordo/consent-preferences/mine*` REST, `ordoConsentPreferences`/
  `setOrdoConsent`/`setOrdoConsentTopic` GraphQL, and `Controller/Consent/*` (a storefront
  "Communication Preferences" account page) — see ADR 0054

## Dashboard

- `Block/Adminhtml/Dashboard/DashboardViewModel.php` — aggregates stats across campaigns/segments/
  offers/approvals for the admin landing page, nav cards grouped by merchant goal (Diagnostics
  section separated out)

## Content blocks

- `Model/ContentBlock.php`, `Model/ContentBlockRepository.php` — admin-authored reusable content (snippet/rss/
  product_feed/recommendations), resolved by type via `Model/ContentBlock/ProducerPool`
- `Model/ContentBlock/Producer/*` — one class per type, registered in `etc/di.xml`
- `Model/Campaign/Action/AddDynamicContent.php` — resolves a content block into a campaign email's context
- `Block/Frontend/ContentBlock/Render.php` — the on-site path: resolves a content block by its `identifier` and
  renders it directly on a storefront page
- `etc/widget.xml` — registers `Render` as a real Magento widget (`{{widget type=... identifier=...}}` in any
  CMS block/page, or the CMS WYSIWYG's own "Insert Widget" dialog) — there is no generic "insert any block by
  class" CMS directive, `{{widget}}` is the actual mechanism

## Promotion Builder

- `Model/Rule/Action/Discount/` — `CheapestItemFree` (custom SalesRule calculator), `QualifyingSetTracker`
- `view/adminhtml/ui_component/sales_rule_form.xml` — extends the native Cart Price Rule form with a live
  "Buy X Get Y" preview field
- `view/adminhtml/web/js/buy-x-get-y-calculator.js` — the preview's read-only calculator (mirrors the native
  discount logic, adds none)

## Credit limit & free gifts

- `Model/CreditLimitCalculator.php` — used-credit derived from open `sales_order.total_due`
- `Model/CreditLimitManagement.php` — REST-facing wrapper (mine / by customer id) over the calculator above
- `Plugin/Quote/BlockOverLimitCheckout.php` — blocks order placement at/over credit limit
- `Model/FreeGiftOffer(Tier/Product).php`, `Model/FreeGiftManagement.php` — cascading-tier gift offers + selection
- `Model/QuoteGiftItem.php` — marker linking a quote_item to the offer it was earned from

## Shared building blocks

- `Model/CustomerTagManager.php` — add/remove/check/list-by-tag; fires `ordo_customer_tag_added`
- `Model/CouponGenerator.php` — mints a single-use SalesRule coupon code
- `Model/SalesRepEmailContext.php` — shared email signature block
- `Setup/Patch/Data/` — customer attributes (credit/spend limit, approval admin email, sales rep), Pending Approval
  order status
- `Helper/Config.php` — typed access to `system.xml` values
- `view/frontend/email/` — email templates

## On-site tracking

- `Controller/Track/Event.php` — public, CSRF-exempt tracking endpoint
- `Model/VisitorEventLogger.php` — writes `ordo_visitor_event`, triggers aggregation when identity is known
- `Model/VisitorAggregator.php` — raw events → `ordo_customer_tag` threshold-crossing tags
- `view/frontend/web/js/tracker.js` — dependency-free visitor cookie + event snippet; also mirrors
  events onto `window.dataLayer` (GTM bridge) and fires an `exit_intent` trigger on a `mouseout`-
  based listener, both opt-in
- `Controller/Track/LowStock.php`, `Controller/Track/SocialProof.php` — `window.ordoRenderLowStock()`/
  `window.ordoRenderSocialProof()` PDP theme integration points
- `Controller/Track/EmailCapture.php` — newsletter signup popup for anonymous visitors, subscribes
  through Magento's own `Subscriber` and tags the visitor (`newsletter_signup`)

## SMS & delivery tracking

- `Model/Sms/` — `SmsSenderInterface`, `TwilioSmsSender`, `CallbackUrlBuilder`, `MessageLogWriter`
- `Controller/Sms/StatusCallback.php` — signature-verified Twilio delivery-status webhook (public, CSRF-exempt)
- `Model/MessageLog.php` — `ordo_message_log`, channel-generic delivery tracking (SMS today, email later)
- `Controller/Adminhtml/MessageLog/Index.php` — read-only admin grid over `ordo_message_log`

## Tests & i18n

- `Test/Unit/` — PHPUnit tests
- `Test/Mftf/` — full-stack acceptance tests (see `Test/Mftf/SCENARIOS.md`, `Test/Mftf/README.md`)
- `Test/Api/` — REST endpoint tests against a live instance (see `Test/Api/README.md`)
- `i18n/` — translation CSVs (`en_US`, `pl_PL` native-reviewed, + 18 machine-translated locales)
