# ADR 0041: TikTok ad-audience sync

## Status

Accepted - the client is implemented, unit-tested, and its request shape/auth placement/error
handling were confirmed with real (unauthenticated) calls against TikTok's actual live endpoints,
which caught two real mistakes a documentation-only implementation would have shipped with (see
Decision). It is still **not yet verified against a real, authenticated TikTok account** (see
Consequences) - the same "implement now, verify once access exists" shape the Data Manager API
migration (ADR 0035) and this feature's own sibling clients went through, not a closed/finished
state.

## Context

`ROADMAP.md` flagged TikTok ad-audience sync as a candidate, with a stated risk: "comparably
strict account-history/spend requirements to Google Ads' Customer Match" - the same wall that
made Google Ads Customer Match a permanent, accepted gap for this project (a real Google Ads
account needs $50k+ lifetime spend before Customer Match list creation is even allowed, confirmed
via Google's own eligibility troubleshooter).

Before writing any code, that risk was checked directly against TikTok's own documentation
(`ads.tiktok.com/help`, `developers.tiktok.com`, and TikTok's own published OpenAPI spec at
`github.com/tiktok/tiktok-business-api-sdk`) rather than assumed from the Google Ads precedent.
**No ad-spend or account-age threshold was found anywhere in TikTok's own documentation.** The
actual gate is different in kind: a discretionary TikTok for Developers app review (Marketing
API, "Audience Management" scope) that TikTok itself describes as judged on "the app's
credibility, benefits to TikTok users, and other factors," taking roughly 2–14 days, with no
guaranteed approval and no quantifiable bar to clear - genuinely different risk from Google Ads'
hard financial wall, closer to "wait and see" than "needs $50k spend we'll never have." Given
that, implementing this now (while the developer application is in review) was judged worthwhile
rather than waiting - unlike the Google Ads/WhatsApp gaps, there's no known reason this one can't
eventually clear.

## Decision

- **A new `Model\AdAudience\TikTokSyncClient implements SyncClientInterface`**, registered as
  `"tiktok"` in `SyncClientPool` (`etc/di.xml`) - `Cron\SyncAdAudiences` needed zero changes, the
  same "add a class, add one di.xml line" shape `SyncClientPool`'s own docblock already promises.
  New `AdAudience::PLATFORM_TIKTOK` const, added to `Model\Config\Source\AdAudiencePlatform`'s
  options - the admin picks a platform per `ordo_ad_audience` row exactly like Google Ads/Meta.
- **Real HTTP calls to TikTok's own documented REST endpoints**, built directly from TikTok's
  published OpenAPI spec files (the `.yml` files in the SDK repo, not third-party blog posts or
  AI-summarized doc pages, several of which turned out to be JS-rendered and unreadable without a
  browser) - same "plain HTTP, no new SDK" choice `GoogleAdsSyncClient`/`MetaSyncClient` already
  made.
- **Two real findings came only from testing live against the actual endpoint (unauthenticated
  calls, before any real account access), not from documentation alone - the published spec
  turned out to be wrong on one of them:**
  - The spec's own `in: query` declaration says `Access-Token` is a URL query parameter. A live
    unauthenticated call with it as a query param got back the exact same error
    (`{"code":40104,"message":"The access_token is empty."}`) as sending no token at all - the
    real endpoint isn't reading it there. Sent as an `Access-Token` *header* instead, the same
    call got a materially different, more specific error (`{"code":40105,"message":"Access token
    is incorrect or has been revoked."}`), proving the header value was actually parsed and
    reached token validation. Confirmed on both the file-upload and the create/update endpoints.
    This class follows the live endpoint's real behavior, not the spec.
  - Every TikTok response here is HTTP 200, success or failure alike - both error responses above
    came back with HTTP 200, confirming real errors only ever show up in the JSON body's own
    `code` field (0 = success). `Model\Http\JsonApiClient::postJson()` only checks HTTP status,
    so `TikTokSyncClient::assertSuccess()` additionally checks the decoded `code` on every call -
    without it, a real TikTok-reported failure would have looked like success to
    `Cron\SyncAdAudiences`.
- **A real two-step flow, unlike Meta's single-audience-id approach**: upload the hashed-email
  file first (`POST .../dmp/custom_audience/file/upload/`, multipart/form-data, returns a
  `file_path`), then create (`.../create/`, first sync) or update with `action: REPLACE`
  (`.../update/`, every later sync) referencing that `file_path`. `action: REPLACE` is TikTok's
  own documented default for "overwrite the whole membership," passed explicitly here rather than
  relied on implicitly, same as this module's own established style elsewhere.
- **The multipart upload is NOT built on `Model\Http\JsonApiClient`** - that class is JSON-only by
  design (its own docblock already explains why `Model\Push\PushSender` isn't built on it
  either, for the same reason: a non-JSON body). Built directly via `CurlFactory`, using
  `setOption(CURLOPT_POSTFIELDS, [...with a real \CURLFile...])` - Magento's own `Curl::post()`
  always runs its `$params` argument through `http_build_query()`, which can't encode a
  `\CURLFile`, but `_curlUserOptions` (set via `setOption()`) is applied *after* and overrides
  that, confirmed by reading `Curl::makeRequest()` itself rather than assumed. The create/update
  calls, which are plain JSON, do reuse `JsonApiClient`.
- **New, dedicated TikTok credentials** (`tiktok_access_token`/`tiktok_advertiser_id`, same
  `ad_audience_sync` config group Google Ads/Meta already use) - same reasoning ADR 0040 already
  gave for the separate GA4/Meta Conversions API credentials: a new integration gets its own
  fields rather than silently assuming an existing token has the right scope.
- **Reuses `Model\AdAudience\PiiHasher::hashEmail()`** unchanged - TikTok's `calculate_type:
  EMAIL_SHA256` wants exactly the same trim-lowercase-SHA256-hex normalization that class's own
  docblock already establishes as shared across Google Ads Customer Match and Meta Custom
  Audiences; this is a third consumer of the same helper, not a new one.

## Consequences

- **Two real implementation details are honestly flagged as not yet confirmed against a live
  account**, both documented directly in `TikTokSyncClient`'s own class docblock rather than
  silently assumed: the exact response field name carrying the new audience id (used as
  `custom_audience_id`, matching the request field's own naming convention and a third-party
  reproduction of the response, since TikTok's own published OpenAPI spec leaves the response
  `data` object's properties formally undocumented), and the exact raw file content format sent
  in the multipart upload (used as one hashed value per line, no header row). Both should be
  confirmed for real the moment TikTok approves the developer application - the same bar every
  other ad-platform integration in this module was held to before being trusted, not skipped
  here just because access isn't available yet.
- TikTok also documents a real operational constraint neither Google Ads nor Meta's own API
  enforces the same way: an `action: REPLACE`/`APPEND`/`REMOVE` update fails outright if the
  audience would end up under 1,000 members afterward. Handled the same way this module handles
  every other sync failure - reported honestly up to `Cron\SyncAdAudiences`, which already
  catches, logs, and records a per-row failure status; no special-casing needed, but a store with
  small segments synced to TikTok specifically may see sync failures the equivalent Google
  Ads/Meta audience wouldn't.
- No ad-spend gap to document as permanent (unlike Google Ads Customer Match/WhatsApp
  verification) - the open item is simply "waiting on TikTok's app review," tracked as an
  ordinary in-progress task, not logged as an accepted gap in `ROADMAP.md`'s "Test coverage"
  section the way those two are.
