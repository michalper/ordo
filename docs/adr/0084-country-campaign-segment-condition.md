# ADR 0084: Country campaign/segment condition

## Status

Accepted.

## Context

A full audit of the 22 registered `Api\Campaign\ConditionInterface` types found zero that match
on a customer's own country/region - a grep for `country`/`geo` across `Model/Segment/` and
`Model/Campaign/Condition/` found nothing. A store running campaigns or segments across more
than one country/region had no way to target or exclude by it at all, even though every other
common axis (recency, monetary, loyalty tier, churn risk, tags, purchase history) already has a
condition class.

## Decision

- **Data source: `customer_grid_flat.billing_country_id`, not a hand-rolled EAV lookup.**
  `customer_entity` has no flat `country` column - a customer's country lives on their default
  billing address (`customer_address_entity`, linked via the `default_billing` EAV attribute).
  Resolving that by hand (load the customer, read the attribute, load the address) is exactly
  the kind of multi-step EAV walk Magento's own admin customer grid already materializes into one
  flat, indexed column for its own search/filter UI. Reusing it means this condition needs no new
  table, no new indexer, and stays current via the same `customer_grid` reindex every other
  admin-grid-backed Ordo lookup already depends on - not a gap specific to this condition.
- **Params: a comma-separated string (`"US,PL,DE"`), not a real multi-value picker widget.**
  `campaign-flow-editor.js` has exactly one multi-value field type today (`tag_multiselect` - a
  free-text chip editor backed by a live tag-autocomplete endpoint), and no plain "pick many from
  a fixed list" widget at all. Building one (a `<select multiple>` or a chip editor fed a static
  country list instead of a live search) is real, justifiable infrastructure, but is considerably
  more code - new JS field-type branches in two places, its own enhancement pass, its own tests -
  for what a comma-separated free-text field already does correctly. `Action\SendWhatsApp`'s own
  `params` field already establishes this exact "comma-separated list, no widget" shape for a
  list-valued condition/action param; `CountryIn` follows it rather than introducing a second
  convention. An admin typing 2-letter ISO codes is no heavier than typing a SKU or category ID,
  which every other free-text condition param in this pool already asks for.
- **Case/whitespace-insensitive matching.** `US, pl , de` and `US,PL,DE` resolve identically -
  admins will type however they type, and there is no cost to normalizing both sides.

## Consequences

- A store can now target or exclude a campaign/segment by customer country with one new
  `country_in` condition type - no new table, no new indexer, no JS canvas changes.
- Matching depends on the customer's own `customer_grid` reindex being current, same as every
  other admin-grid-backed lookup in this codebase (on "Update on Save" mode, the default, this is
  immediate; on "Update by Schedule" it lags behind the configured cron). Not a gap specific to
  this condition - it inherits whatever staleness tolerance a store already accepts for its own
  admin customer grid.
- No real multi-value picker widget exists in the campaign canvas yet. If a future condition
  genuinely needs picking from a large, closed, non-tag-like list (unlike `CountryIn`'s small,
  well-known set), that's the point to build one - not retrofitted here speculatively.
