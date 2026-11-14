# ADR 0059: Wishlist abandonment reminder trigger

## Status

Accepted.

## Context

A fifth-round brainstorm candidate (2026-11-14) flagged a real gap, confirmed by reading the code
directly: `Observer\TrackWishlistAdd` and `Model\Config\Source\EventType`'s `wishlist_add` value
only ever feed the one-shot `event_occurred` segment condition - there was no `Cron\*` scan
mirroring `Cron\SendBrowseAbandonmentReminders`/`TRIGGER_BROWSE_ABANDONED` for "this registered
customer still has an item in their wishlist N days later and never bought it," a standard
retention flow every established marketing-automation/CDP platform ships as distinct from cart
recovery. `Observer\TrackWishlistAdd`'s own docblock already notes that a Magento wishlist
requires a logged-in customer to exist at all, so - unlike browse abandonment, which has to carve
out an anonymous-visitor case this module's own `ordo_visitor_event` table tracks - there is no
anonymous wishlist concept anywhere to handle.

## Decision

- **Reads Magento core's own native `wishlist`/`wishlist_item` tables directly, not
  `ordo_visitor_event`.** `Observer\TrackWishlistAdd` never writes a wishlist add into this
  module's own event table in a way a scan could reuse for this - `wishlist_add` rows only exist
  to feed the `event_occurred` condition. Magento's native `wishlist_item.added_at` is the
  authoritative "when was this item added" timestamp regardless of whether this module's own
  tracking observer even ran (e.g. for a store that disabled tracking, or a wishlist item added
  before this module was installed) - this is the first time any code in this module queries
  those two core tables, confirmed by grep.
- **New `Cron\SendWishlistAbandonmentReminders`, same scan-claim-dispatch shape every reminder
  cron in this module already uses, mirroring `Cron\SendBrowseAbandonmentReminders` closely.** A
  new `TRIGGER_WISHLIST_ABANDONED` (`wishlist_abandoned`) constant on
  `Api\Data\CampaignTriggerInterface`, a new `ordo_wishlist_abandoned_reminder_log` satellite
  table (identical shape to `ordo_browse_abandoned_reminder_log`: `customer_id` + `event_key`
  (the SKU) + `sent_at`, capped via the existing `ReminderLogStore::claim()`), and a matching
  "Wishlist Abandonment" `system.xml` group (enabled flag, `delay_days`, `max_reminders`) copied
  from the "Browse Abandonment" group's own field naming/doc convention. Dispatches a campaign
  trigger only, with no built-in reminder email of its own - same reasoning as
  `browse_abandoned`: there's no cart/order entity here to summarize into a fixed template, so a
  store wires whatever action it wants onto the trigger via the campaign builder.
- **The "did they already buy it" check is scoped to the exact SKU and to orders placed after the
  wishlist add, not "any order since" like `browse_abandoned` uses.** `browse_abandoned`
  deliberately keeps a loose "placed no order at all since the view" check, because a single
  product view isn't a strong enough purchase-intent signal to justify tying the check to that
  exact SKU (see that cron's own docblock). A wishlist add is a much more deliberate signal, so
  this reuses the per-SKU join shape `Model\Purchase\PurchasedProductResolver::hasPurchasedSku()`
  already established for the `purchased_sku` condition (`sales_order` joined to
  `sales_order_item` on `oi.sku`) - with an added date bound (`sales_order.created_at >
  wishlist_item.added_at`) that resolver doesn't need, since its own callers ask "ever bought this,
  at any point," not "bought it since this specific event." Reusing the resolver class itself
  wasn't possible without changing its signature (it has no date parameter), so the join is
  rebuilt inline as a correlated `NOT EXISTS` subquery, the same technique
  `SendBrowseAbandonmentReminders` already uses for its own looser version of this check - not a
  new pattern, a parameterized variant of an existing one.
- **A customer who already owned the SKU before wishlisting it (again) is correctly still
  "abandoning"** - the date bound only excludes orders placed *after* the wishlist add, not any
  order ever. This is a deliberate, narrow scope match to the roadmap bullet's own wording ("no
  matching later order line for that SKU"), not an oversight: a customer who re-wishlists
  something they already own is a real, if unusual, repeat-purchase-intent signal a store may
  still want to act on.

## Consequences

- A merchant can now build a wishlist-abandonment campaign (e.g. a reminder email or a
  discount offer) the same way they already can for cart and browse abandonment, closing the one
  gap in that three-stage funnel.
- `ordo_wishlist_abandoned_reminder_log` is a new table a fresh `setup:install`/`setup:upgrade`
  has to create - no apostrophe in its table comment (see `AGENTS.md`'s own warning about this
  exact class of bug).
- This was implemented and verified with this repo's own standalone unit-test toolchain only
  (phpcs/php-cs-fixer/PHPStan/rector/PHPUnit) - no live Magento instance was available in this
  session, so the schema migration, the real `wishlist`/`wishlist_item` join against a populated
  database, and the dispatcher's real DB-backed behavior are NOT verified against a running
  install. A `Test/Integration/SendWishlistAbandonmentRemindersTest.php` was written following
  this repo's established Integration-test conventions (real DI/DB, no mocks, manual `tearDown()`
  cleanup) to prove the scan-claim-dispatch cycle end to end, but could not be executed in this
  session either - CI's integration suite will exercise the actual `setup:upgrade` and a real scan
  for the first time post-push.
