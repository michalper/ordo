# API-functional tests

Per Adobe's [contributor guide on automated tests](https://developer.adobe.com/commerce/contributor/guides/code-contributions/automated-tests):
"Web API endpoints must have functional test coverage via api-functional tests."

Magento's own `dev/tests/api-functional` suite lives inside `magento/magento2-base`, not inside individual
modules, so a third-party package can't ship tests into it directly. These tests follow the same spirit (real
HTTP calls against a running instance, no mocks) via a portable, self-contained HTTP client instead.

## What exists

- `AbstractApiTestCase.php` — shared HTTP client: admin/customer token acquisition, REST request helper.
- `CampaignApiTest.php` — full CRUD round trip.
- `CampaignConditionActionApiTest.php` — CRUD on a campaign's condition/action rows, filtered listing.
- `OfferApiTest.php` — CRUD, plus the customer-scoped self-extend endpoint.
- `ReorderCycleApiTest.php` — GET list / GET by id (read-only).
- `CustomerTagManagementApiTest.php` — add/get/hasTag/getCustomerIdsWithTag/remove round trip.
- `OrderApprovalApiTest.php` — admin list, anonymous approve/reject-by-token, `decision-links`.
- `CreditLimitApiTest.php` — admin by-id lookup, customer-scoped `mine`, auth/authorization edge cases.
- `FreeGiftApiTest.php` — offer/tier/product CRUD, eligibility/selection round trip on a real cart. Requires
  `ORDO_API_TEST_PRODUCT_SKU` (a real, existing SKU). Known flakiness under this project's local `php -S`
  sandbox specifically (rapid back-to-back requests) — not expected against a real server.

## Running these tests

Plain PHPUnit classes, configured via environment variables (see `.env.example` at the module root):

```
ORDO_API_BASE_URL=http://php:8080          # no trailing slash
ORDO_API_ADMIN_USERNAME=admin
ORDO_API_ADMIN_PASSWORD=...
ORDO_API_CUSTOMER_EMAIL=...
ORDO_API_CUSTOMER_PASSWORD=...
ORDO_API_TEST_PRODUCT_SKU=...          # FreeGiftApiTest only
ORDO_API_TEST_APPROVAL_TOKEN=...       # OrderApprovalApiTest only
ORDO_API_TEST_APPROVAL_ENTITY_ID=...   # OrderApprovalApiTest only
```

```bash
vendor/bin/phpunit vendor/michalper/ordo/Test/Api --bootstrap vendor/autoload.php
```

Without the environment variables set, tests `markTestSkipped()` rather than failing — they need a real,
reachable Magento instance and aren't part of the fast unit suite.
