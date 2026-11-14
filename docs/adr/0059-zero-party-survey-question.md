# ADR 0059: Zero-party-data survey question (text/choice, beyond NPS)

## Status

Accepted.

## Context

A fifth-round roadmap brainstorm item, checked directly against the code: the only "survey"
primitive this module has is a hardcoded 0-10 NPS score. `ordo_survey_prompt`'s own `score
smallint` column, `Model\Campaign\Action\NpsSurvey`, and `Model\Campaign\Condition\
NpsScoreAtLeast` are built for exactly one shape - there was no way to ask a free-text or
multiple-choice preference question (favorite category, size, skin type, …) and later
segment/gate a campaign on the answer, a common CDP "zero-party data" personalization pattern
this module's existing NPS machinery couldn't be reused for as-is.

The real design question was whether to widen the existing `ordo_survey_prompt` table/
`NpsSurvey`/`NpsScoreAtLeast` in place, or add a parallel question-type system next to them
untouched.

## Decision

- **A nullable `response_text` column (plus `question_type`/`choices`) added to the existing
  `ordo_survey_prompt` table - no new table, no separate lifecycle.** The alternative considered
  was a second table (e.g. `ordo_zero_party_response`) with its own claim/deliver/respond
  controller wiring. Rejected: `ordo_survey_prompt`'s claim-before-use lifecycle (queued by an
  action, claimed via `delivered_at` the moment `Controller\Track\Poll` hands it out, answered
  once via `Controller\Track\SubmitSurveyResponse`) is exactly what a generic question needs too
  - the only thing that actually differs between an NPS score and a free-text/choice answer is
  the *shape of the answer itself*, not the delivery mechanism around it. Reusing the table means
  reusing `Controller\Track\Poll`'s claim query, `Cron\PruneSurveyPrompts`, and the admin's
  existing Flow-canvas rendering verbatim, for free.
- **`NpsSurvey`/`NpsScoreAtLeast` are completely untouched - a new, separate `GenericSurvey`
  action and `SurveyResponseEquals` condition instead of widening the existing two classes with a
  `question_type` param.** The roadmap item itself suggested this shape ("keep NPS exactly as-is,
  add a sibling answer shape"). Widening `NpsSurvey` with a `question_type` param defaulting to
  `"nps"` was considered and rejected: it would mean every existing, already-shipped `nps_survey`
  campaign action's params implicitly gain a new key at read time, and `NpsScoreAtLeast`'s own
  "read the most recent answered row, check `score`" logic would need an `if` branch for a case
  it can never actually hit. A new `generic_survey` action / `survey_response_equals` condition,
  registered as two more `ConditionPool`/`ActionPool` di.xml entries, means zero behavior change
  risk for any campaign already using `nps_survey`/`nps_score_at_least` - this is purely additive.
- **`question_type` is `null` for every legacy `nps_survey` row, never retroactively backfilled to
  `"nps"`.** `Controller\Track\SubmitSurveyResponse` branches on `$prompt->getQuestionType() ===
  null` to decide whether to run the original (byte-for-byte unchanged) 0-10 score validation or
  the new response_text validation - `null` is the correct, meaningful signal for "this is an
  nps_survey row, nothing about this feature touches it," not a placeholder that needs cleaning
  up later.
- **No separate options/choices table for "choice" questions - `choices` is a plain
  comma-separated string column, same spirit as `AddTag`'s `tags` param being a JSON array rather
  than a join table.** A real options table would let an option carry its own id/sort
  order/i18n, none of which this feature needs for v1: a choice question's options are defined
  once, inline, by whoever builds the campaign, and the only thing ever read back is which exact
  string the customer picked. The accepted limitation: a choice value containing a literal comma
  can't be represented, and renaming an option after prompts have already been sent leaves old
  rows with the old text (expected - orphaned-but-honest answer, not evidence of a bug). If a
  future need arises for per-option metadata (analytics breakdowns by option, admin-managed
  reusable option sets), that is a real reason to revisit this, not something this ADR assumes
  away.
- **`survey_response_equals` matches the customer's most recent answered row for a specific
  question text, not "most recent answered row overall" the way `NpsScoreAtLeast` does.** NPS has
  exactly one implicit question concept - any `nps_survey` prompt counts toward
  `nps_score_at_least`, regardless of its literal wording, because every NPS prompt means the
  same thing (satisfaction, 0-10). A generic survey question has no such shared meaning across
  different literal questions, so the condition's bulk/single-row queries filter by `question`
  *before* picking the latest row per customer
  (`Model\ResourceModel\SurveyPrompt\Collection::addLatestResponseFilterForQuestion()`), unlike
  `NpsScoreAtLeast::resolveCustomerIds()`'s unfiltered self-join. Matching is done on the literal
  question string (same as `EventOccurred`'s `event_key` matching), not a separate question id -
  consistent with there being no dedicated "question" entity anywhere else in this module either.
- **The storefront widget (`view/frontend/web/js/tracker.js`'s `renderSurvey()`) is not extended
  in this PR to actually render a text input or choice buttons - this is a deliberate, accepted
  v1 scope limit, not an oversight.** The backend lifecycle (action → queue → claim → respond →
  condition) is fully built and integration-tested end to end
  (`Test/Integration/GenericSurveyScenarioTest`); only the one remaining piece - a
  `question_type`-aware frontend widget, plus the matching `Model\Track\TrackerPollReader`/
  GraphQL (`submitOrdoSurveyResponse`) field additions to carry `question_type`/`choices` to the
  browser - is left for a follow-up (tracked in `ROADMAP.md`). Reasoning: the roadmap gap this PR
  closes is explicitly about the *answer shape and condition* ("a sibling answer shape... plus a
  new survey_response_equals condition"), and `Controller\Track\SubmitSurveyResponse` already
  accepts and validates a real `response_text` answer today for any caller that posts one (a
  custom storefront widget, a direct API integration) - closing the full admin-authored,
  tracker.js-rendered loop is real, separable follow-up work, not a hole in this feature's own
  claimed scope.

## Consequences

- A merchant can now ask a free-text or multiple-choice zero-party-data question via a campaign's
  `generic_survey` action, and gate a later campaign (or a segment) on the exact answer via
  `survey_response_equals` - closing the specific roadmap gap, with zero behavior change to any
  existing `nps_survey`/`nps_score_at_least` campaign.
- Reusing `ordo_survey_prompt` means three new always-nullable columns on a table that, for every
  existing `nps_survey` row, will simply never populate them - a small amount of permanent, unused
  column width on old rows, accepted as cheaper than a second table with its own duplicated
  lifecycle plumbing.
- The storefront can't yet actually render a text/choice question to a real shopper purely from
  this module's own shipped JS - only the backend half of the feature is wired to a real browser
  today. A merchant integrating their own frontend (or waiting on the tracker.js follow-up) gets
  the full lifecycle; a merchant relying solely on this module's bundled widget does not yet see
  anything beyond the unchanged NPS prompt. This is called out explicitly in
  `Test/Mftf/SCENARIOS.md` (§7) and `ROADMAP.md` so it isn't mistaken for a bug later.
