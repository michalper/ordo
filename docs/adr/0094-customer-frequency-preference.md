# ADR 0094: Per-customer self-service message-frequency preference

## Status

Accepted.

## Context

`ROADMAP.md`'s 2026-11-28 brainstorm (sixth pass) found that `Helper\Config::
getFrequencyCapMaxMessages()`/`getFrequencyCapWindowHours()` (`Model\Campaign\
FrequencyCapManager::hasCapacity()`) are store-scoped only; the storefront "Communication
Preferences" page (`docs/adr/0054`, `ConsentPreferencesManagementInterface`) lets a customer
control channel/topic opt-in but not how *often* they hear from the store at all - the "let them
set their own cadence" field current preference-center guidance treats as standard alongside
channel/topic.

## Decision

- **A new nullable customer EAV attribute, `ordo_max_messages_per_window`**
  (`Setup\Patch\Data\AddCustomerMaxMessagesPerWindowAttribute`), not a new column on
  `ordo_customer_consent` as the original brainstorm note suggested. That table is one row per
  `(customer_id, channel)`, but `FrequencyCapManager`'s own cap is deliberately channel-agnostic
  (see that class's own docblock - it counts `ordo_message_log` rows across every channel
  together). A per-channel column would have no single well-defined value for `hasCapacity()` to
  read when a customer has rows for multiple channels. A plain customer attribute (the same
  pattern `AddCustomerSmsPhoneAttribute`/`AddSalesRepAttributes` already use) has exactly one
  value per customer, matching the cap's own scope - and is editable from the admin customer form
  for free, the same way those attributes already are.
- **`FrequencyCapManager::hasCapacity()` takes the TIGHTER of the admin cap and the customer's
  own value** - never the looser, so a customer's own preference can only restrict contact, never
  expand beyond what the admin already allows. A customer value `<= 0` is treated the same as
  "not set" (falls back to the admin cap alone), the same safer-failure-direction the admin-side
  value already gets for the identical reason - a customer accidentally storing a zero/negative
  value must never silently block every send to themselves forever.
- **Exposed through `ConsentPreferencesManagementInterface::setMyMaxMessagesPerWindow()`** (new
  REST route `PUT /V1/ordo/consent-preferences/mine/frequency`) and a new field on
  `ConsentPreferencesInterface::getMaxMessagesPerWindow()`, plus a new section on the storefront
  "Communication Preferences" page (`Controller\Consent\SetFrequency`) - the same "one service,
  every transport reads/writes through it" shape `setMyChannelConsent()`/`setMyTopicConsent()`
  already establish.

## Consequences

- **GraphQL is deliberately NOT updated** in this change - `Model\GraphQl\
  ConsentPreferencesDataMapper`/the `OrdoConsentPreferences` schema type still won't expose this
  new field. Safe to defer: the data mapper only reads specific known fields, so an unmapped
  addition to `ConsentPreferencesInterface` doesn't break anything, it's simply invisible to
  GraphQL clients until a follow-up adds it.
- **No `Test\Api` (real-HTTP, real-customer-token) coverage added** for the new REST route - none
  of this interface's existing routes have any either (confirmed before adding this one); the
  `UserContextInterface`-dependent service layer is unit-tested only, consistent with this
  module's own established precedent for self-service "mine" endpoints (see
  `LoyaltyWalletPassEndpointTest`'s own docblock for the same reasoning).
- A store that never has any customer set this preference sees no behavior change at all - the
  admin-configured cap alone still applies, same as before this shipped.
