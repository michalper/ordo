# ADR 0093: NPS detractor → lead routing escalation

## Status

Accepted.

## Context

`ROADMAP.md`'s 2026-11-28 brainstorm (sixth pass) found that a detractor NPS/survey response had
no way to reach a human: `Model\Campaign\Condition\NpsScoreAtLeast` only supports an "at least"
threshold (no `nps_score_at_most`/"detractor" condition existed), and `Model\LeadRouting\
LeadAssigner`/`LeadRoutingRuleEvaluator` were only ever invoked from two hardcoded observer
events (`customer_register_success`, `ordo_customer_score_threshold_crossed` - see `Observer\
AssignLeadRoutingRule`'s own docblock), never from the campaign engine's own `ActionPool`.
Together, a store genuinely could not build "a customer just gave us a low NPS score - get a rep
on this" - despite this module already having every piece except the two connectors.

## Decision

- **A new condition, `nps_score_at_most`** (`Model\Campaign\Condition\NpsScoreAtMost`) - the
  exact mirror of `NpsScoreAtLeast`, same query (`SurveyPrompt\Collection::
  addLatestResponseFilter()`'s single most-recent-*answered*-prompt read), same "threshold"
  dedicated field, flipped comparison (`<=`, not `>=`). The two are now both thin subclasses of a
  new shared `AbstractNpsScoreThresholdCondition` - SonarCloud's new-code duplication gate flagged
  the original copy-pasted pair (CI-blocking, not just advisory), and the right fix was extracting
  the shared query/field shape, not suppressing the check. Each subclass supplies only the
  comparison direction and the field label.
- **A new action, `assign_lead_routing_rule`** (`Model\Campaign\Action\AssignLeadRoutingRule`) - a
  thin wrapper around the exact same `LeadRoutingRuleEvaluator::getMatchingRule()`/
  `LeadAssigner::assign()` pair `Observer\AssignLeadRoutingRule` already calls, reusing their
  round-robin assignment logic as-is. No params of its own - the matching rule and its rep pool
  are looked up fresh every time, same as the observer.
- **`LeadAssigner::hasAssignedRep()`'s own one-time-assignment guard** (re-checked again, under a
  per-customer lock, inside `assign()` itself) already protects this action from overwriting an
  existing rep relationship - including one either of the two real-time events already made. No
  new guard needed; the action is safe to layer onto any campaign trigger without double-assigning
  a customer who already has a rep.
- **Both reuse di.xml registration only** - `ConditionPool`/`ActionPool` entries, no `Block\
  Adminhtml\Campaign\Edit\Flow.php` changes (that canvas's condition/action fields are already
  driven generically from each registered class's own `getParamFields()`, per that file's own
  docblock). The classic form (`ordo_campaign_form.xml`/`ordo_segment_form.xml`) needed one
  explicit `switcherConfig` `<rule>` each, though - that mechanism has no "else" semantics (see
  those files' own comments), so every type, even one reusing an existing field or needing none at
  all, gets its own rule hiding every dedicated field it doesn't use.

## Consequences

- A store can now route a lead off ANY trigger a campaign can build on (e.g. `nps_score_at_most`
  below a detractor survey response, or any other trigger entirely), not just the two hardcoded
  real-time events.
- Noted, not fixed, while editing `ordo_campaign_form.xml`'s action `switcherConfig`: two existing
  rules both use `name="4"` (`add_points` and `add_loyalty_points`), a pre-existing duplicate that
  likely makes one silently lose to the other wherever this file's XML is DOM-merged/compiled.
  Out of scope here - fixing it needs renumbering and a real admin-UI check this change didn't
  otherwise require, and the two action types are unrelated to this one.
