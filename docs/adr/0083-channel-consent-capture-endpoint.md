# ADR 0083: Channel consent capture endpoint (SMS/WhatsApp consent at checkout)

## Status

Accepted.

## Context

`ROADMAP.md`'s "Candidate new features" list flagged a real gap: `Model\ConsentManager`'s
channel-level default is "no row = consented" (an opt-out model) - legally thin for SMS
specifically (TCPA-style regimes expect affirmative prior consent, not silent opt-out), and there
was no checkout touchpoint capturing it either way (a grep for `checkout`/`sms_opt_in`/
`marketing_consent` found zero hits).

`ConsentManager` is entirely `customer_id`-keyed - a guest checkout has no customer entity to
attach a preference to at all. Asked the user directly: scope this to logged-in customers only
(a guest sees no consent checkbox, same opt-out behavior as today), rather than adding the extra
complexity of capturing a guest's choice conditionally on also checking "create an account."

## Decision

- **A lightweight theme-integration-point endpoint, not a native Magento checkout UI component.**
  `window.ordoCaptureChannelConsent(channel, consented)` posts a logged-in customer's consent
  choice straight to the backend on change, independent of order placement - the theme adds its
  own checkbox wherever it has a UI moment for one (a checkout SMS/WhatsApp consent checkbox next
  to the phone field is the motivating case, but nothing here is checkout-specific) and wires its
  `onchange` to this one call. Considered the "proper" Magento-native alternative - a
  `LayoutProcessor`/`ConfigProvider` injecting a real Knockout field into `checkout_index_index.xml`,
  read at order placement - and rejected it: a real reference implementation
  (`Magento\CheckoutAgreements`) runs to 6+ JS files, a `requirejs-config.js`, and its own layout
  XML for what is fundamentally one checkbox, would be the first time this module has ever
  touched a core page's own layout/JS component tree (every other storefront feature here -
  social proof, low stock, email capture, the cross-sell widget - is the same "theme calls a
  documented function with data it already has" shape), and ties the capture moment to order
  placement for no real benefit, since the customer is already identified by their own session
  the instant they're logged in - there's nothing to wait for.
- **No new consent primitive.** `Controller\Track\CaptureChannelConsent` delegates to
  `ConsentPreferencesManagementInterface::setMyChannelConsent()` - the exact same service
  `Controller\Consent\SetChannel` (the storefront "Communication Preferences" page) and REST/
  GraphQL already call, just adapted to a bare `fetch()` call instead of a full-page form POST.
  That service already resolves the current customer (throws `NoSuchEntityException` for a
  guest - reported back as the same `{"ok": false}` no-op this controller already has for an
  invalid channel) and validates `$channel` against the real `ConsentChannel` enum - nothing
  duplicated here.
- **CSRF**: the same trust model every other `Controller\Track\*` endpoint already uses
  (`VisitorIdentityResolver::validateForCsrf()`) - skipped for an anonymous request (which this
  controller no-ops anyway, since there's no customer to attach a preference to), checked against
  Origin/Referer for a logged-in one, since this is a bare `fetch()` call with no page-rendered
  `form_key` to attach.
- **Its own enable toggle** (`Helper\Config::isChannelConsentCaptureEnabled()`), gated also behind
  the top-level `tracking > enabled` toggle, matching every other `tracker.js`-driven feature -
  defaults off, same as every other new theme-integration-point widget in this module.

## Consequences

- A store can add a real SMS/WhatsApp consent checkbox to its checkout (or anywhere else a
  logged-in customer is present) with one call the theme adds to its own template - no new
  layout XML, no new Knockout component, no new table.
- The channel string isn't restricted to Sms/WhatsApp in the controller itself - any real
  `ConsentChannel` value is accepted, same validation `setMyChannelConsent()` already does for the
  account-page controller. A theme is free to use this for any channel; SMS/WhatsApp at checkout
  is simply the motivating, most legally-relevant case, not an enforced restriction.
- A guest checkout still gets no consent capture at all, even if they later create an account at
  order placement - accepted scope per the explicit decision above, not a gap this pass closes.
