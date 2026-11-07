# ADR 0027: The Promotional consent topic defaults to opt-in, not opt-out

## Status

Accepted.

## Context

`Model\ConsentManager`'s channel-level register (`ordo_customer_consent`) is, by design (see that
class's own docblock), an opt-out register: a customer with no explicit row is treated as
consented. ADR 0026 built `ConsentTopic`/`hasTopicConsent()` as a second, topic-granular register
on top of that, and carried the exact same "no row = consented" default forward for both topics
it introduced (`Promotional`, `AccountUpdates`), without re-examining whether that default is
appropriate for each one individually.

It isn't, for `Promotional`. `Promotional` covers exactly the sends that are electronic marketing
in the ordinary sense - win-back, abandoned cart, offer expiry, and the default topic for any
admin-built campaign send. EU ePrivacy Directive / PECR-style rules governing electronic
marketing require an affirmative opt-in for it specifically, not merely that an opt-out is
honored when one exists - "no explicit opt-out on file" is not equivalent to "the customer
affirmatively agreed to receive marketing," which is what those rules actually ask for.
`AccountUpdates` (reorder reminders, credit limit alerts) is a materially different category -
notices about the customer's own account/order state, the same standard this module already
applies to transactional mail (never consent-gated at all) - and keeping it default-opt-in is
not the same compliance question.

## Decision

- `ConsentTopic` gains `defaultsToConsented(): bool` - `Promotional` returns `false`,
  `AccountUpdates` returns `true` (preserving ADR 0026's original behavior for that one topic).
- `ConsentManager::hasTopicConsent()` and `hasTopicConsentForCustomers()` consult
  `$topic->defaultsToConsented()` instead of a single hardcoded `true` whenever no explicit
  `ordo_customer_consent_topic` row exists. A channel-level opt-out still always wins outright
  (narrows, never widens) in both methods, unchanged from ADR 0026.
- `Block\Adminhtml\Gdpr\Index::getTopicConsentStates()` (the admin GDPR/Consent screen's own
  "what does this customer's preference currently show as" read path) picks up the same
  per-topic default, so the UI an admin acts on and the actual send-gating logic can never
  silently disagree about what "no row yet" means for a given topic.
- No change to `ConsentChannel`-level behavior (`hasConsent()`/`setConsent()`) at all - this is
  scoped entirely to the topic-level register ADR 0026 introduced.
- No new data patch/migration. Every existing customer already has no `Promotional` row (the
  register is new), so this takes effect immediately for all of them without needing a backfill
  step - existing opted-out rows (`consented = false`) are unaffected either way.

## Consequences

- **This silently stops every `Promotional`-topic send** (the three reminder crons mapped to it
  in ADR 0026, plus any admin-built campaign send whose `topic` is `Promotional` or unset) for
  every customer who has never had an explicit topic-level preference recorded for them - which,
  since this register only just started existing, is effectively the entire existing customer
  base. This is the deliberate point of the change, not a bug: those sends were never truly
  opt-in before, and now they require it.
- There is still no storefront self-service surface for topic preferences (ADR 0026's own
  already-documented gap) - today the only way to get a customer explicitly opted back in to
  `Promotional` is an admin doing it by hand on the GDPR/Consent screen
  (`Controller\Adminhtml\Gdpr\SetTopicConsent`). That gap is now materially more urgent than it
  was under ADR 0026 (where it only meant "no customer control," not "marketing is off for
  everyone until someone acts") - closing it (e.g. wiring the storefront newsletter-subscription
  checkbox, or a new preference page, to `setTopicConsent()`) is a real follow-up, but out of
  scope for this pass, which is a compliance-default fix, not a new opt-in feature.
- `AccountUpdates` is completely unaffected - every existing behavior and test expectation for
  that topic (and for `hasConsent()`/channel-level consent generally) stays exactly as it was.
