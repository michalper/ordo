# ADR 0067: `RendersGridPageTrait` for the read-only admin grid/report landing controllers

## Status

Accepted.

## Context

A `/simplify` review found that every read-only admin landing controller in this module —
`Controller/Adminhtml/*/Index.php`, 32 of them (grids, reports, the dashboard, the setup guide) —
had the exact same `execute()`: build a page via `resultPageFactory->create()`, call
`setActiveMenu()`, call `getConfig()->getTitle()->prepend()`, return the page. Only the two string
arguments differed.

These 32 controllers don't share a single parent: most extend `Magento\Backend\App\Action`
directly, but nine extend a per-feature `Abstract*Action` base (`AbstractCampaignAction`,
`AbstractSegmentAction`, ...) that their own `Save`/`Delete`/`NewAction` siblings also extend, to
share `ADMIN_RESOURCE` and other setup across that feature's full CRUD set. An abstract base class
for the shared `execute()` would force each of those nine to give up its existing parent, or force
`Abstract*Action` itself to carry grid-rendering behavior its `Save`/`Delete` siblings don't want.

Two of the 32 (`Customer360\Index`, `Gdpr\Index`) aren't plain grids — they resolve a customer by
`?email=` query param and register it before rendering, with the two controllers' email-lookup
code also byte-for-byte duplicated (differing only in the registry key prefix).

Separately, every one of these 32 controllers had two `phpstan-baseline.neon` suppressions: a
`method.notFound` for `setActiveMenu()` (declared on `Magento\Backend\Model\View\Result\Page`, not
on the plain `Magento\Framework\View\Result\Page` that `PageFactory::create()`'s return type
declares) repeated once per file.

## Decision

Extract `Controller/Adminhtml/RendersGridPageTrait.php` as a **trait**, not an abstract base class,
specifically so it composes into any of the ten existing parent hierarchies unchanged. Each using
class implements two abstract hook methods (`activeMenu(): string`, `pageTitle(): Phrase`) and
keeps declaring its own `resultPageFactory` constructor property, since a trait can't declare a
constructor. A third hook, `beforeRender(): void`, no-ops by default and is overridden only by
`Customer360\Index`/`Gdpr\Index` to do their pre-render lookup.

Extract `Controller/Adminhtml/CustomerLookupByEmailRegistrar.php` as a small service (not part of
the trait, since only two of the 32 need it) encapsulating the shared "resolve by email, register
id/email under a given registry key prefix" logic those two controllers had duplicated.

Fix the `setActiveMenu()` PHPStan gap once, in the trait, via an inline `/** @var Page $resultPage
*/` doc-comment narrowing `PageFactory::create()`'s return type to the concrete Backend `Page`
class that's always the real runtime type for an admin controller — rather than suppressing it in
each of 32 files.

`Customer360\AbstractCustomer360Action` is deleted — it existed only to let `Customer360\Index`
share `ADMIN_RESOURCE`-setup with... itself (it had no other subclass), and the trait doesn't need
it.

## Consequences

- All 32 controllers' `execute()` methods collapse to two one-line hook methods; the
  `setActiveMenu()`/`prepend()` call shape now exists in exactly one place.
- 32 now-unmatched `method.notFound` entries removed from `phpstan-baseline.neon` (the
  `Title::prepend()` "expects string, Phrase given" suppressions per file are unrelated — that gap
  comes from a stale `@param string` docblock on Magento core's `Title::prepend()` itself, present
  regardless of this refactor, so those entries stay).
- `Customer360\Index`/`Gdpr\Index` keep their existing `extends AbstractCustomer360Action` /
  `extends AbstractGdprAction` (the latter still shared by `Export`/`Erase`/`SetConsent`/
  `SetTopicConsent`) and additionally `use RendersGridPageTrait;` — no conflict, since a trait
  doesn't touch the class's parent.
- Every one of the 30 plain-grid controllers' existing unit tests passed unmodified; only
  `Customer360\IndexTest`/`Gdpr\IndexTest` needed updating, for the narrower constructor signature
  (`CustomerLookupByEmailRegistrar` replacing their own `CustomerRepositoryInterface`/`Registry`
  pair).
