# ADR 0014: Website-scoping via plain linking tables, not Magento core's EntityManager pattern

## Status

Accepted. Phase 1 (campaigns) implemented; segments and score rules follow the same shape in a
later pass (see `ROADMAP.md`).

## Context

GitHub issue #261 asked about multi-store support. ADR 0013 fixed store-scoped *sends*. The
remaining gap: `ordo_campaign`, `ordo_segment`, and `ordo_score_rule` have no website scoping at
all — a campaign/segment/score-rule matches a customer identically regardless of which website
they're on.

Research before implementing confirmed two things worth deciding explicitly:

1. **No website-scoping precedent exists anywhere in this codebase** (zero hits for
   `website_ids`/`getWebsiteIds`/`multiselect` before this change).
2. **Magento core's own mechanism for this exact problem** (`salesrule_website`, used by Cart
   Price Rules) is real but heavier than anything else in this module: a PK-less, dual-FK link
   table wired through the EntityManager extension-attribute pipeline (`SaveHandler`/
   `ReadHandler` + an `AssociatedEntityMap` virtualType in `di.xml`, calling
   `Magento\Rule\Model\ResourceModel\AbstractResource::bindRuleToEntity()`/
   `getAssociatedEntityIds()`). This module's own house style for every existing parent/child
   relationship (`CampaignSaveProcessor`/`SegmentSaveProcessor`'s trigger/condition/action/
   condition rows) is instead: a plain linking table with its own `entity_id` PK, one FK to the
   parent, managed by a dedicated SaveProcessor method that deletes-and-reinserts, loaded via a
   filtered Collection.

## Decision

1. **Empty website selection = unscoped (matches every website)**, not "matches nothing." The
   only backward-compatible default: every existing campaign/segment/score-rule on an upgrade has
   no website rows yet and must keep matching exactly as before. The "can't verify → fail closed"
   philosophy this module otherwise follows (e.g. an unknown condition/action type) applies at
   the *per-campaign* level once a campaign IS scoped (see point 4), not to the absence of any
   scoping at all.
2. **Plain linking tables**, mirroring this module's own existing shape, not Magento core's:
   `ordo_campaign_website` (`entity_id` PK, FK to `ordo_campaign.entity_id` `onDelete="CASCADE"`,
   plain `website_id` smallint column, `unique(campaign_id, website_id)`) — `ordo_segment_website`
   and `ordo_score_rule_website` to follow the identical shape. No FK into `store_website` itself
   — this module doesn't FK into core tables anywhere else either (e.g.
   `ordo_message_log.campaign_id` has no FK to `ordo_campaign`, for the same "survive the
   referenced side being removed" reasoning), and a website being removed shouldn't silently
   corrupt a campaign's own save/delete behavior via a core-table FK this module doesn't own.
3. **Campaign dispatch filtering happens by resolving the customer's website once, inside
   `CampaignDispatcher`, not by threading `website_id` through every trigger-publishing
   observer's context.** Checked all 3 real-time trigger observers
   (`DispatchOrderPlacedCampaigns`, `DispatchCustomerRegisteredCampaigns`,
   `DispatchTagAddedCampaigns`) — none carry `website_id` today, and making all of them (plus
   every other trigger type) do so would be a much larger, more error-prone change than resolving
   it once from `context['customer_id']` via `CustomerRepositoryInterface` +
   `CustomerStoreResolver::resolveWebsiteId()` (the same int-or-string-or-null-safe accessor ADR
   0013 built for `getStoreId()` — `getWebsiteId()` has the identical untyped-interface issue).
4. **The website filter runs AFTER `campaignIdsForTrigger()`'s cached candidate-campaign lookup,
   as a separate step — not baked into the cached payload.** That cache is keyed only by
   `$triggerEvent`, shared across every customer/website that fires it; keeping it that way means
   none of the 4 existing cache-invalidation call sites (`CampaignTriggerRepository`,
   `Controller\Adminhtml\Campaign\Delete`, `CampaignSaveProcessor`, `CampaignRepository`) need to
   change. The filter itself: load every website row for the trigger's (already-narrowed)
   candidate campaign ids in one query; a campaign with no rows is left alone (unscoped); a
   campaign with rows is dropped unless the resolved customer website id is among them — and
   dropped too if the website id couldn't be resolved at all (fails closed for that one
   campaign, not silently open).
5. **Admin UI**: a `formElement="multiselect"` field (`dataScope: website_ids`, matching Magento
   core's own field-naming convention for the equivalent Cart Price Rule field) whose options come
   from a new `Model\Config\Source\Website` (`OptionSourceInterface`, backed by
   `StoreManagerInterface::getWebsites()`) — website-level, not the store-view-level tree Magento
   core's own CMS Block/Page "Store View" field uses, since every comparison this feature actually
   makes (`CustomerStoreResolver::resolveWebsiteId()`, `customer_entity.website_id`) is at the
   website level, not store-view level.

## Consequences

- A website-scoped campaign now only fires for customers on that website; an unscoped one
  (the default, pre-existing behavior) is completely unaffected — confirmed by the full existing
  `CampaignDispatcherTest`/`CampaignSaveProcessorTest` suites passing unchanged, plus new tests
  for both the scoped-match and scoped-no-match/unresolvable-website paths.
- One extra query per dispatch when a trigger's candidate set includes website-scoped campaigns,
  plus (only then) one customer-repository lookup to resolve the triggering customer's own
  website — zero extra cost for the common case (no website-scoped campaigns at all).
- Segments and score rules will each need their own near-identical SaveProcessor/DataProvider/
  ui_component wiring in a follow-up pass (tracked in `ROADMAP.md`) — accepted duplication of the
  *shape*, not the underlying `Model\Config\Source\Website` options class, which is already
  shared.
