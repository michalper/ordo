# ADR 0072: Shared save-failure logging and headline/body/cta param fields across 4 campaign actions

## Status

Accepted.

## Context

A `/simplify`-style pass over `Model/Campaign/Action/{Notify,ShowPopup,NpsSurvey,GenericSurvey}.php`
found two separate byte-for-byte duplications:

- All four wrap their own entity's `save()` call in an identical `try { ... } catch (Throwable $e)
  { $this->logger->error(sprintf('Ordo_Automation: campaign %s action failed to save: %s',
  self::ACTION_LABEL, $e->getMessage())); }` block - only the entity/resource being saved differed.
- `Notify` and `ShowPopup` had an identical 4-entry `getParamFields()` array (`headline`, `body`,
  `cta_label`, `cta_url`) - both reuse the same dedicated admin fields
  (`ordo_campaign_form.xml`'s `switcherConfig` shows them for both action types). `ShowPopup`
  appends its own `reward_tiers` field after them.

## Decision

Two small traits, composed into the classes that need them (`ActionInterface` implementations
with no shared parent to put this on instead - same reasoning as ADR 0067/0070 for choosing a
trait over a base class):

- `LogsSaveFailureTrait` (all four classes): `saveOrLogFailure(callable $save): void` plus an
  abstract `actionLabel()` hook (each class already had its own `private const string
  ACTION_LABEL`, reused elsewhere in `execute()` for `ContextTargetResolver`, so the hook just
  returns it rather than duplicating the value).
- `HeadlineBodyCtaParamFieldsTrait` (`Notify`, `ShowPopup` only): `headlineBodyCtaParamFields():
  array` returning the shared 4-entry array; `ShowPopup`'s own `getParamFields()` spreads it and
  appends `reward_tiers`.

`LogsSaveFailureTrait` deliberately keeps swallowing the exception (log and return) rather than
letting it escape, even though `CampaignDispatcher::runOneActionOrSplitStoppingChainOnFailure()`
(ADR 0068) now exists and would catch an uncaught throw from `execute()` and stop the rest of this
campaign's action chain. The two failure modes aren't equivalent: a failed save here means only
this one action's own row never got written, with no later action in the chain depending on it the
way, say, a later action might depend on a coupon code an earlier action generated - escalating to
"stop the chain" would be a real behavior change made as a side effect of a dedup, not a dedup
itself, so it's out of scope here and left as this trait's existing, narrower failure mode.

## Consequences

- All four classes' `execute()` bodies drop their own `try`/`catch` for the one line
  `$this->saveOrLogFailure(fn () => ...)`; the now-unused `Throwable` import is removed from
  `Notify`/`NpsSurvey`/`GenericSurvey` (`ShowPopup` keeps it - its own `applyRewardTier()` has an
  unrelated, differently-worded `catch` around coupon generation that isn't part of this dedup).
- `Notify`'s `getParamFields()` collapses to one line; `ShowPopup`'s keeps its own `reward_tiers`
  entry, spreading the shared four instead of repeating them.
- All four existing `Test/Unit/Model/Campaign/Action/*Test.php` files pass unmodified - no
  constructor signature changed, and the save-failure tests assert the logged message contains
  the exception's own text (`self::stringContains(...)`), not its exact wording, so moving the
  `sprintf()` into the trait doesn't break them.
