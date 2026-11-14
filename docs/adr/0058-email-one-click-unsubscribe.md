# ADR 0058: Email one-click unsubscribe

## Status

Accepted.

## Context

A fourth-round brainstorm candidate (2026-11-13) flagged a real gap, checked directly against the
code rather than assumed: `Model\Campaign\Action\SendEmail` and the new `Controller\Consent\*`
storefront preference center (ADR 0054) never set a `List-Unsubscribe`/`List-Unsubscribe-Post`
header, and the only way a customer could opt out of marketing email was logging into the
storefront account area first. Gmail/Yahoo's 2024 bulk-sender rules require a one-click,
no-login unsubscribe mechanism from any sender crossing their volume threshold, and every
established ESP ships one by default. `Model\ConsentManager::setConsent()` already does the only
thing that actually needs to happen (upsert `ordo_customer_consent`, append to
`ordo_customer_consent_log`) - this was missing an entry point into it that doesn't require a
session, not a new consent model.

## Decision

- **Stateless, signed HMAC token - no new table, no DB lookup to verify.**
  `Model\Email\UnsubscribeTokenManager` issues a token shaped
  `"<customerId>.<channel>.<signature>"`, where the signature is
  `EncryptorInterface::hash("<customerId>|<channel>")` - Magento's own `hash_hmac()` helper, keyed
  by this installation's crypt key (`app/etc/env.php`). The alternative considered was a stored,
  random, single-use token per customer+channel the same shape
  `Model\Approval\ApprovalTokenGenerator`'s order-approval token uses (a new
  `ordo_customer_unsubscribe_token` table, looked up by a `loadByToken()`-style resource method).
  Rejected: an order-approval token is naturally single-purpose (approve once, consumed, done),
  but an unsubscribe link is logically per-(customer, channel) and must stay valid indefinitely -
  the same link embedded in every marketing email ever sent to that customer has to keep working,
  not just the first one clicked. A stored token would need either one fixed, never-rotated row
  per customer+channel (defeating the point of "random per send" tokens, and still a table plus
  upsert-before-every-send bookkeeping for zero actual benefit) or a deliberately-reusable row
  (just a stored copy of the same thing a deterministic signature already gives for free, with a
  table and a cleanup story added on top). A stateless signature needs neither: it is
  deterministic (the same customer+channel always yields the same token, so it can be computed
  fresh at send time and verified with zero DB round trip), reusable by construction, and never
  needs a cleanup cron the way a growing token table eventually would.
- **Signed with the installation's own crypt key, not a new admin-configurable secret.**
  `Model\Webhook\WebhookSignatureValidator` and `Model\WhatsApp\WhatsAppSignatureValidator` both
  take their HMAC secret as a caller-supplied string, because those secrets are shared with an
  external party (an ERP/CRM, Meta) and therefore need to be admin-visible/settable in
  `Helper\Config`. This token's secret never leaves this server, so there is no reason to expose a
  new settings field for an admin to discover and configure - using `EncryptorInterface::hash()`
  means one-click unsubscribe works immediately on a fresh install with zero configuration, which
  matters for a mechanism that exists to satisfy a compliance requirement that should hold by
  default. The accepted trade-off: rotating the crypt key invalidates every outstanding
  unsubscribe link signed under the old key (the same kind of edge case every signature-based
  token in this module already accepts, and rarer than a webhook secret rotation).
- **No rate limiting**, unlike `Model\Approval\ApprovalRateLimiter` guarding the order-approval
  endpoints. Approving/rejecting someone else's order is a financially consequential action worth
  throttling blind guesses against; successfully unsubscribing a customer a little early via a
  forged token is not a comparable security incident. More importantly, the token itself isn't
  guessable in a meaningful window: it's a 256-bit HMAC-SHA256 signature over a small, fixed
  input space, not a predictable value derived from the customer id alone - brute-forcing it is
  infeasible regardless of throttling, so a rate limiter here would only protect against a threat
  that doesn't exist.
