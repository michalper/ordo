# ADR 0026: Per-topic preference granularity within a channel

## Status

Accepted.

## Context

ROADMAP.md's "Candidate new features" asked for "per-topic preference granularity within a
channel — split a channel's opt-in further (e.g. 'promotional' vs 'product update' email) beyond
today's per-channel consent", explicitly flagged as needing real merchant demand confirmed first.
Picked up anyway on direct instruction.

Today's consent model (`Model\ConsentManager`, `ordo_customer_consent`) is a single opt-out
register per `(customer_id, channel)` pair, where `ConsentChannel` is `Email`/`Sms`/`Push`/
`WhatsApp` (message-delivery channels) plus `Ads` (not a delivery channel — governs ad-audience
upload, see that enum's own docblock). Opting out of `Email` opts a customer out of every
consent-gated email this module sends: the four `send_*` campaign actions (admin-configured,
could be anything) and five reminder crons (`SendWinBackEmails`, `SendCreditLimitAlerts`,
`SendAbandonedCartReminders`, `SendReorderReminders`/`ReorderReminderSender`,
`SendOfferExpiryReminders`). There was no way to opt out of marketing email while keeping, say,
reorder reminders, or vice versa.

## Decision

- **Two fixed topics** (`Model\ConsentTopic`, a backed enum like `ConsentChannel`), not an
  open-ended admin-defined list: `Promotional` (marketing/recovery sends driven by business
  goals: win-back, abandoned cart, offer expiry, and the default for any admin-built campaign
  send) and `AccountUpdates` (reminders about the customer's own account/order state: reorder
  reminders, credit limit alerts). Every existing consent-gated send in this module maps cleanly
  onto one of these two — a small, fixed enum is what `hasTopicConsent()`'s own
  default-consented-unless-explicit-opt-out semantics needs to stay simple, and matches
  `ConsentChannel`'s own precedent of a backed enum over a hand-maintained string list (that
  enum's own docblock documents why: a stale string silently defaulted to "consented" once).
  Topics apply only to the four message-delivery channels, never to `Ads` — ad-audience
  upload isn't a "send" with a topic.
- **A second, orthogonal opt-out register** (`ordo_customer_consent_topic`, `Model\
  CustomerConsentTopic`), not a column added to `ordo_customer_consent` or a new required
  parameter on `hasConsent()`/`setConsent()`. `ConsentManager::hasTopicConsent()` is true only
  when BOTH the existing channel-level register AND this new topic-level one say so (checks the
  cheaper channel-level one first, since no topic-level override at all is the common case) — an
  explicit topic-level opt-out can only narrow consent, never widen it past a channel-level
  opt-out. This means every one of the 11 existing production `hasConsent()`/
  `hasConsentForCustomers()` call sites keeps working completely unchanged; only the sends that
  actually have a topic call the new `hasTopicConsent()`/`hasTopicConsentForCustomers()` methods
  instead. The alternative (topic as a nullable dimension folded into `ordo_customer_consent`
  itself) would have meant redefining what "no row" means for every existing channel-level
  consent check, and touching all 11 call sites regardless of whether they have any topic concept
  at all (`Cron\SyncAdAudiences`'s `Ads` check, for one, structurally never will).
- **The five existing reminder crons/senders now call `hasTopicConsent()`/
  `hasTopicConsentForCustomers()` with a fixed, hardcoded topic** (not admin-configurable) rather
  than being left on the old channel-only check: `SendWinBackEmails`/`SendAbandonedCartReminders`/
  `SendOfferExpiryReminders` → `Promotional`; `SendCreditLimitAlerts`/
  `SendReorderReminders`/`ReorderReminderSender` → `AccountUpdates`. This is what actually gives a
  customer real control — leaving these six on the old channel-only check would have meant the
  new topic toggle only ever affected admin-built campaigns, not the sends the roadmap's own
  example ("product update" email) most directly describes.
- **The four `send_*` campaign actions gain an optional `topic` param**, defaulting to
  `Promotional` when absent/invalid (`ConsentTopic::tryFrom(...) ?? ConsentTopic::Promotional`) —
  most ad-hoc admin-built campaigns are marketing in nature, so that's the safer silent default
  for every campaign saved before this feature existed. Wired into
  `Model\Campaign\CampaignSaveProcessor::ACTION_FIELDS_BY_TYPE` (closing the exact "field posted
  but not in the allow-list gets silently dropped" trap a previous pass already found and fixed
  for `send_whatsapp`/`send_push`, see `docs/CHANGELOG.md`), the Flow canvas
  (`Block\Adminhtml\Campaign\Edit\Flow::getFieldsConfig()`, inline options — a fixed 2-value enum,
  not DB-driven, same reasoning `use_optimal_send_time`'s own inline options already use on that
  block), and the classic dynamicRows editor for `send_email`/`send_sms` only — `send_whatsapp`/
  `send_push` have no classic-editor fields at all today (Flow-canvas-only), so adding one just
  for `topic` would be new, unprecedented surface area for those two types, not consistent with
  how every other one of their fields already works.
- **Admin-only management**, via a new "Topic preferences" card on the existing GDPR/Consent
  screen (`Controller\Adminhtml\Gdpr\SetTopicConsent`, mirroring `SetConsent` exactly), not a new
  storefront self-service preference page. No storefront consent UI exists for channel-level
  consent today either (opt-out happens only via the SMS/WhatsApp STOP keyword, the SendGrid
  Event Webhook's own hosted unsubscribe link, or this same admin screen) — building storefront
  self-service for topics alone, while channels still have none, would be new, inconsistent
  surface area, not an extension of an existing pattern.
- `ordo_customer_consent_log`'s existing append-only audit trail gained a nullable `topic` column
  (null = a channel-level change, non-null = a topic-level one) rather than a whole second log
  table — one shared audit trail answers "was this customer opted in for X on date Y" for both
  kinds of change the same way it already did for channel-level ones.
- `Model\Gdpr\CustomerDataTableProvider`'s single source of truth gained
  `ordo_customer_consent_topic` — both `CustomerDataEraser`/`CustomerDataExporter` pick it up
  automatically, the whole reason that extraction exists (see that class's own docblock: it went
  stale twice before).

## Consequences

- Only two topics exist; if real merchant demand (which this item was originally deferred
  pending) surfaces a need for more granularity (e.g. a third topic, or per-merchant custom
  topics), that's a bigger design - `ConsentTopic` would need to stop being a fixed backed enum,
  which is a larger change than this pass, not a trivial case addition.
- No storefront self-service exists for topic preferences, same gap as channel-level consent
  already has. An admin must still make the change on the customer's behalf.
- The four `send_*` campaign actions' `topic` param is admin-set once per action, not derived from
  anything about the campaign's own trigger/content — an admin who builds a "promotional under
  the hood but service-shaped" campaign must remember to set it, there's no automatic
  classification.
