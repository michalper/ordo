# ADR 0063: Gamified "spin the wheel" reward popup

## Status

Accepted.

## Context

A fifth-round roadmap brainstorm (2026-11-14) flagged a real gap, confirmed by reading
`Model\Campaign\Action\ShowPopup` directly: every popup a campaign queues today - exit-intent,
email-capture, campaign-triggered - shows the exact same static headline/body/CTA to every
recipient. There's no "spin the wheel"/scratch-card mechanic offering one of several weighted
reward tiers (10% off, free shipping, "try again"), a widely-used email/SMS-capture gamification
pattern on other platforms' own popup builders.

The roadmap bullet pointed at two existing pieces to build on: `ShowPopup`'s own
`ordo_pending_popup` queue-and-poll delivery (unchanged by this feature - see Decision below), and
`Model\CouponGenerator` for minting the winning tier's coupon. It also explicitly flagged a third
piece, `Model\Campaign\SplitVariantSelector`, as something to evaluate rather than blindly reuse,
since its weighting shape ({key/label, weight, ...}) looks superficially identical to a reward
tier's.

## Decision

- **A new reward-tier params shape on the existing `popup` action, not a new action/trigger
  type.** `ShowPopup`'s params gain an optional `reward_tiers` key: a JSON array of
  `{label, weight, coupon_rule_id}` entries. Absent or empty, the action behaves exactly as
  before - a plain static popup, no `reward_label`/`coupon_code` ever set. This keeps the single
  frequency cap, the single queue-and-poll delivery mechanism, and the single admin action type
  the merchant already knows, instead of forking a parallel "wheel" action that would duplicate
  all three.
