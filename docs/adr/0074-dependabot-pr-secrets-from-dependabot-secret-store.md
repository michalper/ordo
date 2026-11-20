# ADR 0074: Dependabot PRs get CODECOV_TOKEN/SONAR_TOKEN from the Dependabot secret store, not pull_request_target

## Status

Accepted.

## Context

`php-coverage` (coverage.yml) and parts of `ci.yml`/`coverage.yml`'s comments claimed GitHub
withholds *every* Actions secret from a Dependabot-triggered `pull_request` run, "regardless of
ecosystem, a separate, unrelated restriction with no per-repo opt-out" (dependabot.yml's own prior
wording). That was true for the repo's `MAGENTO_COMPOSER_USERNAME`/`PASSWORD`, which were already
copied into Settings → Secrets and variables → **Dependabot** back when `dependabot.yml`'s
`registries:` block was set up (2026-09-01) - but the claim that this generalizes to "every secret,
every ecosystem" was never actually re-verified, and PR #413/#414 (two Dependabot PRs - a GitHub
Actions bump and an npm bump, neither composer-ecosystem) disproved it directly.

Checking those two PRs' actual run logs: every job logs `Secret source: Dependabot` for a
Dependabot-triggered `pull_request` run, and `unit-tests`/`static-analysis`/`rector`/
`coding-standard` all passed - meaning `secrets.MAGENTO_COMPOSER_USERNAME`/`PASSWORD` resolved
correctly from the **Dependabot secret store**, for PRs that don't even touch `composer.json`. Only
`php-coverage`'s Codecov upload failed, with Codecov's CLI logging `Token length: 0`. The actual
mechanism: for a Dependabot-authored `pull_request` run, `secrets.<NAME>` resolves against the
Dependabot secret store instead of the Actions one - not "withheld entirely". `CODECOV_TOKEN`/
`SONAR_TOKEN` simply weren't in that store, so they alone resolved empty.

This is a materially different, and much smaller, problem than the one ADR-worthy alternative on
the table (from the prior `pull_request_target` attempt, reverted for SonarCloud `githubactions:
S7631`): a `workflow_run`-based split, where a second, secrets-bearing workflow triggers after the
untrusted one completes and uploads an artifact the first job produced, without ever checking out
the PR's head ref under a secrets-bearing event. That's the standard safe pattern for this problem
in general, and still the right answer if a secret genuinely can't go in the Dependabot store - but
it wasn't needed here.

## Decision

Added `CODECOV_TOKEN` and `SONAR_TOKEN` to the repo's Dependabot secret store (`gh secret set
CODECOV_TOKEN --app dependabot`, same for `SONAR_TOKEN`), alongside the existing
`MAGENTO_COMPOSER_USERNAME`/`PASSWORD`. No workflow changes: `ci.yml`/`coverage.yml` already read
`secrets.CODECOV_TOKEN`/`secrets.SONAR_TOKEN` via `pull_request`, and that trigger already resolves
against the Dependabot store for Dependabot-authored runs - the gap was purely that the store was
missing two entries, not a trigger/workflow problem.

Accepted the same trust boundary already implicit in copying `MAGENTO_COMPOSER_*` there: a
Dependabot-triggered `pull_request` run already executes `composer install`/`npm ci` against the
bumped dependency with those credentials in scope, which is strictly more exposure than handing a
Codecov/Sonar upload token to a job that only uploads a coverage report it already generated.

Updated the now-inaccurate comments in `ci.yml`, `coverage.yml`, and `dependabot.yml` that
described Dependabot PRs as permanently red/secret-less here.

## Consequences

- Dependabot PRs now get a working `php-coverage`/`sonar` check (pending the next Dependabot PR
  actually running - not forced/re-triggered as part of this change).
- No new SonarCloud security finding: `pull_request_target` was never introduced.
- `CODECOV_TOKEN`/`SONAR_TOKEN` are now present in both the Actions and the Dependabot secret
  stores; rotating either requires updating both.
- If a future secret can't be duplicated into the Dependabot store for some reason, the
  `workflow_run` split considered here is still the fallback - not applied, since it wasn't needed.