- **One controller action, two HTTP verbs.** `Controller\Email\Unsubscribe` implements both
  `HttpGetActionInterface` and `HttpPostActionInterface` on the same `execute()` - a human clicking
  the footer link sends a plain GET, while RFC 8058's `List-Unsubscribe-Post` has a supporting mail
  client POST a fixed body (`List-Unsubscribe=One-Click`) to the same URL. Unlike
  `Controller\Approval\{Approve,Reject}` (two actions because they do different things), this one
  does the identical thing regardless of which verb reached it, so a single action is correct, not
  a shortcut. It also implements `CsrfAwareActionInterface` (opting out of form-key validation),
  the same pattern every other public, no-login POST endpoint in this module already uses
  (`Controller\Webhook\Receive`, `Controller\Track\*`, `Controller\Sms\Reply`, etc.) - a mail
  client's one-click POST has no session or form key to present.
- **Renders a plain confirmation page in place, not a redirect.** `Controller\Approval`'s own
  `redirectHome()` bounces to the storefront home page with a flash message - reusable here, but
  deliberately not reused: the whole point of this endpoint is that it works for a logged-out
  recipient with no account/session context, and a dedicated "Email Preferences" page
  (`view/frontend/layout/ordo_email_unsubscribe.xml`, explicitly NOT extending the
  `customer_account` layout handle `ordo_consent_index.xml`/`ordo_offer_index.xml` both do) says
  what happened without bouncing through an unrelated page first.
- **Scoped to `send_email` only, not every email this module sends.** The roadmap item named
  `Model\Campaign\Action\SendEmail` specifically, and this stays there: `Model\Email\
  UnsubscribeContext` (the shared "what does an unsubscribe link need" builder, same pattern
  `Model\SalesRepEmailContext` already established for a signature block shared across every
  automated email) is injected into `SendEmail`'s own template vars and
  `Model\Email\PendingUnsubscribeHeaderHolder` (consumed by the new
  `Plugin\Email\EmailMessageUnsubscribeHeaderPlugin`, the same request-scoped handoff shape
  `PendingMessageIdHolder`/`EmailMessageMessageIdPlugin` already use to reach the message
  `TransportBuilder::getTransport()` builds internally - there's no other public seam to set a
  header on it). The reorder/abandoned-cart/credit-limit/offer-expiry/win-back reminder crons and
  the sales-rep digest are deliberately NOT touched by this change - those are their own
  cron-driven sends outside the campaign engine, each already gated by `ConsentManager::
  hasConsent()`/`hasTopicConsent()` before sending anything, and adding one-click unsubscribe to
  them is a separate, equally mechanical follow-up if ever prioritized, not a reason to widen this
  one's scope now.
- **The visible footer link is only added to `campaign_generic.html`.** `send_email`'s `template`
  field can reference any Magento-registered template, including core ones and admin customizations
  this module doesn't own - `unsubscribe_url` is unconditionally available as a template var for
  every send regardless of which template is chosen (so an admin can add `{{var unsubscribe_url}}`
  to any custom template), but this module only edits the one template it ships for this action.
  The `List-Unsubscribe`/`List-Unsubscribe-Post` headers apply to every `send_email` send
  regardless of template, since those come from the plugin, not the template body - the visible
  footer link is a courtesy on top for templates that choose to render it.

## Consequences

- Every `send_email` campaign send now carries a working, no-login unsubscribe link (both as a
  `List-Unsubscribe`/`List-Unsubscribe-Post` header pair and, for `campaign_generic.html`, a
  visible footer link), closing the specific gap Gmail/Yahoo's bulk-sender rules require.
- The token is stateless and needs no new table, cleanup cron, or admin-configured secret - but it
  is also not rotatable except by rotating the installation's crypt key (which invalidates every
  other signature this module and Magento core itself already derive from that key, not just this
  one), and not individually revocable the way a stored, deletable token would be. Accepted: this
  module has no existing crypt-key-rotation story for any of its other signed tokens either, and a
  token someone else can act on being "revoked" only matters if leaking it is itself a problem,
  which an unsubscribe action deliberately is not (see the no-rate-limiting rationale above).
- This explicitly does not extend one-click unsubscribe to the reminder-cron and sales-rep-digest
  sends this module makes outside the campaign engine - verified against those crons directly
  (each already consent-gated, none sets these headers), not assumed from `send_email`'s own fix.
