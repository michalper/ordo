# ADR 0013: Resolve the customer's own store for customer-facing sends, not the ambient store

## Status

Accepted.

## Context

Every campaign action and reminder cron that sends to a customer (`SendEmail`, `SendSms`,
`SendReorderReminders`, `SendWinBackEmails`, `SendOfferExpiryReminders`, `SendCreditLimitAlerts`)
resolved its store via `StoreManagerInterface::getStore()` with no argument, or read
`Helper\Config` with no `$storeId`. In a cron/CLI dispatch context (ADR 0001 - campaign dispatch
is always async, off the request thread) there is no real "current store" at all; Magento falls
back to the first/default store, which is simply wrong for a customer registered on any other
store on a multi-store install - the wrong template locale, the wrong sender address, and
store-scoped feature toggles (e.g. `ordo_channels/sms/enabled`) checked against the wrong scope.

`Observer\HoldOrderForApproval`/`Cron\EscalateStalePendingApprovals` already had the right pattern
for order-scoped sends: resolve `$order->getStore()` (the order's own store), not the ambient one.
This ADR extends the same reasoning to every other customer-facing send, where the natural
"authoritative store" is the recipient customer's own, not an order's.

A real complication surfaced while fixing this: `CustomerInterface::getStoreId()` has no return
type declared on the interface itself, and a real customer repository fetch returns it as a
string, not `int|null` - confirmed by a real integration-test `TypeError` once every call site
assumed `int|null` directly. A `null` (no store on record) must stay `null` (meaning "use the
ambient default store" to `StoreManagerInterface::getStore()`/`Helper\Config::is*Enabled()`), not
become `(int) null = 0` - a real, different store id.

## Decision

- `Model\CustomerStoreResolver::resolveStoreId(CustomerInterface $customer): ?int` centralizes the
  int-or-string-or-null coercion once, reused by every call site below instead of six separate,
  easy-to-get-subtly-wrong inline casts.
- `SendEmail`/`SendSms` resolve the customer's store via this resolver instead of the ambient
  current store/config scope.
- `Model\Cron\ReminderEmailSender::getStore()` gained an optional `?int $storeId` parameter
  (previously unused externally, so this is additive, not a breaking change) - every one of
  `SendReorderReminders`, `SendWinBackEmails`, `SendOfferExpiryReminders`, `SendCreditLimitAlerts`
  now passes the recipient customer's resolved store through it to `send()`'s existing
  `?StoreInterface $store` override parameter.
- `SendAbandonedCartReminders` uses the abandoned quote's own `getStoreId()` instead (a quote is
  always genuinely store-scoped already, and `Quote::getStoreId()` already casts to `int`
  internally - no `CustomerStoreResolver` needed there).
- `Observer\HoldOrderForApproval::isOrderApprovalEnabled()` now also receives the order's own
  store id (the email-link-building code right next to it already did; the config check itself
  didn't).
- `Cron\SendSalesRepDigest` is deliberately **not** changed: it aggregates customer names across
  potentially many different stores into one digest email per sales rep, not per customer - there
  is no single "the customer's store" to resolve for a cross-customer aggregate, and sales rep
  digests are an internal/admin-facing communication, not customer-facing storefront email.

## Consequences

- A customer-facing send on a multi-store install now renders with that customer's own store's
  template, locale, and sender address, and checks that store's own feature-toggle scope - not
  whatever store happens to be ambient/default in the cron/CLI process that ran it.
- Test fixtures/helpers that set store-scoped config via `MutableScopeConfig::setValue(...,
  ScopeInterface::SCOPE_STORE)` with no explicit scope code (relying on it happening to match the
  production code's own un-scoped read) needed updating to pass the real store id explicitly, so
  write and read scope agree - `CampaignSendSmsActionTest`/`SendAbandonedCartFallbackRemindersTest`
  both had exactly this latent coincidence.
- `ordo_campaign`/`ordo_segment`/`ordo_score_rule` still have no store/website scoping of their
  own (which campaigns/segments apply where) - that's a separate, larger gap, tracked in
  `ROADMAP.md`, not addressed by this ADR.
