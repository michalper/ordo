# ADR 0064: Storefront widget for generic_survey questions (closing the ADR 0061 gap)

## Status

Accepted.

## Context

ADR 0061 added a `generic_survey` campaign action (free-text or multiple-choice questions, on the
same `ordo_survey_prompt` claim/deliver/respond lifecycle as the existing NPS survey) but
explicitly left the storefront half out of scope: `view/frontend/web/js/tracker.js`'s own
`renderSurvey()` still only ever rendered the legacy 0-10 NPS button row, and neither
`Model\Track\TrackerPollReader` nor the GraphQL `submitOrdoSurveyResponse` mutation carried
`question_type`/`choices`/`responseText` to or from the browser. The REST transport
(`Controller\Track\SubmitSurveyResponse`) already had the real `question_type`-branching
validation from day one of ADR 0061 - only the storefront widget and the GraphQL mutation were
the actual gap.

This was tracked as a known, named follow-up in ADR 0061's own Consequences section and in
`ROADMAP.md`, not a bug - but it meant a merchant relying solely on this module's bundled widget
(the large majority of this module's install base, which targets Magento Open Source without a
headless/custom storefront) got no visible UI at all for a `generic_survey` action configured in
the admin, making the feature effectively half-shipped for that audience. This ADR closes that
gap.

## Decision

- **`Model\Track\TrackerPollReader::resolveSurvey()` now returns `question_type` and `choices`
  alongside the existing `id`/`question`.** `choices` is split from `SurveyPrompt`'s own
  comma-separated column into a plain array once here, so neither `tracker.js` nor a GraphQL
  consumer has to parse the raw string itself - the only place that format decision is made.
  `question_type` stays `null` for a legacy `nps_survey` row, matching every other
  `question_type === null` check already in this module (`Controller\Track\SubmitSurveyResponse`,
  `SurveyPrompt::getQuestionType()`'s own docblock).
- **`view/frontend/web/js/tracker.js`'s `renderSurvey()` branches on `survey.question_type`**:
  `null`/undefined keeps the original 0-10 button row byte-for-byte (`appendSurveyScoreButtons()`,
  extracted verbatim from the old `renderSurvey()` body, zero behavior change for any existing
  `nps_survey` campaign), `"text"` renders a single-line input plus a submit button
  (`appendSurveyTextInput()`), and `"choice"` renders one button per `survey.choices` entry
  (`appendSurveyChoiceButtons()`) - the same row-of-buttons shape the score row already used.
  `submitSurveyResponse()` now takes a `params` object (`{score}` or `{response_text}`) instead of
  a hardcoded `score` positional argument, so it can post either answer shape through the same
  fire-and-forget POST to the unchanged `Controller\Track\SubmitSurveyResponse` endpoint.
- **No canvas/SVG, no new dependency, no injected `<style>` tag** - same "dependency-free,
  plain-DOM, inline-style" convention this file already established for the reward-popup widget
  (ADR 0063) and every other widget in it. A "choice" question's buttons and a "text" question's
  input reuse the exact same inline-style constants (border/radius/padding) the legacy score
  button row already used, not a new visual language.
- **The GraphQL `submitOrdoSurveyResponse` mutation gains an optional `responseText: String` arg,
  and `score` is widened from `Int!` to `Int`.** Widening a required argument to optional is a
  non-breaking GraphQL schema change - no existing caller sending `score` is affected. The
  alternative (a brand-new mutation, e.g. `submitOrdoGenericSurveyResponse`) was rejected: it would
  mean two ownership/claim-row-loading code paths doing the exact same thing with a one-field
  difference, and the REST transport already proves one mutation handling both shapes via a
  question_type branch is the right shape for this lifecycle.
- **`Model\GraphQl\Resolver\SubmitSurveyResponse` now mirrors
  `Controller\Track\SubmitSurveyResponse`'s own branching exactly, including loading the prompt
  and checking ownership *before* validating `score`/`responseText`** - not reusing a shared
  service class between the two. This repo's existing convention for this specific
  controller/resolver pair is duplication, not a shared helper (unlike
  `Model\Track\TrackerPollReader`, which the poll controller and resolver already share, because
  identity resolution is the only part that differs for *that* pair). Introducing a new shared
  class now, for a pair that has never shared one, would be a bigger refactor than this feature's
  own scope calls for. The one real behavior change from this mirroring: the GraphQL resolver used
  to reject an invalid `score` before ever loading the prompt; now it loads the prompt first (the
  same order the REST controller always used), because deciding whether to validate `score` or
  `responseText` at all requires knowing the prompt's own `question_type` first. This is strictly
  more correct, not a regression - the old GraphQL-only early-reject order was itself the
  inconsistency being fixed.
- **`choices` round-trips as a GraphQL `[String]`, not a raw comma-separated `String`.** The
  storage format (`ordo_survey_prompt.choices`, a plain comma-separated column - ADR 0061's own
  deliberate choice, unchanged here) is an implementation detail of that table, not something a
  GraphQL or JS consumer should have to parse itself.

## Consequences

- A merchant using only this module's bundled storefront widget now sees a real, working
  free-text input or choice-button row for a `generic_survey` campaign action, closing the gap
  ADR 0061 and `ROADMAP.md` both named explicitly. Zero behavior change for any existing
  `nps_survey` campaign - the legacy button row is the exact same code, just extracted into its
  own function.
- A merchant integrating their own headless/PWA storefront can now also call
  `submitOrdoSurveyResponse` with `responseText` for a `generic_survey` prompt, where previously
  only the REST transport supported it - closing the specific GraphQL/REST parity gap ADR 0061's
  Consequences section flagged.
- Verified in this session: `npm run test:js:coverage` (100% statement/branch/function/line
  coverage on the changed `tracker.js`), the full module `Test/Unit` suite inside the
  `magento-ordo-test/` sandbox (3576 tests, 0 new failures - two pre-existing, unrelated
  `ShowPopupTest` failures were found and are tracked separately, not caused by this change),
  `Test/Integration/ProductPerformanceGridCollectionTest`/`GenericSurveyScenarioTest`/
  `CalculateProductPerformanceTest` against a real database, and a real HTTP `POST /graphql`
  against the running sandbox confirming the widened schema parses and both the query's new
  survey fields and the mutation's new `responseText` arg resolve correctly end to end.
