# AGENTS.md

Magento 2 module `ordo/module-automation` (marketing automation for Ordo/Sellina): a campaign-scenario builder (triggers → conditions → actions) with a Drawflow-based flow editor.

## Directory structure (contract)

- `docs/CHANGELOG.md` — history of decisions and changes (moved here from `CHANGELOG.md` at the root).
- `docs/adr/` — Architecture Decision Records; decisions you can't reconstruct from the code alone.
- `.env.example` — environment variable contract (today: `Test/Api/*` only, see `Test/Api/README.md`).
- `README.md` / `README.pl.md` — functional description of the module (EN/PL), don't duplicate that content here.
- `ROADMAP.md`, `VERIFICATION.md`, `API.md` — respectively: what's in progress, what's been manually/test-verified, REST API reference.

## How to test changes

This directory is a standalone module repo — there's no `vendor/` here, so tests can't be run directly from it. Unit tests run in a separate Magento environment:

- Test environment: `/Users/michalper/Projects/magento-ordo-test/`
  - `docker-compose.yml` — services `db` (MySQL 8), `opensearch`, `php` (container `ordo_test_php`), `selenium`.
  - `magento/` — a full Magento Open Source 2.4.7 install.
  - This directory (`mma`) is mounted into the php container as `/var/www/mma`.

**Important pitfall:** the module is wired into Magento via a composer path repository with `"options": {"symlink": false}` — meaning Composer **copies** files into `vendor/ordo/module-automation` instead of symlinking. **Changes to files in this repo aren't visible in the test environment until you refresh the copy via `composer update`.**

**Second pitfall:** the module's version is hard-pinned in its `composer.json` (`"version": "1.0.0"`), so plain `composer update ordo/module-automation` sometimes returns "Nothing to modify in lock file" and **doesn't** recopy the new files, because Composer doesn't see a version change. If tests still see the old code after `composer update`, force a reinstall:
```bash
docker compose exec php sh -c "rm -rf vendor/ordo/module-automation && composer update ordo/module-automation"
```

### Commands

```bash
cd /Users/michalper/Projects/magento-ordo-test

# start the environment (if containers aren't running)
docker compose up -d

# after EVERY change to files in mma — refresh the copy in vendor/
docker compose exec php composer update ordo/module-automation

# run this module's unit tests
docker compose exec php vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/ordo/module-automation/Test/Unit

# or the whole Magento unit test suite (the module is included in it automatically)
docker compose exec php vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist
```

The module's tests are part of the standard `Magento_Unit_Tests_Other` test suite defined in `magento/dev/tests/unit/phpunit.xml.dist` (covers `vendor/*/module-*/Test/Unit`) — no extra configuration needed, just an up-to-date copy in `vendor/`.

## Coding standard — fix it locally, not in CI

CI (`ci.yml`, job `coding-standard`) runs two separate tools, both **report-only**, neither auto-fixes:
- `vendor/bin/phpcs` (Magento2 coding standard) — some of its violations are auto-fixable via `phpcbf`, but CI doesn't call it.
- `composer cs-check` (`php-cs-fixer fix --dry-run --diff`) — formatting, `use` ordering, etc.

Both have failed CI after a push (e.g. `$block->escapeHtml` instead of `$escaper->escapeHtml` in a new `.phtml`, import ordering in a new file) — even though both are fully auto-fixable. To stop that from recurring:

**Enable the git hook once per clone:**
```bash
git config core.hooksPath .githooks
```
`.githooks/pre-commit` runs `phpcbf` + `php-cs-fixer fix` on staged `.php` files and re-stages whatever it fixes — no need to remember it on every commit.

**Or manually before pushing:**
```bash
composer cs-fix    # phpcbf + php-cs-fixer fix, fixes in place
composer cs-check  # same thing CI runs — zero output = clean
vendor/bin/phpcs   # separate check, because phpcbf doesn't fix 100% of its own violations
```

## Campaign dispatch is asynchronous (Magento queue)

Triggers (`order_placed`, `customer_registered`, `tag_added`) no longer call `CampaignDispatcher::dispatch()` directly from the observer — they publish a message on the `ordo.automation.campaign.dispatch` topic (`Model/Queue/CampaignDispatchPublisher.php`), consumed by `Model/Queue/CampaignDispatchConsumer.php`. This is so checkout/customer registration doesn't wait on condition/action evaluation.

