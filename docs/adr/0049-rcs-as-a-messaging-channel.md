# ADR 0049: RCS as a messaging channel

## Status

Accepted.

## Context

A fresh brainstorm round (2026-11-12) flagged a real gap: `Model\ConsentChannel` and the action
pool in `etc/di.xml` already cover Email, SMS, Push, and WhatsApp, but not RCS (carrier-level
rich messaging — branded sender, richer formatting, without requiring the recipient to have a
specific app installed the way WhatsApp does). `Model\Sms\TwilioSmsSender` already proves out the
provider this most naturally extends — Twilio's own API serves RCS Business Messaging through the
same Programmable Messaging surface this module already calls for SMS.

## Decision

- **A new `send_rcs` campaign action and `ConsentChannel::Rcs` case, not a new provider
  integration.** `Model\Rcs\RcsSenderInterface`/`TwilioRcsSender` and
  `Model\Campaign\Action\SendRcs` share the same skeleton as `SmsSenderInterface`/
  `TwilioSmsSender`/`SendSms` — same `ordo_sms_phone` customer attribute and
  `PhoneNumberResolver` (RCS is still addressed by phone number via carrier routing), same
  `ConsentManager`/`QuietHoursGate`/`FrequencyCapGate` plumbing, same `ordo_message_log` channel
  string ('rcs'). That plumbing is already channel-agnostic (a plain string parameter), so adding
  RCS needed no change to any of it.
- **Extracted shared base classes instead of letting `SendSms`/`SendRcs` (and
  `TwilioSmsSender`/`TwilioRcsSender`) duplicate that skeleton outright.** An initial
  implementation mirrored `SendSms`/`TwilioSmsSender` as independent, parallel classes — the same
  shape `SendWhatsApp`/`SendPush` already use relative to `SendSms` — but crossed SonarCloud's
  new-code duplication gate (12.4% vs. the 3% ceiling), correctly: the resulting duplication was
  real copy-paste, not incidental overlap. Fixed properly rather than suppressed: `Model\Campaign\
  Action\AbstractPhoneMessageAction` now owns the full shared skeleton (resolve customer, check
  the channel's own enabled flag, resolve phone, check consent, quiet hours, frequency cap,
  validate E.164, substitute the `{{recommended_products_text}}` token), with `SendSms`/`SendRcs`
  as thin subclasses supplying only the channel string, config check, consent channel, and the
  `sendMessage()` step - the one place SMS's opted-out/retry-queue handling and RCS's simpler
  single-attempt send genuinely diverge. `Model\Twilio\AbstractTwilioMessageSender` does the same
  for the two Twilio senders (client construction/caching, rate-limited `messages->create()`
  call, REST-error handling), with `TwilioSmsSender`/`TwilioRcsSender` supplying only the
  provider-specific request options and (SMS only) the STOP-keyword-to-`OptedOutException`
  translation. `SendWhatsApp`/`SendPush` were deliberately left as-is — they didn't trip the
  duplication gate, so refactoring them now would be unrelated scope creep, not a fix for anything
  broken.
- **Reuses SMS's own Twilio Account SID / API Key credentials**, not a separate credential set —
  both are the same Twilio account's Programmable Messaging surface. Only one RCS-specific config
  field is new: `ordo_channels/rcs/twilio_messaging_service_sid`, since RCS is addressed via a
  Messaging Service SID (Twilio Console → Messaging → Services) rather than a phone number as
  SMS's own "From" is.
- **No classic-editor (`ordo_campaign_form.xml`) dedicated-field wiring**, matching the precedent
  already set for every action type added since the Flow canvas existed (`send_whatsapp`,
  `send_push`, `send_ga4_event`, `send_meta_conversion_event` — none of those have classic-editor
  switcherConfig rules either). `SendRcs::getParamFields()` is all the Flow canvas needs; the
  classic editor's existing `message`/`topic` dedicated fields are already generically labeled
  ("Message" / "Consent topic", not "SMS message") and could be wired up later if the classic
  editor itself gets revisited, but that's a pre-existing gap this feature doesn't need to close.
- **Two deliberate v1 gaps, not oversights:**
  - **No automatic opted-out detection.** `SendSms`'s Twilio-SMS-error-21610 → `OptedOutException`
    handling is specific to the STOP-keyword reply convention, which RCS has no equivalent of —
    RCS blocking happens at the device/carrier level. `TwilioRcsSender` never throws an
    opted-out-specific exception; a real RCS-side block still surfaces as a generic send failure
    (`recordFailed()`), not silently as `recordOptedOut()`. The channel-level consent toggle (GDPR
    screen, now listing RCS alongside the other channels) remains the only way to stop RCS sends
    to a given customer.
  - **No delivery-status callback, no retry queue participation.** Unlike `SendSms`, `SendRcs`
    does not enqueue failed sends into `MessageSendRetryQueue` — Twilio's RCS error taxonomy
    (which failures are transient vs. permanent) isn't established well enough yet to retry
    indiscriminately without risking a double-send on an error that was actually permanent. A
    failed RCS send is logged and recorded as failed, once, same as `SendSms`'s own final
    (non-retriable) failure path.
- **Consent plumbing extended everywhere a channel list was hand-maintained**, not just the enum:
  `Model\Gdpr\ConsentStates` (new `rcs` property/getter), `Block\Adminhtml\Gdpr\Index` and
  `Controller\Adminhtml\Gdpr\Export`'s `$channels` arrays. `ConsentManager` itself, the admin GDPR
  template (`view/adminhtml/templates/gdpr/index.phtml`), and `Controller\Adminhtml\Gdpr\
  SetConsent` needed no change — all three were already generic over whatever `ConsentChannel`
  cases exist, exactly as `ConsentChannel`'s own docblock (describing the earlier WhatsApp-missing
  bug) intended.

## Consequences

- A merchant with Twilio RCS provisioned can add a "Send RCS" step to any campaign today, with
  the same consent/quiet-hours/frequency-cap guarantees every other messaging channel already has.
- RCS carrier/device support is still regional and inconsistent (unlike SMS's universal reach), so
  this is a genuine additive channel for stores with the right audience, not a wholesale SMS
  replacement — consistent with how the ROADMAP item that prompted this flagged the same risk.
- The two documented v1 gaps (no opt-out detection, no retry) mean RCS sending is less resilient
  than SMS's today. Closing either needs real-world Twilio RCS error data this module doesn't
  have yet, not a guess — a follow-up iteration, not this one.
