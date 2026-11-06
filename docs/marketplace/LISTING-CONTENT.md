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

## Website / Support URL

```
https://ordo.marketing
```

Also the listing's documentation link target where the form asks for one separately — the live marketing
site (see `docs.html` there) links back out to the GitHub README/wiki for the full technical docs.

## Short description

```
Campaign automation for Magento Open Source: abandoned cart, reorder reminders, credit limit alerts, and
order approval — no Adobe Commerce B2B license or per-contact subscription required.
```

## Long description

```html
<p>Ordo adds a full marketing-automation campaign engine to Magento Open Source, so merchants can send
abandoned-cart emails, reorder reminders, credit-limit alerts, and other triggered messages without buying
an Adobe Commerce B2B license or paying a third-party platform per contact. Each campaign is built from a
trigger — for example, a cart left idle for two hours, or a customer's order history showing it's time to
reorder — optional conditions such as a minimum order total or a customer tag, and one or more actions:
send an email, SMS, WhatsApp message, or push notification; add a tag; generate a coupon. Campaigns are
assembled visually on a drag-and-drop canvas directly in the Magento admin, so a merchant can see the whole
flow — trigger, conditions, actions — on one screen and change it without a developer.</p>

<p>Because Ordo reads data Magento already stores — orders, quotes, customers, carts — plus a small
first-party tracking model it adds alongside, it can react to events most external marketing tools never
see at all, such as a B2B customer crossing their credit limit or an order waiting for manager approval.
Installing Ordo adds an "Ordo Automation" section to the admin menu (campaign builder, customer segments, a
customer-360 view, and settings) plus a Stores &gt; Configuration &gt; Ordo Automation page where every
feature has its own on/off toggle and schedule, so a merchant only turns on what they need. No changes are
made to the storefront theme or checkout flow unless a feature that needs one — the hard credit-limit
checkout block, or the on-site behavior-tracking snippet — is explicitly enabled.</p>

<h3>Features</h3>
<ul>
<li>Visual drag-and-drop campaign builder — multiple triggers per campaign, conditions, actions</li>
<li>Abandoned cart recovery</li>
<li>Reorder reminders based on a customer's own purchase history</li>
<li>Win-back / re-engagement emails after a configurable period of inactivity</li>
<li>Welcome email on registration</li>
<li>Offer/quote expiry reminders</li>
<li>Credit limit alerts, with an optional hard checkout block at 100% utilization</li>
<li>Order approval workflow — orders above a per-customer spend limit are held for admin approve/reject,
with escalation for unresolved approvals</li>
<li>Free gifts above a cart threshold, with cascading tiers</li>
<li>SMS recovery via Twilio</li>
<li>WhatsApp messaging via the Meta Cloud API, with its own template approval workflow</li>
<li>Web Push notifications (RFC 8291/8292, no vendor SDK)</li>
<li>Price-drop and back-in-stock alerts</li>
<li>Customer segments, RFM and lead scoring</li>
<li>Customer 360 view — one screen for everything the module knows about a customer</li>
<li>Referral program</li>
<li>Email template version history</li>
<li>On-site behavior tracking via a dependency-free JS snippet</li>
<li>Predictive send-time optimization, based on each customer's own historical open/click behavior</li>
</ul>

<p>Full documentation, including a PDF setup guide, is linked from the listing.</p>
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