This test environment **has no RabbitMQ** — Magento uses the default database-backed queue (DB queue driver). The consumer therefore has to actually run in the background, not just let messages pile up in the queue table.

**This is already automated in the test environment, nothing to do manually.** The `php` container (`Dockerfile.php` + `docker/entrypoint.sh` + `supervisord.conf` in `magento-ordo-test/`) starts via `supervisord` instead of `sleep infinity`:
- `entrypoint.sh` appends a `cron_consumers_runner` section with `cron_run => false` to `app/etc/env.php` on every container start (if `env.php` already exists, i.e. Magento is installed).
- `cron_run` is deliberately `false`, because the consumers are **not** run via cron — supervisord keeps them as persistent, long-running processes (`docker/run-consumer.sh ordo.automation.campaign.dispatch` / `...visitor.aggregate`, with `autorestart=true`), so there's no point in cron *also* firing them (it would duplicate the real consumer process and waste cycles).
- `docker/run-magento-cron.sh` separately loops `bin/magento cron:run` every 60s — this handles the module's regular cron jobs (e.g. `Cron\PrunePendingPopups`), independent of the queue consumers.
- All three scripts loop-wait until `bin/magento`/`env.php` actually exist (in case of a freshly built image before Magento is installed), so they safely survive `docker compose up -d --build` against an empty `magento/`.

After a fresh Magento install (`setup:install`/`setup:upgrade`), `docker compose restart php` is enough for the entrypoint to append `cron_consumers_runner` and for supervisord to start keeping the consumers alive. Check `bin/magento queue:consumers:list` — it should show `ordo.automation.campaign.dispatch` and `ordo.automation.visitor.aggregate`.

Incidentally, `docker-compose.yml`'s `db` service now has `--log_bin_trust_function_creators=1` permanently in its `command`, so `SET GLOBAL log_bin_trust_function_creators = 1;` no longer needs to be manually reapplied after every DB container restart before `setup:upgrade`.

