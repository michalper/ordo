# ADR 0082: Order thank-you page cross-sell widget

## Status

Accepted.

## Context

`ROADMAP.md`'s "Candidate new features" list flagged a real gap: `Model\Campaign\Action\
AddProductRecommendations` only renders a recommendation block inside a `send_email` action - a
grep of `view/frontend` found no thank-you/order-success integration point at all, and no
`window.ordo*` function targets that page the way `ordoRenderSocialProof`/`ordoRenderLowStock`
target the PDP.

## Decision

- **A new theme-integration-point JS function**, `window.ordoRenderCrossSell(orderId,
  containerSelector)`, same shape as the existing PDP widgets - the theme calls it on its own
  thank-you template with the order id it already has (`Magento\Checkout\Block\Onepage\Success::
  getOrderId()`, the entity id) and a container it has placed in its own markup. No new layout
  XML, no new frontend Block/ViewModel/template - this module still injects nothing into
  `checkout_onepage_success.xml`.
- **The server returns ready-to-insert HTML, not a raw product-data JSON array.**
  `Controller\Track\CrossSell` reuses `Model\Recommendation\ProductRecommender::
  getRecommendedSkus()` (the same co-purchase-affinity ranking `Model\Campaign\Action\
  AddProductRecommendations` and `Model\ContentBlock\Producer\ProductFeedProducer` already use)
  and `Model\Recommendation\ProductRecommendationRenderer::renderHtml()` (the same email-grade
  grid markup those two already render), then hands the resulting HTML string straight to
  `tracker.js`, which sets it as the container's `innerHTML`. This needed zero new client-side
  product-rendering logic and zero new recommendation logic - the entire feature is "wire two
  existing services together behind a new controller and a new JS function," matching every
  other widget this file exposes (`renderSocialProof`/`renderLowStock` also just drop a
  server-formatted value straight into the DOM).
- **Order ownership is enforced server-side via `Magento\Checkout\Model\Session::
  getLastRealOrder()->getIncrementId()`, not trusted from the request.** `order_id` is a plain
  integer on a public, unauthenticated GET endpoint - without this check, anyone could enumerate
  order ids and read back a stranger's own purchase-affinity signal (a low-sensitivity but real
  IDOR-shaped leak). `Controller\Track\CrossSell` loads the order by the posted entity id, then
  refuses to recommend anything unless that order's own increment id matches the CURRENT
  session's own last real order - the exact same ownership boundary Magento's own core `Success`
  block relies on internally, just re-implemented here since this controller receives the id as
  an explicit argument rather than reading the block's order object directly. This is the first
  use of `Magento\Checkout\Model\Session` anywhere in this module - read via `getLastRealOrder()`
  (a real, declared method returning a `Sales\Model\Order`) rather than the lower-level
  `getLastRealOrderId()` it calls internally, which is a magic accessor via
  `Magento\Framework\Session\SessionManager::__call()` that PHPUnit can't stub directly
  (`MockBuilder::addMethods()`, the mechanism for exactly this case, was removed in PHPUnit 10+) -
  same information, trivially mockable, no wrapper class needed.
- **Guest orders fall through to `ProductRecommender`'s existing best-seller fallback.**
  `getRecommendedSkus()` already treats `customerId <= 0` as "no purchase history to rank
  against" and pads straight from store-wide best-sellers - exactly the guest-checkout case here,
  no new branch needed.
- **Its own enable toggle plus an item-count field** (`Helper\Config::
  isCrossSellWidgetEnabled()`/`getCrossSellItemCount()`, default 4 - the same default
  `ProductRecommender::getRecommendedSkus()` itself already uses), gated also behind the
  top-level `tracking > enabled` toggle, matching every other tracker.js-driven feature.

## Consequences

- A store can show a real "you might also like" grid on its thank-you page with one call the
  theme adds to its own template - no new admin UI beyond two config fields, no new table, no new
  cron, and the same visual grid a recommendation email already sends.
- A guest checkout with `Magento\Checkout\Model\Session`'s own cookie-based session cleared
  between order placement and the thank-you page load (rare, but possible for a guest with
  cookies blocked) sees no recommendations at all, rather than a stranger's - a deliberate
  fail-closed choice given the alternative is a real, if minor, cross-customer data leak.
- `Model\Recommendation\ProductRecommendationRenderer`'s markup is inline-styled for email
  rendering (table-based, no external CSS) - it renders correctly on a storefront page too, but a
  store wanting it to match its own theme's visual language more closely would need its own
  follow-up styling pass; not attempted here, since matching the email's own established grid was
  the explicit point of reusing it.
