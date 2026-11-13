# ADR 0055: Campaign chain exit condition

## Status

Accepted.

## Context

`CampaignDispatcher::resumeScheduledAction()` resumes a `delay_minutes`-paused action chain
unconditionally once its wait has elapsed — the method's own (now-updated) docblock used to say so
explicitly. For the built-in crons this is harmless (`SendAbandonedCartReminders` re-checks "not
converted to an order" fresh on every cron tick, so there's nothing stale for a resumed chain to
act on), but a custom Flow-canvas campaign built on `cart_abandoned`/`customer_inactive` plus a
multi-step `delay_minutes` drip has no equivalent: a customer who places the order between step 1
and step 2 of a three-step drip still gets steps 2 and 3, the same "goal already met, stop the
sequence" gap established journey-builder tools close with an explicit per-campaign exit condition.

`resumeScheduledAction()` is the single resume entry point for every delayed step in a chain —
confirmed by tracing `scheduleResume()`'s only caller, `runActionsFrom()`, which is itself called
from both `runCampaign()`'s initial synchronous pass and from `resumeScheduledAction()` after a
previous delay. So a single check in `resumeScheduledAction()`, re-run on every resume, covers the
first delayed step of a chain and every later one, without touching `dispatch()`/`runCampaign()`'s
own initial, synchronous entry-condition evaluation (nothing can have "converted" mid-way through a
single synchronous call — no real time passes).

## Decision

- **One plain JSON column (`ordo_campaign.exit_conditions`), not a child-row table and not a role
  column on `ordo_campaign_condition`.** All three were considered:
  - A new column/role on the existing `ordo_campaign_condition` table (distinguishing "entry" vs.
    "exit" rows for the same campaign) reuses the existing per-type dedicated-field admin UI
    (switcherConfig, `ConditionPool::getParamFields()`), but also reuses — and risks reintroducing
    a regression in — `ParamFieldsNormalizer`'s dedicated-field merge, the exact mechanism behind a
    real, previously-fixed bug in this codebase (a dedicated select field's default leaking into
    every row's params regardless of type). It would also need `CampaignSaveProcessor::
    saveChildRows()`'s delete-then-reinsert loop, `CampaignDispatcher::groupByCampaignId()`'s
    batching, and a second dynamicRows section wired into both `ordo_campaign_form.xml` and the
    Flow canvas (`Block\Adminhtml\Campaign\Edit\Flow`) to be usable at all.
  - A dedicated `ordo_campaign_exit_condition` table mirroring `ordo_campaign_condition` verbatim
    has the same admin-UI and `ParamFieldsNormalizer` surface area as the option above, for no
    real benefit — the row shape genuinely is identical, so a second table would only duplicate
    resource model/collection/repository classes, not justify them.
  - **A single nullable `text` column on `ordo_campaign` itself**, storing a JSON array of
    `{type, params}` specs — the same shape `ConditionGroupEvaluator::evaluate()` already consumes
    — read directly off the one campaign row `resumeScheduledAction()` already loads to check
    `enabled` (see below), at zero extra queries. No sort_order, no child rows, no dedicated
    per-type fields, and critically, no interaction at all with `ParamFieldsNormalizer`'s
    dedicated-field merge — a brand new `Model\Campaign\ExitConditionSpecs::decode()`/
    `sanitizeForSave()` pair handles the whole shape on its own, dropping malformed rows rather
    than rejecting a save (same fail-closed philosophy as an unknown condition/action type
    elsewhere in this module).
- **Always AND-joined, not a per-exit-set `all`/`any` toggle.** A campaign's own entry
  `condition_logic` is deliberately not reused for this — exit conditions are a different concept
  ("stop early if this is already true") and a v1 admin surface this small (one JSON textarea, see
  below) has no natural place to put a second logic toggle without implying more polish than the
  feature actually has. Revisit if a real use case needs OR-joined exit conditions.
- **Zero exit conditions configured means "never exits"** — the opposite polarity from
  `conditionsSatisfied()`'s own "zero conditions = always true" for entry conditions. This is the
  one thing that makes the feature backward-compatible by construction: every campaign that
  predates this column has `exit_conditions IS NULL`, decodes to an empty spec list, and
  `resumeScheduledAction()` behaves exactly as before. Implemented as a small, separate
  `exitConditionMet()` method rather than reusing/inverting `conditionsSatisfied()` at the call
  site — the two methods would otherwise need either a confusing boolean-polarity parameter or a
  duplicated short-circuit, and they share the one piece of real logic (`ConditionGroupEvaluator::
  evaluate()`) directly already.
- **`resumeScheduledAction()`'s existing per-campaign lookup memo now caches the loaded `Campaign`
  model, not just an `enabled` bool** (`loadEnabledCampaignForResume()`) — the exit check reads
  `exit_conditions` off that same row, so this feature costs zero additional queries per resumed
  chain, and the existing memoization (one campaign-enabled query per campaign per cron run, not
  per resumed row) is unchanged.
- **Admin UI: a single JSON textarea field (`exit_conditions`) on the classic campaign form**
  (`ordo_campaign_form.xml`'s general fieldset), not a Flow-canvas node and not a dynamicRows
  section. This is a deliberate, honestly-scoped v1 gap (see Consequences) — the Flow canvas
  (`Block\Adminhtml\Campaign\Edit\Flow`) is the only editor most admins actually use for
  conditions/actions today, and it has no concept of a campaign-level (as opposed to per-node)
  property to attach this to without new canvas UI work disproportionate to this task. The
  textarea's `dataScope` is the bare column name (`exit_conditions`), not nested like the
  conditions/actions dynamicRows sections, since `Model\Campaign\DataProvider::getData()` already
  spreads every plain `ordo_campaign` column (including this new one) into the form's data without
  any loader changes needed.

## Consequences

- Every existing campaign is unaffected: `exit_conditions` defaults to `NULL`, decodes to zero
  specs, and `resumeScheduledAction()`'s new check short-circuits to "never exits" before touching
  `ConditionGroupEvaluator` at all.
- **No Flow-canvas UI for this yet** — an admin configuring exit conditions today hand-writes a
  JSON array of `{"type": "...", "params": {...}}` objects into a plain textarea, with no
  type-specific fields, no validation beyond "malformed rows are silently dropped on save", and no
  visual feedback distinguishing a working exit condition from a typo that quietly does nothing.
  Same honestly-documented v1-gap shape as `docs/adr/0044-loyalty-points-program.md`'s own
  "no new admin screen" gap — closing it with real Flow-canvas support (a dedicated node type, or a
  campaign-level properties panel) is follow-up work, not part of this change.
- Exit conditions are always AND-joined; a store wanting "stop if EITHER of two things happened"
  needs a single condition type that itself checks both (same workaround a campaign's own entry
  conditions would need under `any` today if this module didn't already expose `condition_logic`
  for entry — but it does, and exit doesn't, by the decision above).
- This was verified with this repo's own standalone unit-test toolchain only (`Test/Unit/Model/
  CampaignDispatcherTest.php`, `CampaignSaveProcessorTest.php`, `CampaignTest.php`, and a new
  `ExitConditionSpecsTest.php`) — not against a real running Magento instance. No Integration/MFTF
  coverage was added in this change; real end-to-end verification (a Flow-canvas campaign with a
  multi-step delay chain, an exit condition, and a real conversion between two delayed steps) is
  left to CI's own Integration Tests job and, ideally, a follow-up MFTF scenario.
