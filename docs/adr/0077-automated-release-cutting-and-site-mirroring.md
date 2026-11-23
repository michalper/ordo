# ADR 0077: Automated release cutting and ordo-site changelog mirroring

## Status

Accepted.

## Context

CONTRIBUTING.md's "Cutting a release" had two manual steps after a CHANGELOG-update PR merged to
`main`: run `scripts/release.sh X.Y.Z` (tag + publish the GitHub Release), and separately hand-write
a short, plain-language entry into `ordo-site/changelog.html` (a different repo, deployed to
ordo.marketing). Both are easy to forget - the first already once left 1.6.0 tagged with no visible
Release (see `scripts/release.sh`'s own comment) - and the maintainer explicitly asked for both to
happen automatically, with no manual approval step in the loop.

## Decision

- **`release.yml` is called (`workflow_call`) as the last job of each of the 4 CI workflows
  (`ci.yml`, `coverage.yml`, `api-tests.yml`, `integration-tests.yml`), gated on
  `github.event_name == 'push' && github.ref == 'refs/heads/main'` and `needs:` on that workflow's
  own non-skippable gate job(s) - not on `push` to `main` directly in `release.yml` itself, and
  no longer on `workflow_run`.** A release must never be cut off a commit before its own CI has
  actually gone green - the same gate `scripts/release.sh` already enforces manually. Trade-off
  accepted: each of the 4 callers finishing fires this once, so it can run up to 4 times per
  commit. This is harmless by construction (an "already tagged" check makes every run after the
  first a no-op, and an "every check green" check - still needed, since the other 3 workflows run
  in parallel and may not have finished yet - makes an early firing a no-op too, retried by
  whichever finishes last).
  The `needs:` list in each caller is specifically that workflow's `if: always()` trivial
  aggregator job(s) (e.g. `unit-tests`/`static-analysis`/`rector`/`coding-standard` in `ci.yml`),
  never the raw `changes`-path-filter-gated jobs directly - those aggregators always resolve to a
  definite success/failure and never `skipped`, which is what lets `needs:` use its default
  `if: success()` with no extra `always()` + `result` dance.
- **Originally triggered on `workflow_run` of those same 4 workflows finishing,** which forced the
  `release` job's `if:` to check both `workflow_run.head_branch == 'main'` AND
  `workflow_run.head_repository.full_name == github.repository` - the branch check alone was
  insufficient, since a fork can name its own branch `main` too, and `workflow_run` fires even for
  fork-originated `pull_request` runs of the 4 upstream workflows; without the repository check,
  such a run's checked-out (attacker-controlled) SHA would have been processed by this job while it
  still held this repo's own write-scoped secrets (caught by SonarCloud's `githubactions:S7631`
  during review). That `if:` guard made the actual risk null, but OpenSSF Scorecard's
  `Dangerous-Workflow` check flags the underlying pattern (checking out a `workflow_run` event's
  `head_sha` in a job with write permissions) regardless of any `if:` condition - it's a static
  analysis that doesn't evaluate conditionals, so it scored this `0` even though the gap it's
  warning about was already closed. Moved to `workflow_call` from a direct-push trigger instead:
  a fork cannot push to this repo's `main` branch at all, so there is no externally-controlled SHA
  for this workflow to ever act on in the first place - a structural fix, not just one that
  satisfies the scanner. The `head_branch`/`head_repository` checks and the `workflow_run.head_sha`
  checkout `ref:` override are gone; `release.yml`'s own jobs now just use `github.sha` directly,
  which in a `workflow_call` run is the same commit the calling push event fired for.
- **`update-site` authenticates to the Claude API via Workload Identity Federation, not a static
  `ANTHROPIC_API_KEY` secret.** A short-lived token is minted per run from the job's own GitHub
  OIDC identity; the federation rule is pinned to this exact repo (by name and immutable owner id)
  and to `refs/heads/main`. One fewer long-lived secret to create, rotate, or leak, at the cost of
  a more involved token-exchange step than a plain `x-api-key` header.
- **`update-site` merges its ordo-site PR immediately, with no human review gate.** Requiring a
  review on every single release would defeat the point of automating this at all - that was the
  maintainer's explicit, considered instruction, not an oversight. The risk this accepts (an LLM
  autonomously publishing to a live public page) is mitigated structurally rather than by adding a
  review step back in: the model is asked for **plain text only**, never HTML, and the workflow
  itself HTML-escapes every line and wraps it in `<li>` before it ever reaches `changelog.html` -
  so no reply the model could return, however adversarial, can inject a tag or attribute into the
  page. (An earlier version of this step instead pattern-matched the model's raw HTML output
  against `<li>.*</li>` as its only safety check - a real stored-XSS gap, since a crafted `.*`
  payload can contain a live `<script>` while still satisfying that wrapper shape. Caught in
  review before this workflow ever ran for a real release.)
- **Shared logic lives in `scripts/*.sh`, called by both `scripts/release.sh` (manual) and
  `release.yml` (automated), instead of being duplicated inline in the workflow.** `ci-green.sh`
  (is every check-run for a commit green), `changelog-section.sh` (extract one dated section),
  `is-tagged.sh` (is a version already tagged), and `tag-and-release.sh` (tag + push + publish) -
  four small scripts instead of the same jq/awk logic typed out twice, which had already drifted
  once before either copy even merged (an unpaginated check-runs query was fixed in one copy
  during review, and would have silently stayed broken in the other).

## Known limitation (accepted, not fixed here)

If `update-site` fails *after* `release` has already tagged and published (e.g. a transient
Anthropic/WIF outage), there's no automatic retry: that version is permanently past the
"already tagged" gate, so no later run of this workflow can ever set `released=true` for it again.
A `workflow_dispatch`-based manual-version retry path was considered and deliberately deferred -
it would add a second, differently-shaped code path (no `release` job output to read `version`/
`notes` from) that would itself need testing before being trusted on a workflow with no review
gate, which didn't seem like the right trade to make under time pressure while first proving the
happy path works end to end. Recover manually in the meantime via this workflow's own Actions UI
("Re-run failed jobs" on the `update-site` job) - its steps are themselves idempotent enough for
that to be safe.
