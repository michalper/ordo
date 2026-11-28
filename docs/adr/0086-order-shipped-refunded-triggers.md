# ADR 0086: `order_shipped`/`order_refunded` campaign triggers

## Status

Accepted.

## Context

`ROADMAP.md`'s 2026-11-28 brainstorm found a real gap, grep-verified against the actual code: the
22 registered `TRIGGER_*` constants in `Api/Data/CampaignTriggerInterface.php` cover `order_placed`
and `order_canceled`, but nothing on the shipment or refund side. A store running Ordo had no way
to build a "your order is on its way" or a post-refund touchpoint inside its own campaign engine -
two of the most standard lifecycle sends any marketing-automation tool offers - and was stuck
relying on Magento's own unbranded, unautomatable shipment/credit-memo emails instead.

## Decision

- **Two new trigger types, `TRIGGER_ORDER_SHIPPED`/`TRIGGER_ORDER_REFUNDED`, on Magento core's own
  `sales_order_shipment_save_after`/`sales_order_creditmemo_save_after` events** - real core events,
  not a module-owned one, same category as `order_cancel_after`. `Observer\DispatchOrderShippedCampaigns`/
  `Observer\DispatchOrderRefundedCampaigns` extend a new shared
  `Observer\AbstractDispatchOrderSubEntityCampaigns` (the same "extract a shared abstract once two
  near-identical observers exist" precedent `AbstractDispatchOrderCampaigns` already set for
  `order_placed`/`order_canceled`), since both read the parent order via `$subEntity->getOrder()`
  rather than from the event directly.
- **Both needed the same resave guard `DispatchOrderPlacedCampaigns` already documents for
  `sales_order_save_after`, applied to a different entity.** `sales_order_shipment_save_after`/
  `sales_order_creditmemo_save_after` fire on *every* save of the same shipment/credit memo, not
  just its creation - an admin adding a tracking number to an existing shipment
  (`Order\Shipment\Track\Save`) or a comment to an existing credit memo (`Order\Creditmemo\AddComment`)
  resaves it. Without a guard, either action would silently re-dispatch a trigger that already
  fired once for the real shipment/refund event. `AbstractDispatchOrderSubEntityCampaigns::
  isGenuinelyNew()` checks `getOrigData(ENTITY_ID) === null`, the exact same technique
  `DispatchOrderPlacedCampaigns::shouldDispatch()` uses for the order entity itself - a genuinely
  new entity has no orig data for its own identity column at this point in
  `AbstractModel::afterSave()`, one being resaved does.
- **A partial-shipment or partial-refund order fires once per sub-entity, not once per order.** A
  3-box order fires `order_shipped` three times (one per real shipment created), and two separate
  credit memos fire `order_refunded` twice - each is a real, distinct fulfillment/refund event a
  store might reasonably want to message about individually (tracking numbers differ per shipment),
  not a single order-level occurrence to collapse into one dispatch.
- **Context carries the usual `customer_id`/`order_total`/`order_increment_id`/`order_id` keys every
  other order trigger does, plus entity-specific fields under their own keys, never overloading an
  existing one:**
  - `order_shipped` adds `shipment_id`, `tracking_numbers` and `carrier_titles` (both
    comma-separated, empty string if the shipment has no tracks at all - the established
    free-text-list shape `country_in`/`Action\SendWhatsApp` params already use, not a new
    multi-value structure, and consistent with `SendEmail`'s own "every scalar value in `$context`
    becomes a template variable" contract, which an array value can't satisfy).
  - `order_refunded` adds `creditmemo_id` and `refund_total` (the credit memo's own
    `base_grand_total`) as separate keys from `order_total`, which stays the *order's* own
    `base_grand_total` for consistency with every other order trigger. Reusing `order_total` for
    the refund amount would silently change what the existing `OrderTotalAtLeast` condition means
    for this one trigger only, compared to every other order-based trigger it's also usable with.
- **Two new Flow-canvas starter templates** ("Order Shipped Notification", "Post-Refund Follow-Up"),
  same minimal shape as the existing "Canceled Order Recovery" one - trigger plus a single
  `send_email` action, no new action/condition type needed.

## Consequences

- A store can build a shipment-notification or post-refund-recovery campaign using the existing
  Flow canvas, condition set, and `send_email`/`generate_coupon`/survey actions unchanged - no new
  admin UI, action type, or database table.
- `Block\Adminhtml\Dashboard\DashboardViewModel::TRIGGER_LABELS` (the dashboard's "sent by fixed
  trigger" breakdown) is a second, hand-maintained constant - already stale against most triggers
  added since `order_placed`/`customer_registered`/`tag_added`/`cart_abandoned`/`visitor_tag_added`/
  `score_threshold_crossed` (`order_canceled`, `browse_abandoned`, `exit_intent`, and a dozen others
  are already missing from it). Deliberately not touched here: adding just these two would imply a
  completeness this list doesn't actually have, and bringing it fully in sync with
  `CampaignTriggerInterface`'s real trigger set is a separate, pre-existing cleanup, not something
  to fold into this change's scope.
- Both triggers need a real shipment/credit-memo flow through the admin to exercise - verified
  end-to-end via `AdminCampaignOrderShippedTriggerTest`/`AdminCampaignOrderRefundedTriggerTest`
  (ships/refunds a real order through the real admin order view page, drains the real DB-backed
  queue, asserts the real Funnel widget), same depth `AdminCampaignOrderCanceledTriggerTest`
  already established for `order_canceled`.
