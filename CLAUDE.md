# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

See `AGENTS.md` for the full test-environment setup (the `magento-ordo-test/` Docker sandbox, queue
consumers, MFTF pitfalls, CI/auto-merge policy) — this file doesn't repeat that, only adds what's missing
from it.

## What this is

`michalper/ordo` — a Magento 2 module (`Ordo_Automation`) providing marketing automation for Magento Open
Source: a campaign-scenario engine (trigger → conditions → actions) plus B2B/B2C features built on it
(reorder reminders, abandoned cart, order approval, credit limits, SMS/WhatsApp/push, ad-audience sync,
segmentation, lead scoring/RFM). See `README.md` for the full feature list.

This repo is **module source only** — no `vendor/`, not a runnable Magento install. It's consumed by a
real Magento instance via a Composer path repository (`"options": {"symlink": false}`, so changes here are
*copied*, not symlinked — see `AGENTS.md`/`CONTRIBUTING.md` for the `composer update` refresh step). PHP
`>=8.4 <8.6`, targets Magento 2.4.8/2.4.9.

## Architecture decisions

When a change picks one approach over a real alternative, deviates from an existing pattern, or
accepts a known trade-off, add an ADR under `docs/adr/` in the same PR — see "When to write an ADR"
in `AGENTS.md`.

## Commands

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

# Dead code / PHP 8.4 modernization checks (report-only, deliberately skips Api/ — see rector.php)
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

## Architecture

Full directory/class map: `docs/ARCHITECTURE.md`. What follows is the part that isn't obvious from any
single file.

**Campaign engine — the core abstraction everything else plugs into.** A campaign is trigger(s) →
condition(s) → action(s). Conditions and actions are *not* hardcoded: each type is a `di.xml`-registered
class implementing `Api/Campaign/ConditionInterface` / `ActionInterface`, collected into
`Model/Campaign/ConditionPool` / `ActionPool`. Adding a new condition/action type means adding a class and
a `di.xml` entry, not touching the dispatcher. `Model/CampaignDispatcher` is the runtime entry point:
trigger name + context in, it looks up which campaigns listen for that trigger (cached — see AGENTS.md's
cache-invalidation note), evaluates conditions (AND-joined, with one level of `{"logic": "all"|"any"}`
group nesting), and runs actions. Conditions can defer an action (`deferActionUntil()` — quiet hours,
`delay_minutes` chaining, send-time optimization) rather than running it inline; `Cron/RunScheduledCampaignActions`
resumes those later. An unknown condition/action type fails closed (campaign doesn't run), not open.

**Triggers publish, don't call.** Observers (`Observer/Dispatch*Campaigns.php`) don't call
`CampaignDispatcher::dispatch()` directly — they publish to a Magento queue topic
(`Model/Queue/CampaignDispatchPublisher`), consumed asynchronously by `Model/Queue/CampaignDispatchConsumer`,
so request-path code (checkout, registration) never waits on condition/action evaluation. See AGENTS.md for
how this works with no RabbitMQ in the dev sandbox (DB-backed queue, supervisord-managed consumers).

**Segmentation reuses the campaign engine's own matching logic.** `Model/Segment` conditions are the same
flat-list-plus-one-level-of-nested-group shape as campaign conditions, and `Model/Segment/SegmentMatcher`
recurses into nested groups the same way `CampaignDispatcher` does — a segment is conceptually "a saved
condition set with no trigger/action," not a separate engine.

**Everything is a cron-driven scan against `*_at` claim columns, not a live event, when the source data
isn't a real Magento event.** Reorder reminders, abandoned cart, offer expiry, credit limit alerts,
price-drop/back-in-stock, win-back — all periodic crons scanning for rows crossing a threshold, claiming
them (setting a `notified_at`/`run_at` column) before dispatch to avoid double-sends, same shape across every
feature. On-site behavior tracking (`Controller/Track/Event` → `VisitorEventLogger` → `VisitorAggregator`)
uses the same anonymous-then-stitched-to-customer identity model as Web Push subscriptions and price-watch
subscriptions (`StitchVisitorIdentity` observer attributes pre-login activity to the customer on login).

**Everything is config-gated per feature.** Every feature has its own toggle under Stores → Configuration
→ Ordo Automation (`Helper/Config`) and its own cron job — there's no single master switch, and reading one
feature's behavior means checking its own config keys, not a shared flag.

**`Api/` is the REST service-contract boundary** (`etc/webapi.xml`), kept deliberately out of
`rector.php`'s scope because Magento's WebAPI reflection needs explicit `@return`/`@param` PHPDoc even
where native types are already declared (see comment in `rector.php` — this bit CI once).
