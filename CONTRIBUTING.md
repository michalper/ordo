# Contributing to Ordo Automation

Thanks for considering a contribution. This is a Magento 2 module (`michalper/ordo`) — this repo holds
only the module's own source, not a full Magento installation, so tests can't be run directly from here.

## Setting up a dev environment

You need a real Magento 2 Open Source install with this module required via a Composer path repository. A minimal
`composer.json` addition in your Magento root:

```json
{
    "repositories": [
        { "type": "path", "url": "../path/to/this/repo", "options": { "symlink": false } }
    ]
}
```

Then `composer require michalper/ordo:@dev`, `bin/magento module:enable Ordo_Automation`, and
`bin/magento setup:upgrade`.

**Note:** with `"symlink": false`, Composer *copies* files into `vendor/michalper/ordo` — changes here
aren't visible in your test environment until you re-run `composer update michalper/ordo`. The module's
own `composer.json` pins `"version": "1.0.0"`, so if Composer reports "Nothing to modify in lock file" after a
change, force a reinstall: `rm -rf vendor/michalper/ordo && composer update michalper/ordo`.

## Code style and static analysis

- **Coding standard**: Magento2 Coding Standard, enforced via `phpcs.xml.dist`. Run `composer cs-check` /
  `composer phpcs-check`; auto-fix what's fixable with `composer cs-fix`.
- **Static analysis**: PHPStan at `level: max` (`phpstan.neon`), via `bitexpert/phpstan-magento` so it understands
  Magento's virtual types (`*Factory`, `*Proxy`, interceptors). Run from your Magento root:
  ```bash
  php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
  ```
  If a change shifts the number of suppressed baseline errors, regenerate `phpstan-baseline.neon` — don't hand-edit it.

Both run in CI (`.github/workflows/ci.yml`) on every push/PR; a change that doesn't pass either won't merge.

**Why `composer.lock` isn't committed**: this repo is a library (consumed by a real Magento app via a path
repository — see "What this is" in `CLAUDE.md`), not a deployable application, so it follows Composer's own
guidance for packages and leaves version resolution to whoever requires it. The trade-off: CI's own
`composer install` re-resolves `require-dev` fresh on every run, so a new release of a dev-only tool can
change behavior between one PR and the next with no code change on either side — this actually happened
(`phpstan/phpstan` 2.2.15 → 2.2.16 mid-session invalidated one `phpstan-baseline.neon` entry). `phpstan/phpstan`
and `infection/infection` are pinned to an exact version in `composer.json` (not a caret range) for exactly
this reason: they're the two whose output is checked into the repo (`phpstan-baseline.neon`, `infection.json5`'s
MSI gate) and would otherwise silently drift underneath it. Bumping either is still automatic to *notice* -
Dependabot's `composer` ecosystem entry (`.github/dependabot.yml`, weekly) opens a PR the moment a new version
of either exists, same as any other dependency - the pin only means that PR's own CI is where a baseline/MSI
fallout shows up and gets fixed, not some unrelated PR that happened to run after the version silently moved.

## Tests

Four layers, each with a different scope and a different way to run it:

- **`Test/Unit/`** — no live Magento needed beyond the framework classes on Composer. Run:
  ```bash
  vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/michalper/ordo/Test/Unit
  ```
- **`Test/Integration/`** — real object manager, real dev database, no mocked `ScopeConfigInterface`. Requires a
  working `app/etc/env.php`. Run with `--bootstrap app/bootstrap.php` from the Magento root:
  ```bash
  vendor/bin/phpunit --bootstrap app/bootstrap.php vendor/michalper/ordo/Test/Integration
  ```
- **`Test/Api/`** — `webapi_rest` calls against the module's REST surface, same real-instance requirement as
  `Test/Integration/`.
- **`Test/Mftf/`** — full browser acceptance tests (Selenium + a real webserver). See `Test/Mftf/SCENARIOS.md` for
  what's covered and what isn't, and `AGENTS.md` for the real-CI-only pitfalls already found and fixed — read
  that before adding a new MFTF test, several of them cost real debugging time to track down.

CI runs the unit-test + static-analysis lane (`ci.yml`) on every push. `Test/Integration`, `Test/Api`, and MFTF
each need a real Magento install (service containers, and a browser for MFTF) and each have their own dedicated
workflow: `integration-tests.yml` and `api-tests.yml` run on every push/PR too; `mftf.yml` runs nightly (full
browser suite is slower) plus on demand. All three support manual `workflow_dispatch` if you want to trigger one
directly once your PR is up.

## Making a change

- Keep PRs scoped to one thing — a bug fix, one new scenario, one refactor. Large mixed PRs are harder to review
  and harder to bisect later.
- If you fix a bug, add a regression test in whichever layer actually would have caught it — usually `Test/Unit`
  for a logic bug, `Test/Integration` for a DI-wiring or real-database bug, MFTF only when the bug is genuinely
  about the browser/UI, since MFTF is the slowest and most environment-sensitive layer.
- Match the existing comment style: comments explain *why*, not *what* — a hidden constraint, a workaround for a
  specific bug, a non-obvious invariant. Don't add a comment a well-named identifier already makes obvious.
- Update `Test/Mftf/SCENARIOS.md` if you add or close a scenario gap it tracks, and `ROADMAP.md` if you complete
  or add a roadmap item.

## Reporting issues

Open a GitHub issue with what you expected, what happened instead, and enough to reproduce it (Magento version,
PHP version, relevant config). For a security issue, please don't open a public issue — see
[SECURITY.md](SECURITY.md) instead.

## License

By contributing, you agree your contribution is licensed under this project's [MIT license](LICENSE).
