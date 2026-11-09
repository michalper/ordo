---
name: verify-ordo-changes
description: Exact commands to lint, statically analyze, and test a change to this module (phpcs/php-cs-fixer, PHPStan, rector, PHPUnit unit/integration, mutation testing, JS tests). Use before considering any code change in this repo done.
---

All of these run from a Magento root with this module installed (see `AGENTS.md` for the
`magento-ordo-test/` Docker environment and its exact `docker compose exec php ...` invocations).

```bash
# Coding standard (Magento2 ruleset, phpcs.xml.dist) — auto-fix, then verify
composer cs-fix     # phpcbf + php-cs-fixer fix, in place
composer cs-check   # php-cs-fixer --dry-run --diff (what CI runs — zero output = clean)
composer phpcs-check # phpcs alone (phpcbf doesn't fix 100% of its own violations)

# Static analysis — PHPStan level: max, via bitexpert/phpstan-magento for Magento virtual types
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
# If a change shifts the suppressed-error count, regenerate phpstan-baseline.neon — don't hand-edit it.

# Dead code / PHP 8.3 modernization checks (report-only, deliberately skips Api/ — see rector.php)
composer rector-check   # rector process --dry-run

# Unit tests — no live Magento needed beyond framework classes on Composer
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/michalper/ordo/Test/Unit
# Single test class:
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/michalper/ordo/Test/Unit/Model/CampaignDispatcherTest.php
# Single test method:
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist --filter testMethodName vendor/michalper/ordo/Test/Unit/Model/CampaignDispatcherTest.php

# Integration tests — real DI/database, no mocks (see AGENTS.md: stop the queue consumers first)
vendor/bin/phpunit --bootstrap app/bootstrap.php vendor/michalper/ordo/Test/Integration

# Mutation testing (infection.json5) — minMsi/minCoveredMsi 70, a couple points under the measured
# ~70.2% baseline so normal mutant-selection noise doesn't fail an unrelated PR
vendor/bin/infection

# JS unit tests — the handful of hand-rolled AMD modules under view/**/web/js (run from this repo, has
# its own package.json; everything else here is PHP-only)
npm run test:js
npm run test:js:coverage
```

`Test/Api/` (REST functional tests) and MFTF (`Test/Mftf/`) both need a real running instance —
see `Test/Api/README.md`, `Test/Mftf/README.md`/`SCENARIOS.md`, and `AGENTS.md` for the concrete
commands and every environment-specific pitfall already found and fixed.
