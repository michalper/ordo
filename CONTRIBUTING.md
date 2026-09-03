# Contributing to Ordo Automation

Thanks for considering a contribution. This is a Magento 2 module (`ordo/module-automation`) — this repo holds
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

Then `composer require ordo/module-automation:@dev`, `bin/magento module:enable Ordo_Automation`, and
`bin/magento setup:upgrade`.

**Note:** with `"symlink": false`, Composer *copies* files into `vendor/ordo/module-automation` — changes here
aren't visible in your test environment until you re-run `composer update ordo/module-automation`. The module's
own `composer.json` pins `"version": "1.0.0"`, so if Composer reports "Nothing to modify in lock file" after a
change, force a reinstall: `rm -rf vendor/ordo/module-automation && composer update ordo/module-automation`.

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

## Tests

Four layers, each with a different scope and a different way to run it:

- **`Test/Unit/`** — no live Magento needed beyond the framework classes on Composer. Run:
  ```bash
  vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/ordo/module-automation/Test/Unit
  ```
- **`Test/Integration/`** — real object manager, real dev database, no mocked `ScopeConfigInterface`. Requires a
  working `app/etc/env.php`. Run with `--bootstrap app/bootstrap.php` from the Magento root:
  ```bash
  vendor/bin/phpunit --bootstrap app/bootstrap.php vendor/ordo/module-automation/Test/Integration
  ```
- **`Test/Api/`** — `webapi_rest` calls against the module's REST surface, same real-instance requirement as
  `Test/Integration/`.
- **`Test/Mftf/`** — full browser acceptance tests (Selenium + a real webserver). See `Test/Mftf/SCENARIOS.md` for
  what's covered and what isn't, and `AGENTS.md` for the real-CI-only pitfalls already found and fixed — read
  that before adding a new MFTF test, several of them cost real debugging time to track down.

CI runs the unit-test + static-analysis lane on every push. `Test/Integration`, `Test/Api`, and MFTF need a real
Magento install (service containers, browser) and are wired into `.github/workflows/mftf.yml`, which you can
trigger manually (`workflow_dispatch`) once your PR is up.

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
PHP version, relevant config). For a security issue, please don't open a public issue — see the repo's contact
info instead.

## License

By contributing, you agree your contribution is licensed under this project's [MIT license](LICENSE).
