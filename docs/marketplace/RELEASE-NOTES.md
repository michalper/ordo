# Ordo — Marketplace Release Notes

Adobe Commerce Marketplace asks for release notes as plain text per submitted version — merchant-facing
("what this version gives you"), not `docs/CHANGELOG.md`'s internal, PR-by-PR engineering log. Copy the
relevant entry below as-is into the submission form's release notes field; add a new entry above it for
every future version submitted to the Marketplace (newest first), written in the same plain style.

---

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