`CampaignDispatcher` also caches the "which campaigns are active for a given trigger" lookup (tag cache `CampaignDispatcher::CACHE_TAG`, cleared on campaign/trigger save/delete — see `Controller/Adminhtml/Campaign/Save.php`, `Delete.php`, `CampaignRepository.php`, `CampaignTriggerRepository.php`). If a campaign "doesn't see" a newly changed trigger during manual testing, check first whether the cache actually got cleared (`bin/magento cache:flush` as a workaround if something didn't).

## Integration tests (`Test/Integration/`) — real DI, real database, no mocks

`Test/Unit/` mocks every collaborator — it proves the logic is correct, not that the mechanism actually works as a whole. `Test/Integration/` uses the approach from the `magento-testing:magento-integration-test-lite` skill: a real bootstrap of the fully installed Magento application (`app/bootstrap.php` + `Bootstrap::create`), real DI, a real dev database — without a second install into `dev/tests/integration` and **without transactional rollback**. That means every test cleans up after itself in `tearDown()` (deletes created campaigns/customers/rules/coupons/tags) — if you write a new integration test, add cleanup there, or you'll permanently litter the dev database.

Three files, different scope:
- `CampaignDispatchScenarioTest.php` — the dispatcher engine from every angle: every condition type, every action type (except `send_email`, see below), ANDed conditions, unknown condition/action type (fail-closed), delayed actions + resumption via `Cron\RunScheduledCampaignActions` (rewinds `run_at` instead of waiting real time), campaigns with multiple triggers, the trigger→campaigns cache (proves it's stale-capable and that invalidation works). Calls `CampaignDispatcher::dispatch()` directly — **deliberately bypasses observers and the queue**, to test the engine in isolation from transport.
- `CampaignQueueWiringTest.php` — proves what that file skips: a real Magento event (`customer_register_success`) actually reaches our observer (`etc/events.xml`), the observer actually publishes to the queue (`etc/communication.xml`/`etc/queue*.xml`), and `bin/magento queue:consumers:start ordo.automation.campaign.dispatch --max-messages=1` (run as a real subprocess, since this environment only has DB-queue, not RabbitMQ) actually consumes the message and triggers dispatch.
- `CampaignSendEmailActionTest.php` — the one place where something is faked: `SendEmail` calls a real `TransportBuilder`, which would actually send an email / require a registered email template. Only the tail end, `TransportBuilder::getTransport()`, is swapped out (the `RecordingTransportBuilder` class in the same file) — the rest of the dependencies (`CustomerRepositoryInterface`, `StoreManagerInterface`) are real.
- `CampaignVisitorPopupScenarioTest.php` — the anonymous visitor path (Phase 5/7): real aggregation of `ordo_visitor_event` → `ordo_visitor_tag` without login, real dispatch of the `visitor_tag_added` trigger, real `visitor_tag` condition, real `popup` action → a real `ordo_pending_popup` row, plus a test against the real database that `UPDATE ... WHERE delivered_at IS NULL` actually prevents double delivery (not a mocked SQL builder).

### Running them

**Important since the supervisord consumer automation (see the section above): stop both persistent consumers before running integration tests, or the tests will hang forever.** `CampaignQueueWiringTest` and `CampaignVisitorPopupScenarioTest::drainPendingMessages()` publish a message and then start `bin/magento queue:consumers:start <topic> --max-messages=1` themselves as a subprocess, waiting for it to consume *that specific* message. If a persistent supervisord consumer (`consumer-campaign-dispatch`/`consumer-visitor-aggregate`) is also running in the background, it eats the message first — the test's subprocess then waits for a message that's already gone, and hangs indefinitely (encountered and fixed: two hung `phpunit` runs had to be manually killed with `kill -9` by PID from `/proc/[0-9]*/cmdline`, since `ps`/`pkill` aren't in this image).

```bash
cd /Users/michalper/Projects/magento-ordo-test
docker compose up -d
docker compose exec php sh -c "rm -rf vendor/ordo/module-automation && composer update ordo/module-automation"

# stop the persistent consumers while running integration tests
docker compose exec php supervisorctl stop consumer-campaign-dispatch consumer-visitor-aggregate

# from the Magento directory (not the module!) — requires --bootstrap app/bootstrap.php, otherwise BP doesn't exist
docker compose exec php vendor/bin/phpunit --bootstrap app/bootstrap.php \
    vendor/ordo/module-automation/Test/Integration

# start them back up after the tests
docker compose exec php supervisorctl start consumer-campaign-dispatch consumer-visitor-aggregate
```

Requires this environment's working `app/etc/env.php` (database, cache) — this isn't stateless, the tests actually connect to the dev database. `CampaignQueueWiringTest` additionally runs `bin/magento` as a subprocess (`exec()`), so PHP in the container needs permission to run shell commands.

## MFTF — from saving a trigger to a real effect

- `AdminCreateMultiTriggerCampaignTest.xml` — triggers only (multi-trigger support).
- `AdminCreateCampaignWithConditionsAndActionsTest.xml` — same, for conditions and actions (previously untested in MFTF).
- `AdminCampaignScenarioEndToEndTest.xml` — **the only test in the whole module that answers "does this actually make sense and work" with no shortcuts**: builds a scenario in the admin (trigger=`order_placed`, condition=`order_total_gte`, action=`generate_coupon` — chosen because it has real, visible UI in the admin; `add_tag`/`send_email` have no grid at all), a real customer performs a real storefront checkout, `queue:consumers:start ordo.automation.campaign.dispatch --max-messages=1` processes the message (again: no RabbitMQ in this environment), and finally checks in Marketing → Cart Price Rules → Manage Coupon Codes that the coupon **is actually there**.

### Running MFTF in this environment — real pitfalls, all encountered and fixed

This environment **has no real webserver** — `docker-compose.yml`'s `php` service runs `command: sleep infinity`, so someone (us) has to manually start the PHP built-in server before every `mftf run:test`. Pitfalls encountered and fixed, in order of discovery:

1. **No server at all** → `curl` to `localhost:8080` from the php container got "connection refused". Start it manually:
   ```bash
   docker compose exec -d -e PHP_CLI_SERVER_WORKERS=8 php sh -c "cd /var/www/magento/dev/tests/acceptance/utils && exec php -S 0.0.0.0:8080 -t /var/www/magento/pub /tmp/router.php"
   ```
   **`PHP_CLI_SERVER_WORKERS=8` is mandatory** — without it the PHP built-in server handles one request at a time, while Selenium/Chrome fires many parallel requests (JS/CSS/AJAX) — without workers the admin page renders with truncated assets (`Uncaught SyntaxError`, RequireJS `Script error`) or hangs for a 60s timeout.
   **Check that the port actually freed up before restarting** — `docker compose exec` doesn't kill the old process automatically; if `php -S` is already listening on 8080, the next run silently fails to bind, and the OLD process (with a different config) keeps serving traffic, which looks like "my change didn't work". Kill it manually by scanning `/proc/[0-9]*/cmdline` (no `pgrep`/`ps` in this image) before starting a new one.
2. **Static assets generated on the fly** (`app mode: default`) make problem #1 worse even with workers — every request to an undeployed file under `pub/static/` triggers LESS/JS compilation. Deploy once at environment startup:
   ```bash
   bin/magento setup:static-content:deploy -f en_US -a adminhtml
   bin/magento setup:static-content:deploy -f en_US -a frontend
   ```
3. **`<magentoCLI>` in MFTF tests returned HTTP 404** — `.env` had no `MAGENTO_CLI_COMMAND_PATH`/`MAGENTO_CLI_COMMAND_PARAMETER` at all (without them MFTF POSTs to an empty base URL). Added to `dev/tests/acceptance/.env`:
   ```
   MAGENTO_CLI_COMMAND_PATH=cli-bridge/a/b/command.php
   MAGENTO_CLI_COMMAND_PARAMETER=command
   ```
   **This path MUST be exactly 3 directories deep under `pub/`** — `dev/tests/acceptance/utils/command.php` computes `bin/magento` as `../../../../bin/magento` (4×`../`) **relative to the CWD, which the PHP built-in server sets to docroot + the requested URL's directory**, not to the file's actual location (even if the file is a symlink, even if the server started with a different CWD). With docroot=`pub/`, 3 levels of nesting + 4×`../` lands exactly on Magento's root directory. The symlink has to be created manually (it's not in git, since it lives under the test environment's `pub/`, not in the module repo):
   ```bash
   mkdir -p /var/www/magento/pub/cli-bridge/a/b
   ln -sf /var/www/magento/dev/tests/acceptance/utils/command.php /var/www/magento/pub/cli-bridge/a/b/command.php
   ```
4. **"Update by Schedule" indexers hide freshly created test data** — a product created via `<createData entity="SimpleProduct2">` isn't "salable"/addable to cart until the stock/price indexers recalculate. Instead of forcing `indexer:reindex` in every test (which additionally fails the whole step if OpenSearch happens to be down, since `catalogsearch_fulltext` cascades), set this once globally:
   ```bash
   bin/magento indexer:set-mode realtime cataloginventory_stock catalog_product_price catalog_product_attribute catalog_category_product catalog_product_category
   ```
   (deliberately WITHOUT `catalogsearch_fulltext` — this test doesn't need it, and it requires a live OpenSearch).

**Known, unresolved issue: random `tab crashed` / `Operation timed out` in Chrome/Selenium.** This host has a lot of other, unrelated Docker projects installed (visible in `docker stats` — one container alone took 66% of Docker's memory limit), so `db`/`opensearch` regularly get OOM-killed (exit 137), and Selenium's Chrome sessions crash at a random, unpredictable point in the test (sometimes on the first step, sometimes right before the finish line). This is **not a bug in the module's code or in the test itself** — `AdminCreateMultiTriggerCampaignTest` and `AdminCreateCampaignWithConditionsAndActionsTest` (purely admin-side, shorter) pass reliably after these fixes; `AdminCampaignScenarioEndToEndTest` (long, real checkout) got further with each successive attempt (as far as selecting the `generate_coupon` action) without a single real logic error, but never fully completed due to host memory exhaustion. To actually finish verifying this test: either free up host memory (stop other, currently unneeded Docker projects), or run it in a less loaded environment/CI.

Running it:
```bash
docker compose exec php vendor/bin/mftf generate:tests AdminCampaignScenarioEndToEndTest AdminCreateCampaignWithConditionsAndActionsTest AdminCreateMultiTriggerCampaignTest
docker compose exec php vendor/bin/mftf run:test AdminCampaignScenarioEndToEndTest AdminCreateCampaignWithConditionsAndActionsTest AdminCreateMultiTriggerCampaignTest
```

### MFTF in real CI (GitHub Actions, `.github/workflows/mftf.yml`) — different pitfalls than the local sandbox

This workflow has its own, real nginx+PHP-FPM stack (not `php -S`) — so the local pitfalls above (workers, cli-bridge symlink, indexers) are already solved differently there and baked permanently into the workflow. The following three turned out to be specific to a real, fresh `setup:install` on GitHub Actions and had no equivalent in this sandbox — each has a full explanation as a comment at the relevant step in `mftf.yml`, here's just the summary:

1. **"Add Secret Key to URLs" (`admin/security/use_form_key`) is ON by default** on a fresh Magento (`vendor/magento/module-store/etc/config.xml`) — every `amOnPage("admin/...")` without a secret `?key=` in the URL (i.e. every literal navigation in an MFTF test, as opposed to clicking a link Magento rendered) got a silent 302 to the Dashboard, no error message. Tracked down via a curl-repro step in the workflow (the `Location:` header contained `/key/<hash>/`). Fixed: `bin/magento config:set admin/security/use_form_key 0` in the "Install Magento" step.
2. **`<valueMap>` directly in `<field><settings>` and `<settings><componentType>container</componentType></settings>` in `<container name="record">` break the XSD** (`Magento_Ui:etc/ui_configuration.xsd`) — Magento returns this as a 500 (`LocalizedException`, "The XML ... is invalid"), not as something visible in the browser/Selenium. `valueMap` is only allowed under `<formElements><checkbox><settings>`; `<container>` passes straight from `<argument>` to `<field>` children, with no `<settings>` of its own — verified against real core examples (`module-backend`'s `design_config_form.xml`).
3. **`sendmail_path` with an unquoted value containing `=` gets truncated by PHP's INI parser** at the first internal `=` — `mhsendmail --smtp-addr=127.0.0.1:1025` in practice reached PHP as a bare `mhsendmail --smtp-addr` (no address, no further flags), which also broke Symfony Mailer's `SendmailTransport` (requires literal ` -bs` or ` -t` in the flags). Fixed by quoting the whole value: `sendmail_path = "/usr/local/bin/mhsendmail --smtp-addr=127.0.0.1:1025 -t"`.
4. **`queue:consumers:start --max-messages=N` BLOCKS by default** (`CallbackInvoker::invoke()`, `sleep(1)` in a loop, `maxIdleTime` defaults to `PHP_INT_MAX`) until N messages show up in the queue — it doesn't return early just because no more are coming. A test asking for more messages than are actually queued (e.g. to "drain" a leftover message from another test in the same job — there's no consumer running in the background between MFTF tests here) hangs until the HTTP CLI-bridge (magentoCLI goes through `command.php` under nginx+PHP-FPM, not directly) gets a 504 from the proxy. Fixed once, globally: `bin/magento setup:config:set --consumers-wait-for-messages=0 -n` in the "Install Magento" step (`Magento\MessageQueue\Setup\ConfigOptionsList` — this is deployment config in `env.php`, not a regular `config:set`) — the consumer then returns once the queue is empty instead of waiting.
5. **`customer_save_after`'s `getCustomer()` does NOT guarantee `CustomerInterface`** — in practice (confirmed by a real TypeError in the log) it's always the legacy `Magento\Customer\Model\Customer` (actually its `\Interceptor`), which extends `AbstractModel`/`DataObject` and does not implement `Api\Data\CustomerInterface`. Type-hinting `CustomerInterface $customer` against that object throws a `TypeError` at runtime (`Observer\EvaluateCustomerScoreRules`, see commit). Safe workaround: don't trust the event's type, fetch a real `CustomerInterface` via `CustomerRepositoryInterface::getById((int) $eventCustomer->getId())`.
