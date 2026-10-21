# Ordo — Privacy Policy

*Last updated: 2026-10-22*

This page describes how **Ordo** (`Ordo_Automation`, developed by Michał Per) processes personal
data as installed inside a merchant's own Magento Open Source / Adobe Commerce store.

## Who is the data controller

**The merchant who installs Ordo is the data controller** for their own store's customer and
visitor data — Ordo runs entirely inside the merchant's own Magento installation and database;
no customer data is sent to Michał Per or hosted on any infrastructure Michał Per operates. If
you are a merchant using Ordo, you are responsible for your own store's privacy notice to your
customers, and for configuring Ordo's consent settings (see below) in line with your own legal
obligations (e.g. GDPR, if you serve EU customers).

This document describes what Ordo's own code does with data, and which third-party services it
can send data to **when a merchant enables that specific feature** — it is not a substitute for
the merchant's own customer-facing privacy notice.

## What data Ordo processes

Depending on which features a merchant has enabled (every feature in Ordo has its own on/off
toggle under **Stores → Configuration → Ordo Automation**, off by default), Ordo may store:

- Customer identifiers (`customer_id`) and order/purchase history already present in Magento,
  used to compute segments, scores (RFM/CLV/lead score), and trigger campaigns.
- Email address, phone number (for SMS/WhatsApp), and push-notification subscription tokens, to
  deliver the messages a campaign action sends.
- On-site behavior (page/product/category views, cart/wishlist activity) via a first-party
  tracking snippet, tied to an anonymous visitor ID until/unless a visitor logs in (at which
  point it is attributed to their `customer_id`).
- Message delivery/engagement history (sent/delivered/opened/clicked, opt-outs) for the channels
  above.
- Survey responses, loyalty/referral data, and other first-party engagement data a merchant's
  campaigns collect.

Ordo does **not** collect data from a store's checkout/payment flow beyond what Magento's own
order record already contains, and does not process payment card data at all.

## Third-party sub-processors

These services are only contacted if a merchant has configured and enabled the corresponding
feature; each needs its own credentials, which only the merchant controls:

| Service | Used for | Data sent |
|---|---|---|
| [Twilio](https://www.twilio.com/legal/privacy) | SMS delivery | Phone number, message text |
| [Meta (WhatsApp Cloud API)](https://www.whatsapp.com/legal/business-data-processing-terms) | WhatsApp delivery | Phone number, approved template content |
| Merchant's own SMTP/transactional email provider (e.g. SendGrid, if configured) | Email delivery | Email address, message content |
| Google Ads / Meta Ads (Customer Match / Custom Audiences) | Ad-audience sync | SHA-256 **hashed** email address only — never sent in plaintext |

A merchant can scope which of these a given customer may be contacted through on a per-channel
basis — see Consent below.

## Consent

Ordo tracks consent per channel (email, SMS, push, WhatsApp, and a separate "ads" channel for
the ad-audience sync above) via `Model\ConsentManager`. A channel with no explicit consent
record defaults to consented for transactional/marketing use, consistent with Magento's own
default customer communication settings — merchants operating under opt-in regimes (e.g. GDPR
for marketing SMS/WhatsApp) are responsible for configuring and collecting that consent through
their own storefront UI before relying on Ordo to send through those channels.

## Data subject rights (export & erasure)

Ordo ships built-in admin tooling for data-subject access and erasure requests, covering every
table it holds keyed by `customer_id` (consent state and history, tags, scores, notifications,
survey responses, message log, push subscriptions, reorder cycles, offers, alert logs, campaign
attribution, referral/loyalty data, and any visitor browsing history already linked to that
customer):

- **Export**: Stores → *Ordo Automation → GDPR* → search by email → *Export*.
- **Erasure**: same screen → *Erase* — a hard delete of every one of those rows for that
  customer, not an anonymize-in-place, since none of the data is meaningful without the customer
  it belongs to.

A merchant handling a data subject request should use this tooling directly rather than deleting
the customer record alone, since Magento's own customer deletion does not touch Ordo's tables.

## Data retention

- Short-lived operational data (on-site tracking events used to compute tags, delivered
  notifications/popups) is pruned automatically by a dedicated cron job once it is no longer
  needed for its purpose.
- Message/engagement logs, scores, and campaign history are retained until the merchant's own
  data-retention policy says otherwise, or until an erasure request is processed as above.

## Contact

Questions about this policy, or about Ordo's own code (not about a specific merchant's use of
it — contact that merchant's store directly for that): open an issue at
[github.com/michalper/ordo/issues](https://github.com/michalper/ordo/issues), or email
michal.per@outlook.com.
