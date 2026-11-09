# ADR 0043: Newsletter signup (email-capture) popup

## Status

Accepted.

## Context

Third candidate from the 2026-11-09 feature brainstorm: a standing, config-driven popup inviting
an anonymous visitor to sign up for the store's newsletter, usually with a first-order discount as
the hook. Unlike the social proof/low-stock widgets (ADR 0039/0042), this isn't a per-SKU theme
integration point - it's a store-wide behavior with no natural "the theme calls this with data it
already has" shape, closer to the existing "Show Popup" campaign action's own text-field authoring
than to `window.ordoRenderSocialProof()`'s pattern. It was initially scoped as "medium effort,
needs a real admin-side popup builder" in the brainstorm itself - that turned out to be avoidable
(see Decision).

## Decision

- **No new admin screen or builder.** The existing "Show Popup" campaign action's own "admin
  authoring" is itself just text fields (`headline`/`body`/`cta_label`/`cta_url` params) - this
  feature gets the same shape at a lower level: five plain `system.xml` fields (enable, delay
  seconds, headline, body, CTA label), the same minimalism `exit_intent_enabled`/
  `social_proof_enabled` already use. This keeps the actual effort low, not medium.
- **A new, standing tracker.js behavior** (`startEmailCaptureTimer()`/`renderEmailCapture()`),
  wired directly off the script tag's own `data-email-capture-*` attributes rather than exposed as
  a `window.ordoRender*()` function - there's no per-instance data for a theme to supply, so unlike
  the social proof/low-stock widgets this needs zero theme changes to work out of the box. Shown
  after a configurable delay (default 8s) to a visitor who hasn't already dismissed or submitted
  it (`ordo_email_capture_dismissed`, a 365-day cookie, checked both before starting the timer and
  again right before rendering).
- **Reuses Magento's own real newsletter subscription** (`Magento\Newsletter\Model\Subscriber::
  subscribe()`) rather than inventing a new one - a submitted email respects whatever confirmation-
  email/double-opt-in config the store's own newsletter already has, exactly like its native
  storefront signup form would.
- **Tags the visitor via the existing `VisitorTagManager`** (`newsletter_signup`), not a new
  trigger type. `VisitorTagManager::addTag()` already fires `ordo_visitor_tag_added`, which
  `Observer\DispatchVisitorTagAddedCampaigns` already dispatches as a `visitor_tag_added` campaign
  trigger - a store builds "what happens after someone signs up" (welcome email, a discount tag,
  …) entirely with the engine's existing primitives. Zero new campaign-engine plumbing.
- **New module dependencies declared explicitly**: `magento/module-newsletter` (the subscription
  API this feature is built on) and, retroactively, `magento/module-catalog-inventory` (used by
  ADR 0042's `ProductStockChecker` but never added to `composer.json`'s `require`/
  `etc/module.xml`'s `<sequence>` when that shipped - caught while adding this feature's own new
  dependency and fixed in the same pass, since real CI already proved both resolve fine
  transitively, but an explicit, accurate dependency list is worth having regardless of luck).
- **No server-side "don't show to a logged-in customer" check.** `TrackerViewModel`'s block
  (`ordo.tracker` in `default.xml`) has no `cacheable="false"` - rendering per-visitor login state
  into a cacheable block would serve one visitor's login state to the next visitor on a cache hit,
  a real correctness bug. Reliably detecting login state client-side without an extra request
  would need this dependency-free script to either make one, or parse Magento's own `customer-data`
  section storage - both a materially bigger addition than this feature otherwise needs. Honestly
  accepted as a known simplification rather than silently assumed away: a logged-in customer may
  still see the popup on a page they haven't dismissed it on before.

## Consequences

- A store gets a working newsletter-signup popup with five config fields and zero theme changes -
  the lowest-friction of the three widgets shipped from this brainstorm batch.
- The popup may show to an already-logged-in, already-subscribed customer (see the last Decision
  point) - a soft UX rough edge, not a correctness bug; worth revisiting only if a store reports it
  as a real nuisance.
- `magento/module-newsletter`/`magento/module-catalog-inventory` are now explicit, accurate
  dependencies rather than implicit, transitively-resolved ones.
