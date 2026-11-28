<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

/**
 * One trigger event that starts a campaign. A campaign has one or more of these — e.g. both
 * `customer_registered` and `tag_added` can start the exact same conditions/actions chain — so
 * this is its own child entity (same pattern as CampaignConditionInterface/
 * CampaignActionInterface), not a single scalar field on CampaignInterface.
 */
interface CampaignTriggerInterface
{
    public const ENTITY_ID = 'entity_id';
    public const CAMPAIGN_ID = 'campaign_id';
    public const TRIGGER_EVENT = 'trigger_event';
    public const PARAMS = 'params';

    public const TRIGGER_ORDER_PLACED = 'order_placed';

    /**
     * Fires from Observer\DispatchOrderCanceledCampaigns on Magento core's own order_cancel_after
     * event (dispatched by \Magento\Sales\Model\Order::cancel()) - covers the admin "Cancel"
     * button, the REST/SOAP cancel API, and this module's own
     * Model\OrderApprovalManagement::rejectByToken(), which all go through cancel(). Context
     * carries the same keys as TRIGGER_ORDER_PLACED ("customer_id", "order_total",
     * "order_increment_id", "order_id") so existing {{var ...}} email templates work unchanged
     * regardless of which of the two triggers fired a campaign.
     */
    public const TRIGGER_ORDER_CANCELED = 'order_canceled';
    public const TRIGGER_CUSTOMER_REGISTERED = 'customer_registered';
    public const TRIGGER_TAG_ADDED = 'tag_added';
    public const TRIGGER_CART_ABANDONED = 'cart_abandoned';

    /**
     * One step earlier in the funnel than TRIGGER_CART_ABANDONED - fires from
     * Cron\SendBrowseAbandonmentReminders for a registered customer who viewed a product
     * (`ordo_visitor_event` rows with event_type `product_view`) but placed no order since, see
     * that cron's own docblock.
     */
    public const TRIGGER_BROWSE_ABANDONED = 'browse_abandoned';

    public const TRIGGER_VISITOR_TAG_ADDED = 'visitor_tag_added';
    public const TRIGGER_SCORE_THRESHOLD_CROSSED = 'score_threshold_crossed';

    /**
     * One-off: fires at most once, at (or shortly after, once
     * Cron\DispatchScheduledCampaignTriggers next scans) the datetime in this trigger's own
     * params (key "scheduled_at", e.g. "2026-11-28 09:00:00") - see
     * Model\Campaign\ScheduledTriggerScanner.
     */
    public const TRIGGER_SCHEDULED_AT = 'scheduled_at';

    /**
     * Repeating: fires every time the current time matches this trigger's own params cron
     * expression (key "cron_expression", e.g. "0 8 * * 1" for every Monday at 08:00) - matched
     * the same way Magento's own cron scheduler matches a job's <schedule>, via
     * Magento\Cron\Model\Schedule::matchCronExpression(). Precision is bounded by how often
     * Cron\DispatchScheduledCampaignTriggers itself runs (every 5 minutes) - a cron_expression
     * whose minute field isn't a multiple of 5 will never match a scan and so will never fire.
     */
    public const TRIGGER_RECURRING_SCHEDULE = 'recurring_schedule';
    public const TRIGGER_WEBHOOK_RECEIVED = 'webhook_received';

    /**
     * Fires from Cron\ScanPriceDropAlerts for an ordo_price_watch_subscription row whose
     * watch_type is `price_drop` once the product's current price drops below its
     * last_known_price - see that cron's own docblock.
     */
    public const TRIGGER_PRICE_DROP = 'price_drop';

    /**
     * Fires from Cron\ScanBackInStockAlerts for an ordo_price_watch_subscription row whose
     * watch_type is `back_in_stock` once the product transitions from out-of-stock to
     * in-stock - see that cron's own docblock.
     */
    public const TRIGGER_BACK_IN_STOCK = 'back_in_stock';

    /**
     * Fires from Cron\ScanReviewRequestDue for a registered customer's completed order once
     * `review_request_delay_days` has passed since it was placed - context carries `order_id`
     * alongside the usual `customer_id`, same shape as TRIGGER_ORDER_PLACED. Claimed via
     * ordo_review_request_log, one dispatch per order - see that cron's own docblock.
     */
    public const TRIGGER_REVIEW_REQUEST_DUE = 'review_request_due';

    /**
     * Fires from Observer\DispatchReferralConvertedCampaigns when a referred customer places
     * their first order - context targets the REFERRER (customer_id = the person who referred,
     * not the customer who just ordered), plus "referred_customer_id" and "order_id". See
     * Model\ReferralManager for the referral lifecycle this closes.
     */
    public const TRIGGER_REFERRAL_CONVERTED = 'referral_converted';

    /**
     * Fires from Observer\RedeemReferralCode the moment a new customer registers with someone
     * else's referral code and Model\ReferralManager::recordSignup() actually records it (not for
     * a self-referral or a customer who already has a referral row) - context targets the
     * REFERRED customer (customer_id = the person who just signed up), plus
     * "referrer_customer_id". The double-sided counterpart to TRIGGER_REFERRAL_CONVERTED, which
     * instead targets the referrer once this same customer's first order completes - a campaign
     * on this trigger is the "you get 10% off your first order" half, independent of whether
     * that first order ever happens.
     */
    public const TRIGGER_REFERRAL_SIGNED_UP = 'referral_signed_up';

