# ADR 0019: campaign-flow-editor.js i18n, and translating condition/action type labels

## Status

Accepted.

## Context

ROADMAP.md flagged `campaign-flow-editor.js` as having no i18n support — a deliberately deferred
item from the tag multiselect work (ADR 0018). Wrapping its 33 hardcoded JS strings in `$t()`
(the `mage/translate` module's `$.mage.__()`) and adding the missing `i18n/*.csv` rows was
expected to be the whole fix.

Visually verifying the result against a real Polish admin session (switching Interface Locale to
`pl_PL`, redeploying static content) surfaced a much larger, pre-existing gap: every condition and
action type label shown in the Flow builder's block palette and node type dropdowns — "Has Tag",
"Order Total ≥", "Send Email", "A/B Split Test", etc., 33 labels total, plus the 5 condition-
category group names ("Tags & Behavior", "Score & Engagement", ...) — stayed in English regardless
of locale. `Model\Campaign\TypeLabels` (the single source for all of them) had never passed any of
its strings through `__()` at all; they were plain PHP string constants. This predates
`campaign-flow-editor.js` entirely and was invisible from a code read of that file alone, since its
own docblock (and the ROADMAP item built on that assumption) took for granted that server-rendered
PHP labels already went through translation the normal way.

Two smaller gaps surfaced the same way: `tag-chip-editor.js`'s own "Add a tag…" placeholder and
"Remove tag" aria-label (a file this session had already written earlier for ADR 0018, missed in
that pass since it isn't `campaign-flow-editor.js`), and `TagMatchMode`'s "Has ANY/ALL of these
tags" option labels plus the segment form's "Match" field label — all three already correctly
wrapped in `__()`, just missing their CSV rows.

## Decision

**Fixed all of it in one pass**, not just the originally-scoped file: `campaign-flow-editor.js`'s
33 strings, `tag-chip-editor.js`'s 2 strings, `TypeLabels`'s 33 condition/action labels + 5
category names, and the 3 missing match-mode/"Match" CSV rows — 73 new phrase keys total, each
added to all 11 translated locale CSVs (803 new rows). Scope judgment: this is squarely "make the
Flow builder actually work in Polish", the real intent behind the ROADMAP item, not scope creep —
confirmed directly against a live Polish admin session rather than assumed from a diff.

**`TypeLabels` rebuilt around literal `__('...')` calls, not a dynamic `__($variable)` lookup into
a string constant.** The first attempt — wrapping `self::CONDITION_LABELS[$type] ?? ...` in
`(string) __(...)` — resolves correctly at runtime (Magento's translation dictionary is a plain
string→string lookup regardless of how `__()` was called) but fails
`phpcs`'s `Magento2.Legacy.Translate` sniff ("Constants are not allowed as the first argument of
translation function") and isn't discoverable by `bin/magento i18n:collect-phrases`, a future
contributor's only tool for finding what still needs a CSV row. Because PHP const-array values
can't themselves be `__()`/`Phrase` expressions, the arrays became private methods (`conditionLabels()`,
`actionLabels()`, `conditionCategories()`) each built from one literal `__('...')` call per entry,
assembled fresh per call rather than cached as a class constant — an acceptable cost for a handful
of lookups, not a hot path.

**`conditionCategory()` and `conditionCategoryOrder()` must resolve the same category literals
identically** — `ConditionType::toOptionArray()` uses the first as an array key to group into, then
looks that key up again using the second's return values. Translating one but not the other (or
translating the same English text two different ways) would silently drop a category from the
grouped dropdown. Fixed by using one local variable per category literal
(e.g. `$tagsAndBehavior = (string) __('Tags & Behavior')`) reused across every condition mapped
into it, and the identical literal in `conditionCategoryOrder()`'s own list — both resolve through
Magento's translation dictionary by the same source string, so they agree within a request
regardless of locale.

**`tag-chip-editor.js`'s input (`.ordo-tag-chip-input`) also gained real CSS** (border-radius,
padding, a subtle border) in the same pass — reported directly against a screenshot as looking
like a bare, un-styled browser default next to its sibling fields on the Flow canvas. Unrelated to
translation, but found via the same real-browser verification pass and small enough to fix
alongside it rather than filing separately.

## Consequences

- Any future addition to `TypeLabels`'s label/category methods must use a literal `__('...')`
  call per entry, not a variable or constant lookup — the pattern this ADR establishes, now also
  enforced by `phpcs`.
- `conditionCategories()`/`conditionCategoryOrder()`'s shared-literal-per-variable approach is the
  precedent for any future pair of methods that must resolve translated text to matching keys.
