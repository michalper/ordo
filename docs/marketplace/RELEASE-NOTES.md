# Ordo — Marketplace Release Notes

Adobe Commerce Marketplace asks for release notes as plain text per submitted version — merchant-facing
("what this version gives you"), not `docs/CHANGELOG.md`'s internal, PR-by-PR engineering log. Copy the
relevant entry below as-is into the submission form's release notes field; add a new entry above it for
every future version submitted to the Marketplace (newest first), written in the same plain style.

---

## 1.9.0

This release catches the Marketplace listing up from 1.2.0 to the current 1.9.0, bringing everything
added since the initial release:

New capabilities:
- Loyalty points — customers earn and redeem an ongoing points balance for coupons, separate from the
  existing tier-based loyalty program
- Newsletter signup popup for anonymous visitors, tied into real Magento newsletter subscriptions
- "Only N left in stock" and "N people viewed this today" storefront widgets, drop-in for any theme
- Server-side conversion reporting straight to GA4 and Meta Conversions API, bypassing ad blockers and
  browser privacy settings that often stop the on-page tracking tag from firing
- TikTok ad-audience sync, alongside the existing Google Ads and Meta integrations
- Birthday / registration-anniversary campaign trigger
- GTM dataLayer bridge and an exit-intent campaign trigger
- A full GraphQL storefront API (tracking, popups/notifications/surveys, My Offers, credit limit,
  price-watch and push subscriptions), for headless/PWA Studio storefronts
- Curated campaign template library (Welcome Series, Cart Abandonment Follow-up, VIP Upsell) to start
  from instead of a blank canvas
- Email template drafts, so an admin can edit a template's subject/content and publish only when ready
- Five new analytics reports: revenue/channel attribution, campaign comparison, cohort retention,
  purchase-funnel drop-off, and product performance, plus automated anomaly alerts on revenue/conversion
- Per-topic consent (Promotional vs. Account & Order Updates), so a customer who opts out of promotions
  still gets their credit-limit or order-approval alerts
- Full multi-website support — campaigns, segments, and score rules can each be scoped to specific
  websites on a multi-website install
- Czech (cs_CZ) added as a supported storefront locale

Also hardened several security and data-integrity issues found along the way (webhook signature
verification, ownership checks on subscription endpoints, an SSRF gap in the RSS content-block fetcher,
and transactional saves for campaigns/segments), and closed a large translation gap across the admin
configuration screens.

## Initial release

Ordo is marketing automation built directly into Magento Open Source — no Adobe Commerce B2B license,
no per-contact subscription. It runs a trigger/conditions/actions campaign engine against data Magento
already has, plus a small first-party tracking model, covering both B2C lifecycle automation and B2B
triggers most external marketing-automation tools can't see at all.

B2B features:
- Reorder reminders based on a customer's own purchase history
- Offer/quote expiry reminders
- Credit limit alerts, with an optional hard checkout block at 100% utilization
- Order approval workflow for orders above a per-customer spend limit
- Free gifts above a cart threshold, with cascading tiers

B2C features:
- Abandoned cart recovery
- Welcome email on registration
- Win-back / re-engagement emails
- SMS recovery via Twilio
- WhatsApp messaging via the Meta Cloud API
- Web Push notifications
- Price-drop and back-in-stock alerts

Shared foundation:
- Visual drag-and-drop campaign builder (trigger, conditions, actions)
- Customer segments, RFM and lead scoring
- Customer 360 view
- Referral program
- Email template version history
- On-site behavior tracking

Every feature has its own configuration toggle under Stores > Configuration > Ordo Automation and runs
on its own schedule, so merchants only turn on what they need.

Full documentation: https://github.com/michalper/ordo/wiki
Support: https://github.com/michalper/ordo/issues