    /**
     * Fires from Cron\DispatchCustomerInactiveCampaigns for a registered customer whose most
     * recent non-canceled order (or registration, if they never ordered) is older than this
     * trigger's own `days` param - unlike Cron\TagInactiveCustomers/SendWinBackEmails (a single,
     * fixed-threshold, non-composable email), this is a real trigger a campaign builds on, so a
     * store can run several win-back-shaped campaigns at different inactivity windows (e.g. a
     * 30-day nudge and a separate 90-day "we miss you" with a bigger incentive). Claimed via
     * ordo_customer_inactive_trigger_claim, re-firable after Helper\Config::
     * getCustomerInactiveRefireCooldownDays() - a customer can leave and re-enter inactivity more
     * than once, unlike TRIGGER_REVIEW_REQUEST_DUE's one-dispatch-per-order-forever shape.
     */
    public const TRIGGER_CUSTOMER_INACTIVE = 'customer_inactive';

    /**
     * Fires live, from Controller\Track\Event (via the "ordo_visitor_exit_intent" Magento event
     * and Observer\DispatchExitIntentCampaigns - same decoupling shape as
     * TRIGGER_VISITOR_TAG_ADDED/VisitorTagManager, though here purely because there's no
     * Magento-core event to hook into, not to avoid a DI cycle), the moment tracker.js detects
     * the visitor's mouse leaving toward the top of the viewport - see docs/adr/0037. Context
     * carries only "visitor_id", same anonymous-only shape as TRIGGER_VISITOR_TAG_ADDED; a
     * logged-in visitor's customer_id is deliberately not included; see that ADR for why.
     */
    public const TRIGGER_EXIT_INTENT = 'exit_intent';

    /**
     * Fires from Cron\DispatchCustomerAnniversaryCampaigns, once per calendar year, for a
     * registered customer whose `customer_dob`, account `created_at`, or own first order's
     * `created_at` (`MIN(sales_order.created_at)` per customer) month+day matches today - which
     * of the three this trigger's own `date_source` param ("dob", "registration", or
     * "first_order") selects.
     * Unlike TRIGGER_CUSTOMER_INACTIVE's rolling, re-fireable-after-a-cooldown shape, a birthday/
     * anniversary is a fixed calendar date that can only genuinely recur once a year, so
     * ordo_customer_anniversary_trigger_claim is keyed by (customer_id, campaign_id, fired_year)
     * instead of a cooldown window - no config to tune, "has this already fired this year" is
     * the whole rule. February 29 is a deliberately accepted edge case: a customer with that
     * month+day only ever matches on an actual leap year, same as every other system that stores
     * a plain date without its own leap-year remapping.
     */
    public const TRIGGER_CUSTOMER_ANNIVERSARY = 'customer_anniversary';

    /**
     * One step earlier in the wishlist funnel, the same shape TRIGGER_BROWSE_ABANDONED already
     * established for product views - fires from Cron\SendWishlistAbandonmentReminders for a
     * registered customer who added a product to their wishlist (Magento core's own native
     * `wishlist`/`wishlist_item` tables, NOT `ordo_visitor_event` - a Magento wishlist always
     * requires a logged-in customer, unlike a product view) at least `delay_days` ago and has no
     * non-canceled `sales_order_item` row for that same SKU placed since the item was added. See
     * that cron's own docblock.
     */
    public const TRIGGER_WISHLIST_ABANDONED = 'wishlist_abandoned';

    /**
     * Fires from Cron\DispatchSegmentBlastCampaigns for every customer currently resolved by this
     * trigger's own `segment_id` param (Model\Segment\SegmentMemberResolver) - the one-time/
     * scheduled "send this campaign to a segment" primitive ROADMAP.md flagged as missing: every
     * other trigger either reacts to a real event or, like TRIGGER_SCHEDULED_AT/
     * TRIGGER_RECURRING_SCHEDULE, fires campaign-wide with no customer_id in context at all.
     * Optional `scheduled_at` param (same format as TRIGGER_SCHEDULED_AT) delays the first scan
     * until that moment; omitted or blank fires as soon as the next scan (every 5 minutes) sees
     * the trigger. Dispatched at most once ever per (customer_id, campaign_id) pair via
     * ordo_segment_blast_trigger_claim, the same one-dispatch-per-pair-forever shape
     * ordo_review_request_log uses (not customer_inactive's re-firable cooldown window - a blast
     * has no notion of "leaving and re-entering" to re-fire from).
     *
     * Known v1 shape, not an oversight: this re-resolves the segment's CURRENT membership on every
     * scan, so a customer who joins the segment after the blast starts still gets it on a later
     * scan (claimed-per-customer, so never twice) - it is not a frozen point-in-time snapshot of
     * who was in the segment the moment the trigger became due. Disable or delete the trigger once
     * the intended audience has been covered if an ongoing stream to new joiners isn't wanted.
     */
    public const TRIGGER_SEGMENT_BLAST = 'segment_blast';

