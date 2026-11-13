# ADR 0055: Campaign holdout group for incremental-lift measurement

## Status

Accepted.

## Context

A fourth-round brainstorm candidate (2026-11-13) flagged a real gap, confirmed by reading
`Model\Campaign\SplitVariantSelector`/`SplitWinnerCalculator` directly: the `split` campaign
action only ever divides traffic between message variants that all actually send something.
There is no way to carve off a small, randomly-assigned percentage of matching customers who
receive nothing at all from a campaign - the no-send control group every established
marketing-automation/CDP platform's own holdout/incremental-lift testing uses to answer "would
this customer have converted anyway?" rather than just comparing variant A's conversion rate to
variant B's. `Model\CampaignOutcomeLogger::getStats()` already aggregates sent/converted/revenue
from `ordo_campaign_outcome_log` - nothing upstream of it ever fed in a customer who was
deliberately never sent anything.

The original roadmap bullet framed the integration point as "the same place
`QuietHoursGate`/`FrequencyCapGate` already gate a dispatch." That framing turned out to be
inaccurate on inspection: those two gates are invoked per individual `Send*` action
(`Model\Campaign\Action\SendEmail`/`SendSms`/`SendWhatsApp`/`SendPush`/`SendRcs`,
`AbstractPhoneMessageAction`), not once per campaign dispatch. A holdout has to mean "this
customer gets zero actions from this campaign run, logged once" - gating per-action would still
let every actionless side effect before the gated action run (e.g. a preceding `add_tag`), and
would log a holdout row once per gated action instead of once per campaign entry.

## Decision

- **Checked once in `CampaignDispatcher::runCampaign()`**, immediately after
  `conditionsSatisfied()` returns true and before `runActionsFrom()` is ever called - the one
  place both `dispatch()` and `dispatchScheduledTrigger()` already converge for the per-campaign
  entry-guard check, so a held-out customer is logged exactly once and never reaches a single
  action, not even a non-sending one like `add_tag`.
- **New nullable `ordo_campaign.holdout_percent` column (0-100, default null/0 = off)** - the same
  "0 is a meaningful, backward-compatible default" convention this module's own store-config
  toggles already use (see `Helper\Config::intConfig()`'s docblock). Every existing campaign row
  reads this back as 0 and is therefore never held out unless an admin explicitly raises it.
  Exposed only in the classic "Campaign Information" form (`ordo_campaign_form.xml`'s `general`
  fieldset), not in the Flow canvas (`Block\Adminhtml\Campaign\Edit\Flow`) - deliberately
  following the exact precedent `condition_logic` already set on this same model
  (`Model\Campaign::getConditionLogic()`'s own docblock: "the Flow canvas is the only editor for
  campaign conditions today and doesn't yet expose this toggle"). A campaign-level setting that
  isn't a trigger/condition/action node doesn't have an obvious place on that node-based canvas
  yet; adding one is future work, not blocking this feature's v1.
- **Deterministic assignment via a new `Model\Campaign\HoldoutSelector`, mirroring
  `SplitVariantSelector`'s own crc32-bucket technique** rather than a fresh `mt_rand()` per
  dispatch - the same customer must land on the same side of the holdout line across every repeat
  dispatch of the same trigger (and across a `delay_minutes` resume chain, though in practice a
  held-out customer never enters one, since the check returns before `runActionsFrom()`).
  Deliberately its own class, not a method added to `SplitVariantSelector`: holdout is
  customer-only (no visitor/email fallback - an unreliable identity makes a holdout group
  meaningless rather than merely less precise) and hashes a `'holdout:<campaign>:<customer>'`
  string, keeping its own bucket space independent of `SplitVariantSelector`'s
  `'campaign:splitAction:identity'` one so the two features can never collide on the same hash for
  the same customer.
- **New `is_holdout` boolean column (default 0) on `ordo_campaign_outcome_log`, written via a new
  `CampaignOutcomeLogger::logHeldOut()`** (an optional-bool param on `logSent()` was rejected -
  the two call sites have genuinely different inputs: `logSent()` always has a variant and a real
  `ordo_message_log` row behind it, `logHeldOut()` never has either, and a shared method with a
  `?string $variant` that's always null for one caller reads worse than two named methods sharing
  one small private `insertOutcomeRow()` helper). The existing race-protection catch-up
  (`$triggerOrderId` lookup against an already-existing later order, `markActed()`) is shared
  between both paths unchanged - a held-out customer's own later order still has to count toward
  this group's organic conversion rate.
- **`getStats()`/`getStatsForWindow()` now filter `is_holdout = 0`.** Without this, a held-out
  customer would silently count as "sent" in the funnel report and in
  `SplitWinnerCalculator`'s auto-winner sample-size gate, exactly the kind of quiet skew the
  roadmap bullet itself warned against ("never conflated with getStats()'s sent/converted").
- **Reporting scope for v1: a new `CampaignOutcomeLogger::getHoldoutStats()` method (holdout
  count/converted/conversion_rate per campaign), no new admin grid UI.** The roadmap bullet's own
  framing - "`getStats()` already has everything needed ... just nothing upstream feeds it one" -
  turned out to undersell the reporting half once the flag exists: the aggregation query itself is
  a five-minute mirror of `getStatsForWindow()`'s own shape, reading `is_holdout = 1` instead of
  `= 0`. Building a new grid/section in `ordo/campaigncomparison/index` to visualize the
  sent-vs-holdout comparison was deliberately left out of this PR - that's a UI/UX design
  question (where does it live, does it need a date range, does a small-sample campaign need a
  "not enough data yet" state) the roadmap bullet didn't actually ask for, and speculative grid
  work risks shipping UI nobody asked to see yet. `getHoldoutStats()` is the right-sized middle
  ground: the comparison is computable and testable today, any future caller (the funnel report,
  a CLI report, a REST endpoint) can read it directly without touching `ordo_campaign_outcome_log`
  again.

## Consequences

- A merchant can now measure a campaign's real incremental lift, not just compare message
  variants against each other - set `holdout_percent` above 0 on a campaign, then compare
  `getStats()`'s sent-group conversion rate against `getHoldoutStats()`'s holdout-group one.
- `holdout_percent` is per-campaign, not per-split-action - a campaign with a `split` action still
  divides its *sent* traffic between variants as before; the holdout slice is carved off before
  any variant is ever chosen, since the check runs before `runActionsFrom()`/`runSplit()`.
- Not exposed on `Api\Data\CampaignInterface` (no REST surface) for the same reason
  `condition_logic` already isn't - this module's REST campaign surface predates per-field
  granularity, and widening it is a separate, larger decision this PR doesn't make.
- This was implemented and verified with this repo's own standalone unit-test toolchain only
  (phpcs/php-cs-fixer/PHPStan/rector/PHPUnit) - no live Magento instance was available in this
  session, so the schema migration, admin form rendering, and the dispatcher's real DB-backed
  behavior are NOT verified against a running install. CI's integration/MFTF suites will exercise
  the actual `setup:upgrade` and a real dispatch for the first time post-push.
