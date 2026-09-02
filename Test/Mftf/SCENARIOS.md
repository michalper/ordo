# MFTF scenario inventory

Every real-world path this module's shipped functionality can take, so MFTF coverage can be planned deliberately instead
of ad hoc. Built by enumerating the actual registered triggers/conditions/actions (`etc/di.xml`), controllers, and cron
jobs — not guessed from memory. Each scenario is marked:

- ✅ **covered** — an existing MFTF test exercises this path end to end (file named).
- ⬜ **not covered** — a real gap, candidate for a new test.

Cross-reference: `Test/Mftf/README.md` for what already passed and why; `ROADMAP.md`'s
"Test coverage" section for the standing priority list this feeds.

**Scope check, done against the actual codebase, not memory** — asked directly: do we have every API endpoint, every
admin form/grid, every real MA scenario, the whole module? Verified:

- **57 REST routes** (`etc/webapi.xml`, 8 resource groups: campaigns + their trigger/condition/ action children,
  offers + self-extend, reorder-cycles, customer/visitor tags, order-approvals + approve/reject/decision-links,
  free-gift-offers + tiers + products + cart eligibility/redemption, credit-limit). These are **out of MFTF's scope by
  design** — MFTF drives a real browser, REST endpoints don't have one — and are **already covered** by a dedicated
  suite: `Test/Api/*ApiTest.php`
  (8 files, `AbstractApiTestCase`-based `webapi_rest` calls). Not duplicated here.
- **All 7 admin form/grid areas** (`view/adminhtml/layout/*.xml` + `ui_component/*.xml`) — Campaign, Dashboard,
  FreeGiftOffer, ReorderCycle, Rfm, ScoreRule, Segment — are represented in §§1–9 below. No admin area was missing
  structurally; most rows within each are still ⬜.
- **Storefront controllers** (`Controller/{Approval,Offer,Track}/`) cross-checked file-by-file:
  caught one real gap this document's first pass missed — `Controller/Offer/Index.php` ("My Offers", a whole page) — now
  added to §5.
