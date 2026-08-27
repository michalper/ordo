# API-functional tests

Per Adobe's [contributor guide on automated tests](https://developer.adobe.com/commerce/contributor/guides/code-contributions/automated-tests):
"Web API endpoints must have functional test coverage via api-functional tests. These tests
should ensure that the endpoints behave in accordance to their service contracts regardless of
the actual concrete implementation that may be loaded."

Magento's own `dev/tests/api-functional` suite (`\Magento\TestFramework\TestCase\WebapiAbstract`)
lives inside `magento/magento2-base`'s own test tree, not inside individual modules — it isn't
something a third-party composer package can ship tests into directly. The tests here follow
the same spirit (real HTTP calls against a running instance, asserting on the actual wire
response, no mocks) using a portable, self-contained HTTP client instead, so they run against
*this* module regardless of which Magento install it's dropped into.

## What exists

- **`AbstractApiTestCase.php`** — shared HTTP client: admin/customer token acquisition, REST
  request helper.
- **`CampaignApiTest.php`** — full CRUD round trip (POST create → GET by id → GET list → PUT
  update → DELETE → confirm 404 after).
- **`CampaignConditionActionApiTest.php`** — full CRUD on a campaign's condition/action rows
  (the dynamicRows sections of the admin form), filtered listing by `campaign_id` via
  `searchCriteria`.
- **`OfferApiTest.php`** — POST create → PUT update → DELETE, plus the customer-scoped
  self-extend endpoint (success, max-extensions-exceeded, and wrong-owner cases).
- **`ReorderCycleApiTest.php`** — GET list / GET by id (read-only).
- **`CustomerTagManagementApiTest.php`** — add → get → hasTag → getCustomerIdsWithTag → remove,
  full round trip.
- **`OrderApprovalApiTest.php`** — GET list (admin-scoped, confirms the token field is never
  present in the response), the anonymous approve/reject-by-token endpoints (including
  confirming a second call with the same token is rejected), and `decision-links` (admin
  fetches the approve/reject URLs by entity_id, extracts the token, and actually uses it to
  approve the order — proving the URL is real and usable, not just correctly formatted).

**All of the above were actually run against a live Magento 2.4.7 instance while writing this
pass** (Docker Compose stack: `ordo_test_php` + `ordo_test_db`, PHP built-in server on
`http://php:8080/`), not just written and left unverified. Doing so surfaced and fixed four
real, pre-existing defects that unit tests alone could never have caught, since they only
manifest in Magento's actual WebAPI reflection/serialization layer:

1. **Missing docblocks on service interface methods.** `Api\CustomerTagManagementInterface`'s
   methods had no docblocks at all — Magento's `TypeProcessor` throws
   `InvalidArgumentException: Each method must have a doc block` at request time, which
   `ErrorProcessor` swallows into a generic "There has been an error processing your request"
   page with no useful detail in the response body (only in `var/log/exception.log`).
2. **Missing docblocks on Data interface getters.** `Api\Data\CampaignInterface` and
   `Api\Data\OfferInterface` (pre-existing, not written in this pass) had zero docblocks on
   their getters. `GET /V1/ordo/campaigns/:id` 500'd outright; the two SearchResults-based list
   endpoints ran without erroring but silently serialized every item as `{}` — worse than an
   error, since it looks superficially like "it works, there's just no data".
3. **Generic `SearchResultsInterface` return type on `getList()`.** Even after fixing (2), list
   endpoints still returned empty items — the WebAPI output processor has no way to know a
   plain `SearchResultsInterface` contains `CampaignInterface[]` specifically. Fixed with a
   dedicated `Api\Data\{Entity}SearchResultsInterface extends SearchResultsInterface` per
   entity, each declaring `@return \Ordo\Automation\Api\Data\{Entity}Interface[]` on
   `getItems()`.
4. **Binding a dedicated SearchResults interface straight to the generic
   `Magento\Framework\Api\SearchResults` class in `di.xml`.** Throws a `TypeError` at runtime
   (`Return value must be of type X, Magento\Framework\Api\SearchResults returned`) — PHP's
   return-type covariance requires the actual returned object to implement the narrower
   interface, which the generic class doesn't. Fixed with a one-line concrete subclass per
   entity (`Model/{Entity}/SearchResults.php`), matching Magento core's own pattern
   (`Magento\Catalog\Model\ProductSearchResults`).

See `VERIFICATION.md` for the full list of bugs found and fixed across this project, and
`git log` for the commits that fixed each of the four above.

## Running these tests

They are plain PHPUnit test classes with no special bootstrap requirement beyond a reachable
Magento REST API and admin/customer credentials, configured via environment variables:

```
ORDO_API_BASE_URL=http://php:8080          # no trailing slash
ORDO_API_ADMIN_USERNAME=admin
ORDO_API_ADMIN_PASSWORD=...
ORDO_API_CUSTOMER_EMAIL=...
ORDO_API_CUSTOMER_PASSWORD=...
```

```
vendor/bin/phpunit vendor/ordo/module-automation/Test/Api --bootstrap vendor/autoload.php
```

If the environment variables aren't set, tests `markTestSkipped()` rather than failing — they
need a real, reachable Magento instance and are not meant to run as part of the fast unit suite.