    /**
     * Fires live, from Controller\Track\Event (via the "ordo_visitor_search_no_results" Magento
     * event and Observer\DispatchSearchNoResultsCampaigns - same decoupling shape
     * TRIGGER_EXIT_INTENT already established: there's no Magento-core event for "the tracking
     * endpoint was called", so the controller fires one itself), the moment a theme reports a
     * search that returned zero results via `window.ordoTrack('search_no_results', queryText)`.
     * Unlike TRIGGER_EXIT_INTENT's deliberately anonymous-only context, a search is meaningful for
     * a logged-in customer too, so context carries "customer_id" whenever the request is
     * authenticated (same resolution VisitorIdentityResolver::resolveCustomerId() already does in
     * that same controller for every other event type), alongside "visitor_id" always. The same
     * event_type string is also written to ordo_visitor_event (event_key = the search query text),
     * so it's immediately usable as a segmentation condition via the existing `event_occurred`
     * condition with zero new condition code - see Model\Config\Source\EventType.
     */
    public const TRIGGER_SEARCH_NO_RESULTS = 'search_no_results';

    /**
     * Fires from Observer\DispatchOrderShippedCampaigns on Magento core's own
     * sales_order_shipment_save_after event - one dispatch per real shipment (a partial-shipment
     * order fires this once per shipment, e.g. "box 1 of 2 shipped" then "box 2 of 2 shipped"),
     * guarded against re-firing when an existing shipment is merely resaved later (e.g. adding a
     * tracking number via Order\Shipment\Track\Save) - see that observer's own docblock, the same
     * trap DispatchOrderPlacedCampaigns already documents for sales_order_save_after. Context
     * carries the same "customer_id"/"order_total"/"order_increment_id"/"order_id" keys every
     * other order trigger does, plus "shipment_id", "tracking_numbers" and "carrier_titles" (both
     * comma-separated, empty string if the shipment has no tracks at all - same established
     * free-text-list shape Action\SendWhatsApp's own "params" field already uses, not a new
     * multi-value structure).
     */
    public const TRIGGER_ORDER_SHIPPED = 'order_shipped';

    /**
     * Fires from Observer\DispatchOrderRefundedCampaigns on Magento core's own
     * sales_order_creditmemo_save_after event - one dispatch per credit memo (a partial refund
     * followed by a second, separate credit memo fires this twice), with the same
     * resave-reacting-to-the-same-entity guard TRIGGER_ORDER_SHIPPED above needs (an admin adding
     * a comment to an existing credit memo resaves it). Context carries the usual
     * "customer_id"/"order_total"/"order_increment_id"/"order_id" keys - "order_total" is always
     * the ORDER's own base_grand_total, consistent with every other order trigger, never the
     * refund amount - plus "creditmemo_id" and "refund_total" (the credit memo's own
     * base_grand_total) as separate keys, so a template or condition can tell "what the order was
     * worth" and "how much actually came back" apart.
     */
    public const TRIGGER_ORDER_REFUNDED = 'order_refunded';

    /**
     * Fires from Cron\SendLoyaltyPointsExpiringReminders for a registered customer's own earn
     * row (`ordo_loyalty_points_ledger`, `type = earn`, `expired_at IS NULL`) once its `created_at`
     * falls within `Helper\Config::getLoyaltyPointsExpiryWarningDays()` of the cutoff
     * `Cron\ExpireLoyaltyPoints` will later expire it at - same "warn before, then act" shape as
     * `TRIGGER_REVIEW_REQUEST_DUE`'s own cron. Context carries "customer_id" and
     * "at_risk_points" (that one earn row's own point amount, not a per-customer total - a
     * customer with more than one row approaching expiry in the same scan dispatches once per
     * row, same reasoning `TRIGGER_WISHLIST_ABANDONED` dispatches once per wishlist item rather
     * than once per customer). Claimed via `ordo_loyalty_points_expiring_reminder_log`, unique on
     * the ledger row's own `entity_id` - at most one warning ever per earn row.
     */
    public const TRIGGER_LOYALTY_POINTS_EXPIRING = 'loyalty_points_expiring';

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId(int $entityId): self;

    /**
     * @return int
     */
    public function getCampaignId(): int;

    /**
     * @param int $campaignId
     * @return $this
     */
    public function setCampaignId(int $campaignId): self;

    /**
     * @return string
     */
    public function getTriggerEvent(): string;

    /**
     * @param string $triggerEvent
     * @return $this
     */
    public function setTriggerEvent(string $triggerEvent): self;

    /**
     * Raw JSON string, e.g. {"scheduled_at": "2026-11-28 09:00:00"}. Named *ParamsJson*, not
     * *Params*, for the same reason CampaignConditionInterface::getParamsJson() is - avoids
     * colliding with the model's own getParams(): array helper. Null/unused for every
     * event-based trigger type, which carries no config of its own.
     *
     * @return string
     */
    public function getParamsJson(): string;

    /**
     * @param string $paramsJson
     * @return $this
     */
    public function setParamsJson(string $paramsJson): self;
}
