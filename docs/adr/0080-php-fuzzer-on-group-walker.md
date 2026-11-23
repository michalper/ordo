# ADR 0080: Coverage-guided fuzzing of GroupWalker, non-blocking

## Status

Accepted.

## Context

OpenSSF Best Practices Badge's `dynamic_analysis`/Scorecard's `Fuzzing` criteria flagged this repo
as having no fuzzing at all. A fuzzer only pays for itself where there's custom parsing/branching
logic over structured input that can plausibly be attacker- or partner-influenced - most of this
module's public surface doesn't qualify:

- `Controller/Track/*` (fully public, unauthenticated) only does simple scalar coercion
  (`(int)`, `is_numeric()`, `trim()`) on Magento's own already-parsed request params - no custom
  parsing for a fuzzer to exercise.
- `Controller/Webhook/Receive.php` and `Controller/WhatsApp/Webhook.php` do decode arbitrary
  external JSON, but only *after* an HMAC-SHA256 signature check rejects anything without the
  shared secret - the realistic threat model is a semi-trusted integration partner, not a fully
  anonymous attacker.
- `Model/Campaign/ParamFieldsNormalizer::parseJson()` only runs for an authenticated admin
  session.

`Model/Condition/GroupWalker` is different: it's the shared AND/OR/nested-group tree-walk behind
every campaign/segment condition evaluation (`ConditionGroupEvaluator`, `SegmentMemberResolver`),
it has zero Magento framework dependencies (pure PHP, no object manager needed), and its own
docblock describes nested groups as "arbitrarily deep" - exactly the kind of recursive,
structure-shaped input a coverage-guided fuzzer is good at stress-testing. It's reachable (once
decoded) from the webhook payload above, from `Model/Campaign/CampaignImporter`'s JSON import, and
from hand-written API/DB condition rows.

## Decision

- **Fuzz `GroupWalker::walk()` directly with `nikic/php-fuzzer`**, not a full HTTP-level target -
  no Magento bootstrap needed, which keeps the CI job fast and the target itself trivial to reason
  about. `Test/Fuzz/group_walker_target.php` feeds it JSON-decoded condition-list shapes (a
  constant leaf resolver, since the real leaf resolver - a `ConditionPool` lookup + a condition's
  own `isSatisfied()` - is out of scope for stress-testing the tree-walk itself).
- **Verified by hand before writing the target that "arbitrarily deep" does NOT mean an unbounded
  stack-exhaustion risk**: PHP's own `json_decode()` has a default max depth of 512, and each
  nesting level here costs ~4 JSON levels (object → `params` object → `conditions` array → item
  object), so anything past ~150 levels never even reaches `GroupWalker` - `json_decode()` itself
  returns `null` first. At exactly that maximum reachable depth (the `deep_chain.txt` corpus
  seed), `GroupWalker` runs in a fraction of a millisecond. This is a real, checked finding, not
  an assumption - the fuzz target's own docblock documents it so a future reader doesn't have to
  re-derive it. The target is kept anyway: there's still open value in fuzzing type confusion and
  malformed-shape edge cases in the tree-walk and `BooleanGroupCombineStrategy`'s combine logic,
  just not depth.
- **New CI job (`ci.yml`'s `fuzz-group-walker`), bounded (`--max-runs=20000` via the new
  `composer fuzz-check` script) and `continue-on-error: true`** - not added to the
  `unit-tests`/`static-analysis`/`rector`/`coding-standard` required-check set, and not part of
  `trigger-release`'s own `needs:` list, so it can never block a merge or a release. Same reasoning
  `coverage.yml`'s `mutation-testing-run` used when it was first added (see that job's own
  comment): establish an honest baseline with nobody gating on it yet, since this is both a
  brand-new tool to this repo and a target that's never been fuzzed before - gate on it later, if
  warranted, once it's proven not to flag noise on this target.
- Crash files (`crash-*.txt`, written to the job's working directory by php-fuzzer itself) are
  uploaded as a build artifact on failure for diagnosis, same pattern `mutation-testing-run` uses
  for its own log.

## Known limitation (accepted, not fixed here)

`php-fuzzer fuzz` always exits 0, even when it finds and writes a crash file - crash detection has
to be a separate post-run check (the `! ls crash-*.txt` step in `composer fuzz-check`). A local
`composer fuzz-check` run also mutates the committed `Test/Fuzz/corpus/` directory in place
(`NEW`/`REDUCE` actions add and prune corpus entries, including possibly replacing a hand-written
seed with a smaller equivalent) - this is php-fuzzer's intended persistent-corpus design, not a
bug, but it means a developer who wants to keep a specific hand-written seed exactly as written
should expect it to evolve under repeated local runs.
