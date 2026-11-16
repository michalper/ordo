# ADR 0069: `RendersEditPageTrait` for the 8 identical admin edit controllers

## Status

Accepted.

## Context

Following ADR 0067's grid-page trait, a further `/simplify` pass found
`Controller/Adminhtml/{AdAudience,Campaign,ContentBlock,FreeGiftOffer,LeadRoutingRule,ScoreRule,
Segment,WhatsAppTemplate}/Edit.php` byte-for-byte identical in shape: read `entity_id`, build a
new entity via that feature's factory, load it by id if present, redirect with an error message
if the id didn't resolve, register the entity onto the page, build the result page with a
"New X"/"Edit X \"name\"" title. Only the factory/resource types, the registry key, and a handful
of noun-phrase strings differ.

Same constraint as ADR 0067: each of these 8 extends its own feature's `Abstract*Action` (shared
with that feature's `Save`/`Delete`/`NewAction` siblings for `ADMIN_RESOURCE`), so a shared base
class would force giving that up. A trait composes regardless.

## Decision

Extract `Controller/Adminhtml/RendersEditPageTrait.php`, parameterized by five hook methods:
`newEntity()`, `loadEntityById()`, `registryKey()`, and two separate label hooks —
`editEntityLabel()` (title case, e.g. "Ad Audience", feeding "Edit %1 ..."/"New %1") and
`missingEntityLabel()` (lowercase, e.g. "ad audience", feeding "This %1 no longer exists.") —
kept as two hooks rather than one because deriving one casing from the other isn't reliable for a
two-word brand-prefixed label like "WhatsApp Template" / "WhatsApp template" (the brand name keeps
its capital, the common noun doesn't).

Fixes the same `setActiveMenu()` PHPStan gap ADR 0067 fixed for the grid-page trait, once, instead
of once per file.

## Consequences

- All 8 controllers' `execute()` bodies collapse to five one-line hook methods; the load-or-404-
  redirect-register-title shape now exists in exactly one place.
- 8 now-unmatched `method.notFound` baseline entries removed.
- Every one of the 8 existing `EditTest.php` files passed unmodified — the constructor signature
  didn't change, only which class owns the method bodies that used to be inline in `execute()`.
