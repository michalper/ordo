# ADR 0073: MFTF test helpers resolve their DB/MailHog connection from the live install, not a hardcoded 127.0.0.1

## Status

Accepted.

## Context

23 of `Test/Mftf/Helper/*.php`'s classes open a direct `new \PDO(...)` connection (bypassing
Magento entirely) to seed/assert fixture rows for tables with "no MFTF-reachable path" - same
reasoning repeated in each one's own docblock. Every one of them defaulted to
`dbHost='127.0.0.1', dbName='magento', dbUser='root', dbPassword=''`, and `MailHogHelper.php`
defaulted its own HTTP API base to `http://127.0.0.1:8025`. No `Test/Mftf/Test/*.xml` ever
overrode any of these defaults.

Adobe Commerce Marketplace's technical-review harness (`docker-compose-mftf.yml`, confirmed via
its own downloaded command/output transcripts) runs a real multi-container Compose stack with
separate `build`/`deploy`/`test`/db services. `127.0.0.1` inside the `test` container (where these
Helper classes execute) is that container itself, never the database or MailHog - every one of
these calls failed there with `SQLSTATE[HY000] [2002] Connection refused` (DB) or `Could not reach
MailHog at http://127.0.0.1:8025`, which is exactly what the review's own "MFTF Vendor Supplied"
test report showed (confirmed failing as far back as v1.2.0's review, 22 Oct 2026 - this was never
a regression, just never blocking, since `Manual QA` - not this automated report - gates the
overall technical-review result).

The 127.0.0.1 default was never actually correct by some universal assumption; it happened to
work in the two environments this module had been exercised in before:
- the local `magento-ordo-test/` sandbox, where the `db` container's `network_mode: "service:php"`
  shares `php`'s network namespace, so `127.0.0.1` from inside `php` does reach `db` - confirmed via
  a direct check: `app/etc/env.php`'s actual configured host there is `db` (not `127.0.0.1`), user
  `magento` (not `root`), so even here the hardcoded default was only an accident of that specific
  network trick, not a value that matched the real config;
- GitHub Actions, where a `services:` container is auto-mapped onto `127.0.0.1` for the job by
  GitHub Actions itself, and this module sets no SMTP config of its own (`sendmail`/`mhsendmail`
  routes mail into MailHog's SMTP port directly, independent of `system/smtp/host`).

## Decision

Added `Test/Mftf/Helper/DbConnectionTrait.php`, composed into every Helper class that previously
built its own PDO connection (no shared base class exists here - same "trait over base class"
reasoning as ADR 0067/0070/0072, these are `Helper` subclasses MFTF instantiates itself with no
constructor dependencies to inject):

- `resolveDbConnection(): \PDO` reads the real connection the already-deployed Magento instance
  under test is using, straight from its own `app/etc/env.php` (`db.connection.default`) - correct
  in any environment by construction, since it's the same file Magento itself connects with. The
  Magento root is found by walking up from `__DIR__` looking for `app/etc/env.php`, rather than
  assuming a fixed `vendor/<vendor>/<package>` depth, so it isn't tied to how this module happens
  to be installed.
- `resolveMailHogBaseUrl(): string` reads `system/smtp/host` back out of `core_config_data` (the
  review harness explicitly points Magento's own SMTP transport at the real Compose service name,
  `mailhog`, via its own `magento-command config:set system/smtp/host mailhog` - MailHog's HTTP API
  always lives on that same host, just port 8025 instead of 1025).
- Both fall back to the historical `127.0.0.1`-based defaults if `env.php` can't be found/read, or
  if `system/smtp/host` is empty - so behavior in the sandbox and in CI, where the old default
  happens to produce a working connection, is unchanged.

Every one of the 23 PDO-based helpers' `$dbHost`/`$dbName`/`$dbUser`/$dbPassword` parameters (and
`MailHogHelper`'s own `$mailhogUrl`) were removed rather than kept as now-pointless overridable
defaults, since nothing ever passed them and a parameter nobody can meaningfully set is dead
weight, not flexibility.

An alternative considered: route each of these through a small module-owned `bin/magento` CLI
command, invoked via MFTF's built-in `<magentoCLI>` action instead of a `Helper` method - that
goes through Magento's own `ResourceConnection`, with zero connection-string guessing at all.
Rejected for this fix: it would mean a new `Console/Command` class plus `di.xml` entry per helper
method (dozens), and rewriting every affected `Test/Mftf/Test/*.xml` to call `<magentoCLI>` instead
of `<helper>` - a much larger surface for what is, underneath, the same information
(`app/etc/env.php`'s own connection block) a `<magentoCLI>`-invoked command would have had to read
via the exact same `ResourceConnection` config either way.

## Consequences

- Confirmed working end-to-end in the local sandbox: a direct Reflection-based call to
  `resolveDbCredentials()` there returns the real `host=db, dbname=magento, username=magento,
  password=magento` (not the old hardcoded root/no-password guess) and a real `PDO` query against
  it succeeds; `AdminCronRunLogGridTest` (uses `CronRunLogTestHelper`) and
  `AdminAddProductRecommendationsActionTest` (uses `GdprTestHelper`) both get past the point that
  previously threw `Connection refused`/masked-by-cleanup errors.
- No `Test/Mftf/Test/*.xml` changes needed - confirmed none ever passed these parameters.
- `MailHogHelper::fetchMessages()` and its four public callers lose their `$mailhogUrl` parameter;
  two helpers' now-pointless `private function connect(...)` (`PriceWatchTestHelper`) wrapper and
  unused `use PDO;` imports (`PushSubscriptionTestHelper`, `SplitVariantTestHelper`) were removed
  along with it.
- Doesn't touch anything outside `Test/Mftf/Helper/` - the actual tables/assertions each helper
  reads or writes are unchanged.
