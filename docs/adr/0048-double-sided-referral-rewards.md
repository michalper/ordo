# ADR 0048: Double-sided referral rewards

## Status

Accepted.

## Context

A second feature-gap brainstorm round (2026-11-12, checked against established marketing-
automation/loyalty platforms' public feature pages) flagged a real gap: `referral_converted`
(`Observer\DispatchReferralConvertedCampaigns`) only ever targets the *referrer* — it fires once
the referred customer's first order completes, crediting whoever referred them. The referred
customer who actually used the code gets nothing automatic from the engine itself today, only
whatever the referrer's own campaign happens to also send them manually. Established referral
programs default to two-sided ("give $10, get $10") specifically because an immediate incentive
for the *referred* side is what gets them to sign up and start shopping in the first place, while
the referrer side still only pays out on a real, completed purchase (preventing reward fraud from
a referral that never converts).

## Decision

- **A new `referral_signed_up` trigger** (`CampaignTriggerInterface::TRIGGER_REFERRAL_SIGNED_UP`),
  published from `Observer\RedeemReferralCode` the moment `Model\ReferralManager::recordSignup()`
  actually records a new signup (not for a self-referral or a customer who already has a referral
  row — the same guards that method already enforces). Context targets the REFERRED customer
  (`customer_id` = the person who just signed up), plus `referrer_customer_id` — the mirror image
  of `referral_converted`'s own `referred_customer_id` field.
- **Fires at signup, not at first order.** This is the deliberate half of "double-sided" that
  closes the actual gap: an immediate welcome incentive (typically a first-order discount via the
  existing `generate_coupon` action) independent of whether that customer ever places an order at
  all. `referral_converted` is unchanged — it still only fires, and only rewards the referrer, once
  a real purchase happens.
- **No new table, no new engine concept.** Same `CampaignDispatchPublisher` plumbing every other
  trigger already uses; a merchant builds a `generate_coupon`/`send_email` action on the new
  trigger exactly like any other, with zero changes to `CampaignDispatcher` itself (trigger
  matching is fully data-driven from `ordo_campaign_trigger` rows).

## Consequences

- A merchant can now build the common "refer a friend: they get 10% off, you get $10 when they
  buy" flow entirely from existing primitives — two separate campaigns (or two triggers on one
  campaign), no new feature to maintain beyond this one trigger constant and its dispatch point.
- The referred customer's incentive is unconditional on them ever ordering — a real cost/risk
  trade-off a merchant accepts by building a campaign on this trigger at all (same as any other
  engagement-only discount), not something this trigger itself needs to guard against.
- `referral_signed_up`'s context carries no `order_id` (nothing has been purchased yet), so it
  behaves the same way `customer_registered`/`tag_added` already do for
  `CampaignOutcomeLogger`/`Model\Sms\MessageLogWriter`'s optional order-race-guard parameter — no
  special-casing needed there.
