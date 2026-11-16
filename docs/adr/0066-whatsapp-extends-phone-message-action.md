# ADR 0066: SendWhatsApp joins AbstractPhoneMessageAction via a content-validation hook

## Status

Accepted.

## Context

A `/simplify` review of `Model/Campaign/Action/` (the same pass that produced the store-scoping
fix to `SendWhatsApp`'s enabled check) flagged, independently across its reuse/simplification/
altitude angles, that `SendWhatsApp` re-implements `AbstractPhoneMessageAction`'s entire
`execute()` skeleton — customer load, store-scoped enabled check, phone resolution, consent
check, quiet hours, frequency cap, E.164 validation — as a parallel ~190-line class instead of
extending it, even though `SendSms`/`SendRcs` already share exactly that skeleton (ADR 0049).
`SendWhatsApp`'s own docblock already acknowledged the duplication explicitly ("same store-scoped
ordering as AbstractPhoneMessageAction::execute()'s identical ordering") without actually fixing
it.

The reason it hadn't been merged before: `AbstractPhoneMessageAction`'s shared `execute()`
hardcoded a free-text "message" field — trim it, reject if blank, substitute the one
`{{recommended_products_text}}` token, then call `sendMessage(string $phone, string $message,
...)`. WhatsApp has no free-text message at all; it always sends an **approved template**
(`template_id` + positional `params`), and the WhatsApp Business Platform forbids free-form
messages outside an active customer-service window a campaign dispatch can't reliably detect
(see the class's own docblock). Naively extending the base would mean `$params['message']` is
always empty for a WhatsApp dispatch, so the base's own "missing message" check would fire for
every single send — the abstraction's contract and WhatsApp's content model were genuinely
incompatible as the base class was written.

## Decision

1. Replace the base class's hardcoded message-trim-validate-substitute block with a new
   overridable `protected function validateContent(array $params): bool` hook. The **default**
   implementation is exactly the old inline free-text logic, inherited unchanged by `SendSms`/
   `SendRcs` — zero behavior change for either.
2. Change `sendMessage()`'s signature from `(string $phone, string $message, ...)` to `(string
   $phone, array &$context, array $params)` — each channel derives its own content from `$params`
   inside `sendMessage()` rather than receiving a pre-resolved string. `SendSms`/`SendRcs` call
   the (still-shared) `substituteRecommendedProductsToken()` helper themselves; this redoes a
   cheap `trim()`/`str_replace()` call, not a repeated I/O cost.
3. `SendWhatsApp` now extends `AbstractPhoneMessageAction`, overriding `validateContent()` with
   its template_id/approved-template check (in place of the free-text default) and `sendMessage()`
   with its template-send logic. It reloads the template by `template_id` inside `sendMessage()`
   rather than threading the already-loaded object through from `validateContent()` — see
   "Consequences" below for why that's an accepted, separately-tracked cost rather than a blocker.

## Consequences

- **Reordering, not a behavior change in outcome.** Template validation used to run right after
  the enabled check, before phone resolution. It now runs where the base class's content check
  always ran for every sibling: after consent/quiet-hours/frequency-cap/E.164. A campaign with a
  genuinely broken `template_id` now does a little more per-customer gating work before failing,
  but the end result for every customer is identical — an error is logged, nothing is sent.
- **One extra primary-key lookup per dispatch.** `validateContent()` loads the `WhatsAppTemplate`
  to check it's approved; `sendMessage()` loads it again to read `getMetaTemplateName()`/
  `getLanguage()`, rather than the base class growing a generic "resolved content" type (string,
  mixed, or an instance property threaded between two methods) just to avoid a second indexed
  single-row `SELECT`. The real fix for repeated template loads is a per-batch cache (loading a
  given `template_id` once across every customer in a dispatch, not once-or-twice per customer) —
  tracked as a separate, already-identified efficiency finding, not solved here.
- `SendWhatsApp` still implements `messageFieldLabel()` (abstract on the base, genuinely needed by
  `SendSms`/`SendRcs`) even though it fully overrides `getParamFields()` — but reuses it as the
  `template_id` field's own label rather than hardcoding that string a second time, so the hook
  stays genuinely used instead of becoming dead code only this subclass carries.
- `SendWhatsApp` gets `consentTopicField()` for free instead of hand-copying the same "Consent
  topic" field array literal `SendEmail`/`SendPush` still duplicate — those two remain untouched
  by this change; they don't extend `AbstractPhoneMessageAction` (not phone-addressed) and are a
  separate, smaller finding.
