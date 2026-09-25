# Roadmap

What's still open — for shipped/stable features see [README.md](README.md), for implementation history/verification
detail see [CHANGELOG.md](docs/CHANGELOG.md) and [VERIFICATION.md](VERIFICATION.md), for the REST API reference see
[API.md](API.md), and the `Test/*/README.md` files.

Ownership split: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is
scoped from real hands-on marketing automation experience.

## Test coverage

- Ad-audience sync (`Cron\SyncAdAudiences`) has no test against a real Google Ads/Meta account — request/response
  logic is unit-tested via a fake `Curl`, but OAuth token exchange and Custom Audience creation/replace have never
  hit the real API.
- `send_whatsapp`/WhatsApp templates have no test against a real Meta WhatsApp Business Account — same shape,
  template submission/approval polling/send have never hit a live WABA.

### MFTF/scenario coverage

Full inventory: `Test/Mftf/SCENARIOS.md`. Open rows: `AutoPickCampaignSplitWinner` (§28) and
`SendAbandonedCartFallbackReminders` (§11), unit-tested only, no MFTF/Integration yet.

## Admin UX

- Add `exportButton` to `ordo_adminactionlog_listing.xml`, `ordo_cronrunlog_listing.xml`,
  `ordo_orderapproval_listing.xml`, `ordo_productfeedrunlog_listing.xml`.
- Add responsive breakpoints to `campaign-form.css`, `segment-form.css`, `whatsapp-template-form.css`,
  `free-gift-offer-form.css` — check at tablet width first, they already lean on `flex-wrap` throughout.

## Documentation

- GitHub Wiki (`github.com/michalper/ordo/wiki`, source staged in `docs/wiki/`) — needs native PL review.

## Candidate new features

Not prioritized against each other; listed for later scoping with the business/product side.

- **Campaign template library** — curated gallery of ready-to-use campaign starting points (welcome series,
  cart abandonment, win-back, VIP upsell), picked and adapted instead of built from scratch.
  `Model\Campaign\CampaignImporter` already accepts the needed JSON shape. Medium scope, B2C/B2B.
- **Email template drafts** — version history is done (`Model\EmailTemplateVersion`, snapshot-on-save +
  restore). What's left: editing a template's content as a separate draft without touching the live
  version `send_email` already reads, then publishing it when ready. Medium scope.
- **Cross-campaign revenue/LTV comparison view** — ranks/compares campaigns against each other, or does
  cohort/LTV analysis over time, on top of the existing per-campaign funnel view. Medium scope.
- **Per-topic preference granularity within a channel** — split a channel's opt-in further (e.g. "promotional"
  vs "product update" email) beyond today's per-channel consent. Confirm real merchant demand first. Medium
  scope.
