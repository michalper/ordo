# Ordo — Marketplace Listing Content

Content for the "Marketing Submission" step of the Adobe Commerce Marketplace listing (separate from, and
independent of, the "Technical Submission" that `build-submission-zip.sh`'s zip goes to — see Adobe's own
"How to Submit a New Extension" flow). Copy each field below as-is into the corresponding form field; add a
new entry to the long description's version history and to `RELEASE-NOTES.md` for every future version.

Spec this was written against (`developer.adobe.com/commerce/marketplace/guides/sellers/content`, checked
2026-10-22): title ≤4 words, no "App"/"Extension"/"Module", no version number/developer name/Adobe/Magento
reference; long description ≤25,989 characters, simple HTML allowed; ≥2 screenshots, JPG/PNG, ≤5MB each;
icon content should reflect the listing via words/images, no slogan, no Adobe/Magento name or logo.

---

## Title

```
Ordo Marketing Automation
```

## Category

**Marketing** (closest fit in Adobe's own category list — confirm against whatever the submission form
offers at the time, categories can change independently of this doc).

## Short description

```
Campaign automation for Magento Open Source: abandoned cart, reorder reminders, credit limit alerts, and
order approval — no Adobe Commerce B2B license or per-contact subscription required.
```

## Long description

```html
<p>Ordo is a Klaviyo/HubSpot-style campaign engine — trigger, conditions, actions, drag-and-drop canvas —
that runs directly inside Magento Open Source. No Adobe Commerce B2B license, no per-contact marketing
automation subscription. Triggers are computed from data Magento already has (orders, quotes, customers,
carts) plus a small first-party tracking model shipped with the extension.</p>

<p>Covers both classic B2C lifecycle automation and B2B triggers most external marketing-automation tools
can't see at all, because they never had access to that data in the first place.</p>

<p><strong>B2B features</strong></p>
<ul>
<li>Reorder reminders based on a customer's own purchase history</li>
<li>Offer/quote expiry reminders</li>
<li>Credit limit alerts, with an optional hard checkout block at 100% utilization</li>
<li>Order approval workflow — orders above a per-customer spend limit are held for admin approve/reject,
with escalation for unresolved approvals</li>
<li>Free gifts above a cart threshold, with cascading tiers</li>
</ul>

<p><strong>B2C features</strong></p>
<ul>
<li>Abandoned cart recovery</li>
<li>Welcome email on registration</li>
<li>Win-back / re-engagement emails after a configurable period of inactivity</li>
<li>SMS recovery via Twilio</li>
<li>WhatsApp messaging via the Meta Cloud API, with its own template approval workflow</li>
<li>Web Push notifications (RFC 8291/8292, no vendor SDK)</li>
<li>Price-drop and back-in-stock alerts</li>
</ul>

<p><strong>Shared foundation</strong></p>
<ul>
<li>Visual drag-and-drop campaign builder — multiple triggers per campaign, conditions, actions</li>
<li>Customer segments, RFM and lead scoring</li>
<li>Customer 360 view — one screen for everything the module knows about a customer</li>
<li>Referral program</li>
<li>Email template version history</li>
<li>On-site behavior tracking via a dependency-free JS snippet</li>
<li>Predictive send-time optimization, per customer's own historical open/click behavior</li>
</ul>

<p>Every feature has its own configuration toggle under Stores &gt; Configuration &gt; Ordo Automation and
runs on its own schedule, so merchants only turn on what they need. Full documentation, including a PDF
setup guide, is linked from the listing.</p>
```

## Icon

`docs/marketplace/assets/icon-240.png` (240×240 PNG, Adobe's exact required size for the extension icon
upload — confirmed on the actual submission form, not documented on the public guidelines pages) —
downscaled from `docs/marketplace/assets/icon-512.png`, generated from `docs/marketplace/assets/icon.svg`,
the same mark/gradient as the README hero and the admin menu icon, so the brand reads consistently. No
slogan or feature text, no Adobe/Magento name or logo, per Adobe's icon content rule.

## Screenshots

At least 2 required; both below are ready, the first becomes the listing's thumbnail:

1. `docs/marketplace/screenshots/01-campaign-builder.jpg` — the Flow canvas: an `Order Placed` trigger
   feeding a `Send Email` action into a `Generate Coupon` action, showing the core visual campaign builder.
2. `docs/marketplace/screenshots/02-trigger-condition-action.jpg` — the same canvas with all three node
   types in one chain: `Tag Added` trigger → `Has Tag` condition → `Send Email` action, showing the
   conditions layer the first screenshot doesn't.

Both captured from `magento-ordo-test/`'s admin (`ordo/campaign/new`, a template loaded from "Load a
Template" then one trigger chain removed for a single clean flow, canvas fit-to-screen). Neither was saved
as a real campaign — the sandbox's own campaign grid is untouched.

## Version compatibility

Adobe Commerce Platform Version: **Adobe Commerce 2.x (M2)**. Minor versions: 2.4.8, 2.4.9 (per
`composer.json`'s `require`/this repo's own stated support — confirm against whatever minor-version
checkboxes the submission form offers, since Adobe updates that list independently of this doc).

## Video

None. Optional field (up to 3 YouTube links) — add one later if a product-tour video gets made; not a
blocker for submission.