- **A new `Model\Campaign\RewardTierSelector`, NOT a reuse of `SplitVariantSelector`, because
  their determinism contracts are opposite and the roadmap bullet's own phrasing ("re-roll on a
  new popup delivery, unlike a split test's sticky per-customer assignment") was exactly right.**
  `SplitVariantSelector` is deliberately sticky: the same customer identity always lands in the
  same split variant via a `crc32('campaign:splitAction:identity')` bucket, re-used rather than
  re-rolled across repeat dispatches/resumes of the same trigger (see that class's own docblock) -
  correct for a split *test*, where comparing variant A against variant B requires each customer
  to stay in one arm for the life of the test. A "spin the wheel" popup is the opposite: a visitor
  who triggers the popup action again later (a fresh campaign dispatch, outside `ShowPopup`'s own
  frequency-cap window - see below) must be able to land on a different tier each time, the same
  way a real wheel doesn't remember your last spin. Reusing `SplitVariantSelector` as-is would have
  silently made every visitor's "wheel" always land on the same prize forever after their first
  spin - plausible-looking at a glance, wrong in practice, and exactly the kind of bug this ADR is
  for. `RewardTierSelector::selectTier()` therefore takes no identity/context parameter at all and
  persists no assignment anywhere - every call is a fresh `random_int()`-weighted roll.
- **"Can't just keep re-spinning" is enforced entirely by `ShowPopup`'s existing frequency cap
  (`Config::getPopupFrequencyCapHours()`, default 24h), not a new parallel cap.** A wheel spin only
  ever happens as a side effect of a freshly-queued popup, and a new popup is only queued when the
  cap allows one. Inventing a second, reward-specific cap would let a visitor bypass the first
  popup's cap by somehow triggering a wheel popup more often than a plain one, with no way to tell
  the two apart server-side anyway (they're the same `ordo_pending_popup` row shape, same action
  type) - there's nothing a second cap could gate that the first doesn't already.
- **The winning tier is picked and the coupon minted once, at the moment the popup is queued
  (`ShowPopup::execute()`), not at poll/claim time.** This matches every other field this action
  already sets statically per delivery (headline/body/cta_*) and means the frequency cap above is
  the complete re-spin guard - there is no separate "claim" step that could race a second pick.
  `reward_label`/`coupon_code` are stored directly on the `ordo_pending_popup` row (two new
  nullable columns) and returned by `Model\Track\TrackerPollReader` alongside the existing popup
  fields, through both the REST poll endpoint and the GraphQL `OrdoTrackerPoll` resolver that
  already share that reader (ADR 0032/0033).
- **A tier with no `coupon_rule_id` (e.g. a "Try again" tier) sets `reward_label` but never calls
  `CouponGenerator`.** Minting a real, usable discount code for a tier the admin configured as a
  non-prize is both pointless and a real cost (a live `salesrule_coupon` row, logged campaign
  attribution) - the tier's own shape (`coupon_rule_id` omitted or not a positive int) is the
  signal, not a separate boolean flag, keeping the params shape minimal.
- **Coupon generation failure is caught and logged, never thrown** - the exact same fail-soft
  pattern `Model\Campaign\Action\GenerateCoupon` already uses around the identical
  `CouponGenerator::generate()` call. A misconfigured `coupon_rule_id` (a deleted/nonexistent rule)
  must not break the whole action: the popup still queues and still shows `reward_label`, just with
  no coupon code attached, rather than the visitor seeing nothing at all.
- **Admin editing: a plain JSON-in-a-text-field, not a new dedicated array-editor widget -
  accepted as a real, deliberate scope trade-off, not an oversight.** This codebase's admin
  campaign-flow editor (`view/adminhtml/web/js/campaign-flow-editor.js`) has exactly one precedent
  for a param field shaped like a list of weighted objects: `variant_list`, built specifically for
  `split`'s own `{key, weight, actions}` shape, and it is a substantial amount of dedicated
  JS/CSS (add/remove rows, live DOM wiring, its own JS unit tests) that only exists because split
  variants also carry a nested action chain per variant - reward tiers don't; they're three flat
  scalars. Building an equally dedicated `reward_tier_list` widget for a strictly simpler shape
  was rejected as out of proportion to this feature's real scope - it would roughly double the
  size of this change for a convenience the admin form, not the dispatch engine, needs. Giving
  `reward_tiers` a `getParamFields()` entry with no dedicated `type` (same as most of this action's
  other fields) means it round-trips as a plain string that `ShowPopup` JSON-decodes itself,
  with a `notice` pointing the admin at the expected shape. If this becomes a real adoption
  friction point, the honest escalation path is building `reward_tier_list` as its own follow-up,
  not retrofitting it silently into this PR.
- **Client-side: a lightweight "spin" suspense animation revealing an already-decided result, not
  a real client-side random pick or a canvas/SVG wheel.** The winning tier is chosen server-side
  before the popup is ever queued (see above) - there is nothing left to randomize in the browser.
  `view/frontend/web/js/tracker.js`'s `renderPopup()` now branches on `popup.reward_label`: present,
  it shows a brief "🎡 Spinning…" placeholder (`appendRewardReveal()`), then swaps in the won label
  and, when `coupon_code` is also present, a copyable code box (`Clipboard.writeText`, with a
  no-op fallback for a browser with no Clipboard API - there's nothing to fall back to, the code is
  still visible to select by hand). Kept deliberately simple - no canvas/SVG wheel graphic, no
  CSS `@keyframes` requiring an injected `<style>` tag - matching this file's existing
  dependency-free, plain-DOM, inline-style conventions (no jQuery/Knockout/RequireJS, per
  `CLAUDE.md`'s Hyvä-compatibility note) and this task's own "reasonably scoped, clean simple
  visual" framing.
- **Test layer: a `Test/Integration/` addition to the existing
  `CampaignVisitorPopupScenarioTest.php`, not a new MFTF test.** `Test/Mftf/SCENARIOS.md` §10
  already has `AdminCampaignPopupActionTest` proving the popup action's full
  queue-and-poll-and-render delivery mechanism end to end in a real browser; `reward_tiers` doesn't
  change that mechanism at all, only what gets written into the row before delivery. The genuinely
  new behavior - a real weighted pick landing in its expected tier over many trials, and a real
  `salesrule_coupon` minted for the winning tier - is exactly the kind of thing a `Test/Unit` mock
  can hide a real wiring bug behind, as `CouponGenerator`'s own docblock documents happened once
  before (a coupon that minted successfully but silently never appeared in the admin grid due to an
  `is_primary`/`COUPON_TYPE_SPECIFIC_AUTOGENERATED` filter mismatch no mocked collaborator could
  have caught). An Integration test against a real database and a real `CouponGenerator` is the
  correct and complete coverage per AGENTS.md's "New code needs its own tests" criteria; a second
  MFTF test would mostly re-prove the delivery mechanism `AdminCampaignPopupActionTest` already
  covers, at MFTF's much higher cost and flakiness.

## Consequences

- A merchant can now build an exit-intent or email-capture popup that offers a weighted random
  prize instead of one fixed message, with a real coupon minted only for prize-bearing tiers.
- `reward_tiers` is validated and normalized defensively (`ShowPopup::normalizeRewardTiers()`):
  a malformed entry (missing/blank label, non-numeric weight) is dropped rather than crashing the
  action, the same fail-closed philosophy `CampaignDispatcher::normalizeVariants()` already applies
  to `split`'s own weighted list. An entirely malformed/empty `reward_tiers` value is
  indistinguishable from an absent one - the popup just behaves as a plain static popup.
- No schema change to `ordo_notification`/`ordo_survey_prompt` - this is `ordo_pending_popup`-only;
  the other two poll-and-render concerns `Model\Track\TrackerPollReader` serves are untouched.
- `RewardTierSelector` and `SplitVariantSelector` now visibly diverge on purpose (one stateless and
  random, one stateful and hash-deterministic) despite a very similar `{weight, ...}` shape in
  both - a future contributor skimming only the params shape could still reach for the wrong one;
  this ADR, and both classes' own docblocks, are the record of why that would be wrong.
- Admin editing `reward_tiers` has no dedicated builder UI - an admin pastes/edits raw JSON in a
  text field with a format hint. This is a real, accepted UX rough edge for v1, not a bug; see the
  Decision section above for the explicit trade-off.
- This was implemented and verified with this repo's own standalone unit-test toolchain
  (phpcs/php-cs-fixer/PHPStan/rector/PHPUnit, plus the module's own `npm run test:js:coverage`,
  which confirmed 100% statement/branch/line/function coverage on the changed `tracker.js`) - no
  live Magento instance was available in this session (the shared `magento-ordo-test/` sandbox was
  explicitly off-limits for this change), so the new `reward_label`/`coupon_code` columns' real
  `setup:upgrade` migration and the new `Test/Integration/` assertions (a real weighted pick's
  distribution, a real `salesrule_coupon` row minted and matched back to its popup) are NOT
  verified against a running install in this session. CI's integration workflow will exercise the
  actual schema upgrade and real dispatch for the first time post-push.
