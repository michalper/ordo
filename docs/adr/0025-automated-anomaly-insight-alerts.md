# ADR 0025: Automated anomaly/insight alerts

## Status

Accepted.

## Context

ROADMAP.md's "Candidate new features" asked for "a periodic cron flagging notable deviations (a
channel's revenue dropping, a coupon/campaign outperforming others)", explicitly flagged as the
most speculative item in that list — no real usage data existed yet to pick sane thresholds, so it
was left for last. Picking it up now, the schema itself settles most of the open design questions
ADR 0017 (Revenue/channel analytics report) already surfaced: **channel is not tracked at the
campaign-outcome or attribution level at all.** `ordo_campaign_outcome_log` and
`ordo_campaign_attribution` both key off `campaign_id`; the only place a send's channel
(email/sms/whatsapp/push) is recorded is `ordo_message_log.channel`, written by a separate code
path (`Model\Sms\MessageLogWriter`/`Model\Push\PushSubscriptionSender`) with no shared row id back
to the outcome/attribution tables — joining the two by `campaign_id`+`variant`+`customer_id`+
nearby timestamps would be an approximation prone to fan-out (a recurring campaign can send to the
same customer more than once), not the honest per-channel number an alert implying "X dropped"
needs to be.

## Decision

- **Scoped to per-campaign anomalies, not per-channel.** The roadmap's two examples ("a channel's
  revenue dropping", "a coupon/campaign outperforming others") are both represented at
  campaign granularity, which the schema already supports cleanly with no new joins: most
  campaigns in practice use one primary send channel, so a campaign-level revenue drop is a
  reasonable, honest proxy for a channel-level one without fabricating a join path the data can't
  actually support — the same "drop it, don't fake it" discipline ADR 0017 applied to device
  tracking.
- Two alert types, both computed over a rolling window (`Helper\Config::getAnomalyLookbackDays()`,
  default 7 days) against an equal-length trailing baseline window immediately before it (days
  8–14 back, for the default):
  - `campaign_revenue_drop` — a campaign's revenue (`CampaignOutcomeLogger::getStatsForWindow()`,
    the same first-plausible-match `sales_order.base_grand_total` sum `getStats()` already uses
    elsewhere in this module, e.g. the dashboard's funnel summary — not
    `ordo_campaign_attribution`: that table has no order-date column to window by, only
    `computed_at`, and would need its own join to `sales_order` to get one) in the current window
    dropped by at least `getAnomalyRevenueDropThresholdPercent()` (default 30%) versus its own
    baseline window. Requires baseline revenue > 0 — a campaign with no baseline revenue has
    nothing to have dropped from, so it's skipped, not flagged as a "100% drop".
  - `campaign_conversion_outlier` — a campaign's conversion rate in the current window deviates
    from the **average conversion rate of every other qualifying campaign in that same window** by
    at least `getAnomalyConversionOutlierThresholdPercent()` (default 50%), in either direction
    (under- or over-performing — "outperforming" alone would miss the equally-actionable case of a
    campaign quietly underperforming its peers).
  - Both checks require `getAnomalyMinSampleSize()` (default 20) sent messages in the relevant
    window before a campaign is even considered — guards against flagging noise on a
    just-launched or low-volume campaign, where a 50% swing is one or two customers, not a real
    signal.
- New `ordo_anomaly_alert` table (one row per detected alert: `alert_type`, `campaign_id` — no FK,
  same reasoning as every other historical log table in this module: a row must survive the
  campaign it references being deleted — `message`, `current_value`, `baseline_value`,
  `deviation_percent`, `detected_at`), new `Cron\DetectAnomalies` (default daily, mirrors every
  other `Helper\Config`-gated cron in this module's shape), new read-only admin grid
  (`ordo/anomalyalert/index`) mirroring `Cron\CronRunLog`'s — the simplest existing grid in this
  module — plus a dashboard stat card (`Block\Adminhtml\Dashboard\DashboardViewModel::
  getRecentAnomalyAlertCount()`) linking into it, same pattern as "Order approvals stuck"/"Crons
  failed".
- **Deliberately a log, not a dismissable queue.** Every other "did something go wrong" view in
  this module (`CronRunLog`, `AdminActionLog`) is an immutable append-only log with no
  acknowledge/dismiss action — an anomaly alert is the same shape (a point-in-time observation,
  not an open ticket needing resolution), so it gets the same simplest-possible grid rather than
  a new mass-action/acknowledged-state pattern this module doesn't otherwise have.
- **Cooldown via the existing `Model\Cron\ReminderLogStore::claim()`**, not a new dedup mechanism —
  same atomic check-then-insert (`GET_LOCK()`-serialized) pattern `SendCreditLimitAlerts`/
  `SendOfferExpiryReminders`/`SendReorderReminders` already use for "don't re-fire the same
  alert every tick": a `(alert_type, campaign_id)` pair won't alert again within
  `getAnomalyAlertCooldownDays()` (default 7, matches the lookback window so each window alerts
  at most once) of its last detection, even though the cron itself runs daily and both windows
  slide every day.
- New `Model\CampaignOutcomeLogger::getStatsForWindow()` — the existing `getStats()` has no date
  bound at all (all-time), so a new windowed sibling was added rather than retrofitting a nullable
  date-range onto the existing method's signature and every one of its current call sites. Grouped
  by `campaign_id` only (not `campaign_id`+`variant` like `getStats()`) — this report ranks
  campaigns against each other and their own trailing baseline, the same "ignore the variant axis"
  choice ADR 0023's `CampaignComparison\Grid\Collection` already made for the same reason.

## Consequences

- Revenue inherits `getStats()`'s existing first-plausible-match double-counting caveat: if the
  same customer was sent more than one campaign within the attribution lookback window and then
  ordered, `CampaignOutcomeLogger::markActed()` can mark more than one campaign's outcome row with
  that same order — so the same order's revenue can count toward more than one campaign's
  `campaign_revenue_drop` check. This is not new: it's the same limitation `getStats()` already
  has everywhere else it's used (the dashboard funnel summary included) — `ordo_campaign_
  attribution` exists specifically to answer the de-duplicated version of this question (see ADR
  0023), just not windowable by order date without its own added join, which this pass didn't need
  for a same-campaign-vs-its-own-baseline comparison (the double-count bias, if any, is roughly
  symmetric across both windows).
- No per-channel anomaly detection exists yet — if real usage later shows campaign-level alerts
  are too coarse (e.g. a merchant running the same campaign across both email and SMS wants to
  know which channel specifically dropped), that needs the same channel-attribution gap ADR 0017
  already logged as open, not something to retrofit onto this cron alone.
- Thresholds (30% revenue drop, 50% conversion-rate deviation, minimum 20 sent, 7-day window) are
  defaults chosen for directional sanity, not calibrated against real production data — this was
  exactly the risk the roadmap flagged in deferring this item. They're ordinary `Helper\Config`
  fields precisely so a real merchant can retune them once they have a sense of their own normal
  variance, with no code change needed.
- An alert is a historical observation, not an actionable item with a lifecycle (no "resolved"/
  "acknowledged" state) — if real usage later shows merchants want to triage/dismiss alerts rather
  than just see a log, that's a materially different UI (mass actions, a status column) better
  scoped as its own follow-up once there's a reason to believe it's needed, not spliced into this
  pass.
