# GraphQL e2e tests

Real HTTP calls against a running instance's own `/graphql` endpoint, no mocks — same portable,
self-contained-HTTP-client shape as `Test/Api/` (see that package's own `README.md` for why this
isn't Magento's `dev/tests/api-functional`), not MFTF: a pure API protocol has nothing
browser-observable for Selenium to assert on.

## What exists

- `AbstractGraphQlTestCase.php` — shared HTTP client: customer token acquisition (reuses
  `Test/Api`'s own REST token endpoint — GraphQL and REST share the same customer-token auth),
  GraphQL request helper.
- `TrackingApiTest.php` — Phase 1 (`ROADMAP.md`): `ordoTrackerPoll`, `trackOrdoEvent`,
  `dismissOrdoNotification`, `submitOrdoSurveyResponse`. Deliberately only exercises paths that
  don't need a pre-seeded fixture row — no mutation exists to create a popup/notification/survey
  prompt (those are campaign-engine-produced, not customer-facing), so a nonexistent id exercises
  the same "not found" branch a real one that's already been acted on would.

## Running these tests

Plain PHPUnit classes, configured via the same environment variables `Test/Api/` already uses
(see `.env.example` at the module root — no new variables needed):

```
ORDO_API_BASE_URL=http://php:8080          # no trailing slash
ORDO_API_CUSTOMER_EMAIL=...
ORDO_API_CUSTOMER_PASSWORD=...
```

```bash
vendor/bin/phpunit vendor/michalper/ordo/Test/GraphQl --bootstrap vendor/autoload.php
```
