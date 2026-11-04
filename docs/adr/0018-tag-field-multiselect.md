# ADR 0018: Tag field becomes a multi-select chip editor with ANY/ALL match mode

## Status

Accepted.

## Context

ROADMAP.md flagged the `tag` field (`Has Tag`/`Visitor Has Tag` conditions, `add_tag` action) as
plain free text with no autocomplete — easy to typo a tag that silently never matches anything.
Asked to choose between autocomplete-only and full multiselect, the user explicitly chose the
widest option: multiselect with a per-condition "has ANY of these tags" / "has ALL of these tags"
toggle, not just single-value autocomplete.

A parallel survey found 5 more fields with the same shape (free text referencing real existing
data). Not part of this change — logged to `ROADMAP.md` as separate candidate items.

## Decision

**Clean params-shape break, no migration shim**: `{"tag": "vip"}` → `{"tags": ["vip","loyal"],
"match_mode": "any"|"all"}` for the two conditions; `{"tags": [...]}` (no match_mode) for
`add_tag`. Acceptable per this project's own standing position (pre-release, tiny install base,
low switching cost — see memory). An old `{"tag": ...}` saved row just evaluates with an empty
`tags` array (no match) until re-saved — same fail-closed philosophy this module already uses
everywhere for bad/missing condition params. The form field itself was renamed `tag` → `tags` too
(not just the params key), for symmetry with the `variants`/`variant_list` precedent.

**`HasTag` migrated onto `BulkResolvableConditionInterface`** (mirrors `ClvAtLeast`), and
`SegmentMemberResolver`'s hardcoded `case 'tag':` arm + its private `resolveTag()` were deleted —
nothing else depended on `'tag'` having its own arm, so this is a clean move to the general
mechanism (same migration `MessageEngaged` already went through). `VisitorHasTag` stays outside
this — an anonymous visitor has no customer_id, so it can never resolve at the segment/set level;
its existing `case 'visitor_tag': return [];` arm is untouched.

**Multi-value posting mechanism copies `CampaignSaveProcessor`'s existing `variants` pattern
exactly**: one hidden `data-field="tags"` input, kept in sync as `JSON.stringify(tags)` on every
chip add/remove; both save processors' `JSON_DEDICATED_PARAM_FIELDS` list `json_decode()` it back
into an array server-side. `SegmentSaveProcessor` had no such const before this change — added,
mirroring `CampaignSaveProcessor`'s.

**New shared tag-search endpoint, not duplicated per-page** — a deliberate deviation from the
`ProductSearch`-per-feature precedent, which splits only to avoid cross-feature ACL coupling. Tags
aren't owned by either feature, and gating one shared endpoint behind `Ordo_Automation::segments`
or `::campaigns` risks an admin role with one but not the other getting a silent 403 on whichever
page it lacks. `Controller/Adminhtml/Tag/Search.php`'s `ADMIN_RESOURCE` is the bare
`Magento_Backend::admin` login check instead. **Confirmed the hard way that Magento admin routing
needs exactly `<route>/<folder>/<action>`** — an initial attempt at a folder-less
`Controller/Adminhtml/TagSearch.php` 404'd; moving it to `Controller/Adminhtml/Tag/Search.php`
(URL `ordo/tag/search`) fixed it.

**Shared vanilla-JS chip editor (`tag-chip-editor.js`), mounted two different ways** rather than a
Magento_Ui component: `campaign-flow-editor.js`'s own `tag_multiselect` field type (same
two-pass placeholder/enhance pattern as its existing `variant_list`), and
`segment-tag-multiselect.js` (a thin per-page wrapper using a MutationObserver, not the delegated
keystroke-driven `sku-suggest.js` attach() — a chip widget needs mounting once a row exists, not
reactively on the user's first keystroke). Deliberately not a true Magento_Ui/Knockout component —
this codebase already has two precedents for "plain input enhanced by jQuery after render"
(`segment-sku-autocomplete.js`, the `event_only_warning` tooltip work), considerably less code for
the one page (the segment form) that needs it than a real component class would be.

## Bugs found and fixed while building this (all confirmed against real data, not just reasoning)

1. **`'DISTINCT tag'` / `'DISTINCT t.customer_id'` as a bare column string** in `from()` gets
   backtick-quoted as a literal identifier instead of being parsed as SQL — a real "column not
   found" error against MySQL, not just a lint nitpick. Fixed by calling `Select::distinct()` as
   its own statement (`CustomerTagManager::searchTags()`, `getCustomerIdsWithAnyTag()`).
2. **`Select::having()` isn't overridden by Magento's `Select` to return `self`** (unlike
   `where()`/`group()`/`join()`, which are) — chaining it narrows the variable's static type to
   the parent `Zend_Db_Select`, which then fails `fetchCol()`'s `Select|string` parameter type.
   Fixed the same way: called as its own statement, not chained
   (`CustomerTagManager::getCustomerIdsWithAllTags()`).
3. **`[data-index="tags"]`'s `<td>` had no flex-basis in `segment-form.css`** — exactly the same
   squeeze bug this file's own comment already documents for `sku`/`category_id` (reported
   directly against a real screenshot: the chip editor rendered in a sliver a few pixels wide,
   overlapped by the MATCH select next to it). Fixed by adding `tags` to the flex-basis rules,
   with more room (`flex: 2 1 260px`) than a plain short input needs.
4. **Saved tags didn't round-trip back into the chip editor on edit.** Both `DataProvider`
   classes' leaf-row builders do `$rowData += $decoded` — correct for scalar dedicated fields, but
   for `tags` this puts a real PHP array where the dynamicRows field (a plain text-valued
   Knockout observable) needs a JSON-encoded string, confirmed directly: reopening a saved tag
   condition showed the chip editor completely empty despite the DB holding `{"tags":["vip"]}`
   correctly. Fixed with a `restringifyJsonDedicatedFields()` helper on both `DataProvider`
   classes, re-encoding `tags` (and, while fixing the identical latent bug there,
   `variants` on the campaign side too — unreachable through the Flow canvas, which has its own
   separate read path via `Flow::getFlowDataJson()`, but real for the collapsible "Campaign
   Information" section underneath it).

## Known, accepted gap (not fixed here)

Every select-type dedicated field across both save processors' merge loops (`tier`, `event_type`,
`engagement_type`, and now this change's own `match_mode`) leaks its current default-selected
value into every row's saved `params`, regardless of whether that row's condition/action type
actually uses that field — confirmed pre-existing (the merge loop reads `$row[$field]` for every
dedicated field name on every row, visible or not), not introduced by this change, and harmless to
correctness (each condition/action class only reads the keys it cares about). Fixing it properly
means restructuring the merge loop to only merge fields relevant to the row's own type, a separate
and more invasive change — logged to `ROADMAP.md`, not fixed here.

## Consequences

- Typo-prone free-text tag entry is gone from all three tag-adjacent fields, replaced by
  autocomplete-suggested chips that still allow a brand-new tag.
- A marketer can now express "any of these tags" vs. "all of these tags" directly, previously
  only expressible (clumsily) as multiple separate `Has Tag` conditions ANDed/ORed together.
- Two real, confirmed-against-real-MySQL `Select` API bugs were caught and fixed before shipping,
  not left for a future bug report.