- **Credit limit** (`Model/CreditLimitCalculator.php`, `Model/CreditLimitStatus.php`,
  `/V1/ordo/credit-limit/*`) has a REST API (covered by `Test/Api/CreditLimitApiTest.php`) and a cron-driven warning
  email, but **no storefront UI at all** — confirmed by grepping
  `view/frontend/` for any credit-limit block/template and finding only the email. Correctly out of this MFTF document's
  scope (there's no browser page to drive); not a documentation gap.

## 1. Campaign engine

The core trigger → condition (s) → action (s) chain (`Model/CampaignDispatcher.php`). Full combinatorics (6 triggers ×
11 conditions × 5 actions) isn't a realistic test plan — the goal is covering every trigger at least once, every
condition at least once, every action at least once, and the multi-campaign / multi-trigger / delayed-action structural
cases separately from the type-by-type ones.

### 1a. Triggers (`Model/Config/Source/TriggerEvent.php` / `CampaignTriggerInterface`)

| Trigger                   | Fired from                                                                 | Status                                                                                                                  |
|---------------------------|----------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------|
| `order_placed`            | `Observer/DispatchOrderPlacedCampaigns.php` (`sales_order_place_after`)    | ✅ `AdminCampaignScenarioEndToEndTest`                                                                                  |
| `customer_registered`     | `Observer/DispatchCustomerRegisteredCampaigns.php`                         | ⬜                                                                                                                      |
| `tag_added`               | `Observer/DispatchTagAddedCampaigns.php` (`ordo_customer_tag_added`)       | ⬜ (only reached indirectly, as a side effect, inside `AdminCampaignScenarioEndToEndTest` — never asserted on directly) |
| `cart_abandoned`          | `Cron/SendAbandonedCartReminders.php`'s own dispatch, not a live observer  | ⬜                                                                                                                      |
| `visitor_tag_added`       | `Observer/DispatchVisitorTagAddedCampaigns.php` (`ordo_visitor_tag_added`) | ⬜                                                                                                                      |
| `score_threshold_crossed` | `Observer/DispatchScoreThresholdCampaigns.php` (lead scoring, see §4)      | ✅ `AdminScoreThresholdCampaignTest`                                                                                    |

### 1b. Conditions (`Model\Campaign\ConditionPool`)

| Condition                             | Params                                                            | Status                                                                                                 |
|---------------------------------------|-------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------|
| `tag`                                 | `{tag}`                                                           | ⬜ (`HasTag`, customer must already have the tag)                                                      |
| `order_total_gte`                     | `{amount}`                                                        | ✅ (`AdminCampaignScenarioEndToEndTest`, `AdminCreateCampaignWithConditionsAndActionsTest` as UI-only) |
| `visitor_tag`                         | `{tag}`                                                           | ⬜ (anonymous-visitor variant of `tag`)                                                                |
| `score_at_least`                      | `{threshold}`                                                     | ⬜                                                                                                     |
| `recency_days_at_most`                | `{days}` (RFM)                                                    | ⬜                                                                                                     |
| `order_frequency_at_least`            | `{count}` (RFM)                                                   | ⬜                                                                                                     |
| `monetary_total_at_least`             | `{amount}` (RFM)                                                  | ⬜                                                                                                     |
| `recency_percentile_at_least`         | `{percentile}` (RFM, needs `Cron\RecomputeRfmScores` to have run) | ⬜                                                                                                     |
| `order_frequency_percentile_at_least` | `{percentile}` (RFM)                                              | ⬜                                                                                                     |
| `monetary_percentile_at_least`        | `{percentile}` (RFM)                                              | ⬜                                                                                                     |
| `in_segment`                          | `{segment_id}`                                                    | ⬜ (a campaign gated on membership in a saved segment — chains §2)                                     |

### 1c. Actions (`Model\Campaign\ActionPool`)

| Action            | Params                                 | Status                                                                                              |
|-------------------|----------------------------------------|-----------------------------------------------------------------------------------------------------|
| `add_tag`         | `{tag}`                                | ⬜ (only ever a side effect inside `AdminCampaignScenarioEndToEndTest`, never the thing under test) |
| `send_email`      | `{template, message}`                  | ⬜ (needs MailHog assertion, same pattern as `AdminApproveOrderViaEmailTest`)                       |
| `generate_coupon` | `{rule_id, prefix}`                    | ✅ `AdminCampaignScenarioEndToEndTest`                                                              |
| `popup`           | `{headline, body, cta_label, cta_url}` | ⬜ (writes `ordo_pending_popup`; needs a storefront poll — see §6)                                  |
| `add_points`      | `{points}`                             | ⬜ (writes `ordo_customer_score`; feeds `score_at_least`/`score_threshold_crossed`)                 |

### 1d. Structural cases (not type-specific)

| Scenario                                                                                                      | Status                                                                                                          |
|---------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------|
| Single trigger/condition/action, save + grid appearance                                                       | ✅ `AdminCreateCampaignTest`                                                                                    |
| Multiple triggers on one campaign (fan-out to the same chain)                                                 | ✅ `AdminCreateMultiTriggerCampaignTest`                                                                        |
| Condition + action together via the dynamicRows form (UI only, no live dispatch)                              | ✅ `AdminCreateCampaignWithConditionsAndActionsTest`                                                            |
| Multiple conditions AND'd together — all pass                                                                 | ⬜                                                                                                              |
| Multiple conditions AND'd together — one fails, action must NOT run                                           | ⬜                                                                                                              |
| Multiple campaigns matching the same trigger, only some satisfy their conditions                              | ⬜ (partially exercised as a side effect in `AdminCampaignScenarioEndToEndTest`'s fixture data, never asserted) |
| Delayed action (`delay_minutes > 0`) — chain pauses, `Cron\RunScheduledCampaignActions` resumes it later      | ⬜                                                                                                              |
| Chained delays (action pauses, resumes, pauses again)                                                         | ⬜                                                                                                              |
| Disabled campaign — trigger fires, nothing happens                                                            | ⬜                                                                                                              |
| Campaign edited after creation (trigger/condition/action changed, re-saved, old rows replaced not duplicated) | ⬜                                                                                                              |
| Campaign deleted — grid no longer lists it, dispatch no longer matches its old triggers                       | ⬜                                                                                                              |
| Unknown/removed condition or action type on a campaign (fails closed, logs, doesn't crash the whole dispatch) | ⬜ (unit-tested; no MFTF equivalent, arguably not worth one)                                                    |

## 2. Segments (`Model/Segment.php`, `Controller/Adminhtml/Segment/`)

| Scenario                                                                                                                            | Status                      |
|-------------------------------------------------------------------------------------------------------------------------------------|-----------------------------|
| Create a segment (name, enabled, one condition), appears in grid                                                                    | ✅ `AdminCreateSegmentTest` |
| Segment with multiple AND'd conditions                                                                                              | ⬜                          |
| Segment referenced by a campaign's `in_segment` condition (real membership match at dispatch time)                                  | ⬜                          |
| Bulk action on a segment's current members — add tag (`SegmentBulkActionConsumer`, async via `ordo.automation.segment.bulk_action`) | ⬜                          |
| Bulk action on a segment's current members — add points                                                                             | ⬜                          |
| Segment edited, condition changed, membership re-evaluates differently                                                              | ⬜                          |
| Segment deleted                                                                                                                     | ⬜                          |

## 3. RFM (`Model/Rfm/`, `Cron/RecomputeRfmScores.php`, `ordo/rfm/index`)

| Scenario                                                                                     | Status |
|----------------------------------------------------------------------------------------------|--------|
| Admin RFM report grid (`Controller/Adminhtml/Rfm/Index.php`) renders with real customer data | ⬜     |
| `Cron\RecomputeRfmScores` populates `ordo_customer_rfm_score`, report reflects it            | ⬜     |
| RFM Score column shows correct quintile digits (e.g. "555" for best-on-all-three)            | ⬜     |
| Percentile-based campaign condition (§1b) reads the precomputed table, not a live scan       | ⬜     |

## 4. Lead scoring (`Model/ScoreRule.php`, `Controller/Adminhtml/ScoreRule/`)

| Scenario                                                                                                 | Status |
|----------------------------------------------------------------------------------------------------------|--------|
| Create a score rule (attribute code, operator, value, points) via admin CRUD                             | ✅ `AdminScoreThresholdCampaignTest` |
| Operator: `equals`                                                                                       | ✅ `AdminScoreThresholdCampaignTest` |
| Operator: `not_equals`                                                                                   | ⬜     |
| Operator: `contains`                                                                                     | ⬜     |
| Customer save triggers `Observer/EvaluateCustomerScoreRules.php`, delta applied to `ordo_customer_score` | ✅ `AdminScoreThresholdCampaignTest` |
| Crossing the configured threshold fires `score_threshold_crossed` (chains §1a)                           | ✅ `AdminScoreThresholdCampaignTest` |
| Score rule edited/disabled — no longer contributes on next customer save                                 | ⬜     |
| Score rule deleted                                                                                       | ⬜     |

## 5. Free gift offers (`Model/FreeGiftOffer.php`, `Model/FreeGiftManagement.php`,

`Controller/Adminhtml/FreeGiftOffer/`, `Controller/Offer/`)

| Scenario                                                                                                                                                                            | Status                                                                                                                                                 |
|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|
| Create a free gift offer (tiers, product pool) via admin CRUD                                                                                                                       | ⬜                                                                                                                                                     |
| Real cart crosses a tier's `min_subtotal` — gift slot becomes available on the storefront                                                                                           | ⬜                                                                                                                                                     |
| Customer selects a free gift, it's added to cart at zero cost                                                                                                                       | ⬜                                                                                                                                                     |
| Cart drops below the tier threshold — previously-added gift is removed                                                                                                              | ⬜                                                                                                                                                     |
| Offer self-extension (`Controller/Offer/Extend.php`, `Offer::canSelfExtend()`)                                                                                                      | ⬜                                                                                                                                                     |
| "My Offers" storefront customer-account page (`Controller/Offer/Index.php`) lists the logged-in customer's offers, self-extend action visible only when `canSelfExtend()` allows it | ⬜ — missed entirely in the first pass of this document; caught auditing `Controller/Offer/*.php` directly against the doc rather than trusting memory |
| `Cron\SendOfferExpiryReminders` — reminder email before expiry                                                                                                                      | ⬜                                                                                                                                                     |
| `Cron\ExpireOverdueOffers` — offer past expiry marked expired, no longer redeemable                                                                                                 | ⬜                                                                                                                                                     |

## 6. Order approval (`Model/OrderApproval.php`, `Controller/Approval/`)

| Scenario                                                                                                                             | Status                             |
|--------------------------------------------------------------------------------------------------------------------------------------|------------------------------------|
| Real order over spend limit → held, approve email sent, approve link releases it                                                     | ✅ `AdminApproveOrderViaEmailTest` |
| Reject link (`Controller/Approval/Reject.php`) — order canceled, not released                                                        | ⬜                                 |
| Token re-use after approve/reject (already covered as the *second* half of `AdminApproveOrderViaEmailTest` — single-use enforcement) | ✅                                 |
| `Cron\EscalateStalePendingApprovals` — a pending approval past its SLA gets escalated                                                | ⬜                                 |
| Order under spend limit — never held at all (negative case)                                                                          | ⬜                                 |
| Customer with no spend limit / no approval admin email configured — never held                                                       | ⬜ (unit-tested only)              |

## 7. Tracking & popups (`view/frontend/web/js/tracker.js`, `Controller/Track/`)

| Scenario                                                                                                                              | Status                                      |
|---------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------|
| `ordo_visitor_id` cookie issued on first visit, stable across reload                                                                  | ✅ `StorefrontTrackerSetsVisitorCookieTest` |
| `page_view` event posted and persisted                                                                                                | ✅ `StorefrontTrackerPostsEventsTest`       |
| `product_view` event posted and persisted (scripted stand-in for a theme PDP hook)                                                    | ✅ `StorefrontTrackerPostsEventsTest`       |
| `category_view` event posted and persisted                                                                                            | ⬜                                          |
| `element_clicked` event posted and persisted (popup-targeting click threshold)                                                        | ⬜                                          |
| View-threshold crossing (default 3) tags the visitor, chains into `visitor_tag_added` (§1a)                                           | ⬜                                          |
| Click-threshold crossing (default 1) tags the visitor via `element_clicked`                                                           | ⬜                                          |
| A campaign's `popup` action writes a pending popup, storefront poll (`Controller/Track/Popup.php`) picks it up and renders the banner | ⬜                                          |
| Popup dismissed / closed client-side, doesn't reappear on next poll                                                                   | ⬜                                          |
| `Cron\PrunePendingPopups` — delivered/expired popups cleaned up                                                                       | ⬜                                          |
| `Cron\PruneVisitorEvents` — events past retention window removed                                                                      | ⬜                                          |
| Tracking disabled via config — `window.ordoTrack` calls become no-ops server-side (`reason: tracking_disabled`)                       | ⬜                                          |

## 8. Reorder cycles (`Model/ReorderCycle.php`, `Cron/CalculateReorderCycle.php`, `Cron/SendReorderReminders.php`)

| Scenario                                                                                  | Status                              |
|-------------------------------------------------------------------------------------------|-------------------------------------|
| Admin diagnostic grid renders                                                             | ✅ `AdminViewReorderCyclesGridTest` |
| `Cron\CalculateReorderCycle` detects a recurring purchase pattern from real order history | ⬜                                  |
| `Cron\SendReorderReminders` emails a customer whose predicted next-order date has arrived | ⬜                                  |

## 9. Dashboard (`Controller/Adminhtml/Dashboard/`)

| Scenario                                                                                        | Status                      |
|-------------------------------------------------------------------------------------------------|-----------------------------|
| Single "Ordo Automation" menu entry lands on the dashboard, stat cards render                   | ✅ `AdminViewDashboardTest` |
| Stat cards reflect real data (e.g. campaign count, trigger performance) after creating fixtures | ⬜                          |

## 10. Cron jobs not otherwise covered above

| Job                          | What it does                                                 | Status |
|------------------------------|--------------------------------------------------------------|--------|
| `SendCreditLimitAlerts`      | Emails when a customer's credit exposure crosses a threshold | ⬜     |
| `SendSalesRepDigest`         | Digest email to a sales rep                                  | ⬜     |
| `SendWinBackEmails`          | Emails customers `TagInactiveCustomers` tagged inactive      | ⬜     |
| `TagInactiveCustomers`       | Tags customers inactive past the configured window           | ⬜     |
| `SendAbandonedCartReminders` | Also the source of the `cart_abandoned` trigger (§1a)        | ⬜     |

## Suggested next batch (highest signal per test written)

1. ~~`score_threshold_crossed` end to end~~ (§4 + §1a) — done: `AdminScoreThresholdCampaignTest.xml` (`campaign`
   group). Deliberately no condition on the campaign (an empty conditions list is vacuously satisfied), so the coupon
   appearing is entirely down to the score-rule → threshold → dispatch chain actually working.
2. **`send_email` action + MailHog** (§1c) — reuses the exact MailHog wiring
   `AdminApproveOrderViaEmailTest`/`MailHogHelper` already established; closes the last uncovered action type.
3. **Free gift storefront flow** (§5) — the only *storefront cart* interaction pattern (adding a zero-cost item
   conditionally) nothing else in this suite exercises yet.
4. **In-segment condition chained with a real campaign dispatch** (§1b + §2) — the one condition type that depends on
   another whole feature (segments) rather than a single DB column, so it's the best test of the two features'
   integration, not just each in isolation.
