# ADR 0020: Campaign template library

## Status

Accepted.

## Context

ROADMAP.md asked for a curated gallery of ready-to-use campaign starting points (welcome series,
cart abandonment, win-back, VIP upsell), picked and adapted instead of built from scratch.

Two existing mechanisms were close but didn't match: `campaign-flow-editor.js`'s `FLOW_TEMPLATES`
give trigger→action node skeletons with no field values at all, client-side only; and
`Model\Campaign\CampaignImporter` already accepts exactly the shape needed (full
trigger/condition/action rows with real params) but is only reachable via a `.json` file upload
an admin already has.

## Decision

**Win-back is not included** — it's not buildable with this engine's current primitives, not a
scope cut for convenience. `recurring_schedule`/`scheduled_at` triggers fire once per *campaign*
on a schedule, with context `{trigger_event, now}` only — no `customer_id`
(`Model\Campaign\ScheduledTriggerScanner::fire()`). Every per-customer condition
(`loyalty_tier_at_least`, `reorder_cycle_at_risk`, ...) and `send_email` itself need a
`customer_id` in context to do anything, so a campaign built on a periodic scan trigger would
import cleanly, look fully configured, and silently never satisfy a single condition or send a
single email. The module's actual win-back capability already exists and works —
`Cron\SendWinBackEmails`, its own dedicated cron/config — it simply isn't built on the generic
Campaign engine, so there's nothing for a *campaign* template to wrap. Building a working win-back
template would need a new per-customer recency-based trigger primitive in the engine itself; that's
real engine work, logged as its own gap rather than quietly dropped.

Three templates ship instead, each checked trigger/condition/action type and email template id
against what's actually registered (`etc/di.xml`'s `ConditionPool`/`ActionPool`,
`Api\Data\CampaignTriggerInterface`'s real constants, `etc/email_templates.xml`) — see
`Model\Campaign\TemplateLibrary`'s own docblock for the full list and reasoning per template.

**Every imported template starts `enabled: false`.** Two things are left genuinely
merchant-specific and can't be safely pre-filled: `generate_coupon`'s `rule_id` (no cart price
rule ships with this module to guess — a bad `rule_id` is caught by `GenerateCoupon` and only
logged, so the campaign would otherwise silently send with no coupon, an already-tracked separate
ROADMAP risk) and the one `send_email` action per template using the generic
`ordo_campaign_generic` template rather than a dedicated one, leaving subject/body to the
merchant. Importing disabled means the admin reviews and fills these in before anything can send,
rather than a half-configured campaign going live immediately.

**Cart Abandonment Follow-up deliberately does not reuse `ordo_abandoned_cart_reminder`.**
`Cron\SendAbandonedCartReminders` already sends that exact template on its own fixed schedule for
every abandoned cart with a known customer, and its own docblock explicitly invites "a store can
layer a coupon or a tag onto cart recovery via a campaign without touching this cron" — the
`cart_abandoned` trigger exists on the generic engine specifically for *additive* campaigns, not
duplicate ones. This template follows that invitation: `generate_coupon` then a `send_email` using
the generic template, delayed 60 minutes so it trails the dedicated cron's own immediate reminder
instead of arriving at the same moment as a second, redundant-feeling touch.

**`Model\Campaign\TemplateLibrary` is a plain PHP class**, not JSON files shipped alongside the
module, following this module's existing option-source-class convention (`TypeLabels`,
`ConditionType`) rather than inventing a new file-based content mechanism for three entries. Each
template's `name`/`description` use the same literal-`__('...')`-per-string pattern
`Model\Campaign\TypeLabels` was just rebuilt around (ADR 0019), for the same
`phpcs`/`i18n:collect-phrases` reasons established there.

**New entry points mirror the existing `Import`/`ImportForm` split exactly**:
`Controller/Adminhtml/Campaign/Library.php` (GET, renders the gallery) and
`ImportFromLibrary.php` (POST, looks up a `template_id` and hands its payload straight to the same
`CampaignImporter::import()` the file-upload path already uses — no new import logic). A new
"Browse Template Library" button sits alongside the existing `add`/`import` buttons on the
campaign grid.

**Found and fixed a real, pre-existing bug this way, not just in this feature's own new code**:
the gallery page rendered its title but no content at all on first real-browser check. Root cause:
the new layout file (named `ordo_campaign_library_index.xml`, copying the one existing precedent
for a non-`index`-named action page) didn't match Magento's real handle-generation rule
(`<frontname>_<controller>_<action>.xml`, no `_index` suffix — confirmed against
`ordo_campaign_edit.xml`/`ordo_campaign_calendar.xml`, every other such file in this module). The
copied precedent, `ordo_campaign_importform_index.xml`, turned out to have the exact same mistake
— meaning the existing "Import Campaign" page's own upload form has never actually rendered
through the real admin UI, in production, since it shipped. `ordo_segment_importform_index.xml`
had it too. All three renamed to the correct handle (`ordo_campaign_library.xml`,
`ordo_campaign_importform.xml`, `ordo_segment_importform.xml`); confirmed all three now render
their content in a real browser.
