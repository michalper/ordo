# ADR 0035: `Model\AdAudience\GoogleAdsSyncClient` migrated to the Data Manager API

## Status

Accepted.

## Context

`ROADMAP.md`'s "Test coverage" section had long flagged `Cron\SyncAdAudiences` as never verified
against a real Google Ads/Meta account. Attempting that verification (a real Google Ads account,
a real Developer Token, a real Customer Match list) surfaced that the client's entire integration
target — the Google Ads API's `OfflineUserDataJobService` — has moved out from under it since
this code was written, in ways a routine read of this module's own code would never surface.
Checked against the current, authoritative Google documentation and the real PHP client
libraries (not assumed), not just the code as it stood.

## Findings, in the order they were found

1. **`API_VERSION = 'v17'` is long sunset.** Google Ads API's own release-notes page only lists
   v23 through v26.1 (current as of this ADR) as supported; v17 predates all of them. Confirmed
   the REST request/response *shape* for `OfflineUserDataJobService` hadn't changed between v17
   and v26 — this alone would have been a one-line fix.
2. **`AddOfflineUserDataJobOperations` never set `enablePartialFailure: true`.** The REST
   reference says `partialFailureError` is only populated when partial failure mode is requested.
   Without it, the code's own `isset($body['partialFailureError'])` check was dead — a rejected
   identifier wouldn't have been caught the way the surrounding comment claimed.
3. **`RunOfflineUserDataJob` is a long-running operation, fired and forgotten.** The REST
   reference says explicitly: "When finished, the long running operation will contain the
   processing result or failure information, if any." The old code discarded that response
   entirely (`void`) and `Cron\SyncAdAudiences` recorded `STATUS_SUCCESS` the instant the job was
   *accepted*, never checking whether it actually *completed* — and completion is documented to
   take "10 minutes to 24 hours," ruling out a simple synchronous poll-with-timeout fix.
4. **The actual blocker: `OfflineUserDataJobService`/`UserDataService` Customer Match requests
   fail outright for a project with no Customer Match history in a specific historical window**
   (per Google's own Customer Match getting-started guide: `CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE`
   for any Google Cloud project without Customer Match API traffic between 2025-10-01 and
   2026-03-31). A fresh Google Ads account/project — exactly what verifying this against a real
   account meant setting up — cannot use this service at all anymore, version bump or not.
   Google's own guidance for every *new* integration is to use the separate **Data Manager API**
   instead.

## Decision

- **Migrated `GoogleAdsSyncClient` to the Data Manager API's `IngestionService.ingestAudienceMembers`**
  (`POST https://datamanager.googleapis.com/v1/audienceMembers:ingest`), replacing the
  3-step create/addOperations/run job dance with one synchronous call. This isn't just a forced
  move — it's a genuine simplification: a 2xx response is the real outcome, not an acceptance
  receipt, so finding #3 above is structurally eliminated rather than worked around.
  `MAX_MEMBERS_PER_BATCH` (10,000) and the per-batch chunking are unchanged — Data Manager API
  documents the exact same limit.
- **Still plain HTTP, not the official `googleads/data-manager` PHP client**, for two reasons
  checked directly rather than assumed: (a) same "no new SDK for one feature" principle this
  class already applied against `googleads/google-ads-php` (confirmed via its own `composer.json`:
  depends on `google/gax`, requires the `ext-grpc` PECL extension, a real hosting-environment
  burden for every Magento install this module touches); (b) `googleads/data-manager`
  itself — while REST-capable, `ext-grpc` only *suggested*, not required — states plainly in its
  own README that it's "considered alpha… more likely to get backwards-incompatible updates," an
  unacceptable foundation for code shipped to other people's production stores. The REST shape
  (fetched from the live Discovery document, not a cached doc page) is stable and fully
  documented independent of that client.
- **New OAuth scope, new config fields.** Data Manager API requires
  `https://www.googleapis.com/auth/datamanager`, a different scope than Google Ads API ever
  needed — an old `google_ads_refresh_token` doesn't carry it; a fresh consent grant is required
  regardless of what this ADR does. Renamed the OAuth config fields from `google_ads_*` to
  `data_manager_*` to match (this module has no real production installs yet — see `CLAUDE.md` —
  so a clean rename costs nothing). `google_ads_developer_token` is removed outright: Data
  Manager API has no developer-token concept at all. `google_ads_login_customer_id` is renamed to
  `google_ads_account_id` and now maps directly to `Destination.operatingAccount.accountId` —
  the old code's own single-account usage (never actually modeling a separate MCC login account)
  carries over unchanged, just renamed for honesty about what the value means.
- **`external_audience_id` accepts both the new bare numeric id Data Manager API wants and the
  old `customers/{id}/userLists/{id}` resource-name shape** (extracts the trailing segment) — a
  value an admin already entered before this migration keeps working without a forced re-entry.

## Consequences

- Testing this against a real account is unblocked for the *code* side, but still needs: a fresh
  OAuth consent for the new scope, and Google's own Customer Match terms-of-service acceptance
  (`termsOfService.customerMatchTermsOfServiceStatus: ACCEPTED`, sent unconditionally — the same
  implicit-consent assumption the old code already made by calling the API at all once configured
  and enabled) at the account level.
- `Model\AdAudience\PiiHasher` is unaffected — it already hashes to lowercase SHA-256 hex, which
  is exactly what `IngestAudienceMembersRequest.encoding: HEX` declares.
- `Model\AdAudience\MetaSyncClient` is unaffected — this migration is Google-side only.
- `ROADMAP.md`'s "no test against a real account" gap for `Cron\SyncAdAudiences` stays open for
  the same reason it always was (needs the user's own real Google Ads account/OAuth consent/
  Customer Match ToS acceptance), but the code itself is no longer the reason a real test would
  fail — three independent, real correctness gaps are fixed, not just the version number that
  prompted this investigation.
