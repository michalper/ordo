# Roadmap

What's still open — for shipped/stable features see [README.md](README.md), for implementation history/verification
detail see [CHANGELOG.md](docs/CHANGELOG.md) and [VERIFICATION.md](VERIFICATION.md), for the REST API reference see
[API.md](API.md), and the `Test/*/README.md` files.

Ownership split: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is
scoped from real hands-on marketing automation experience.

## Test coverage

- **Ad-audience sync (`Cron\SyncAdAudiences`) has no test against a real Google Ads/Meta account.** Note:
  a since-fixed bug (docs/CHANGELOG.md "Fixed") meant `getGoogleAdsClientSecret()`/`getGoogleAdsRefreshToken()`/
  `getGoogleAdsDeveloperToken()`/`getMetaAccessToken()` returned ciphertext at runtime, not the decrypted
  secret — every real API call would have failed auth regardless of this gap. Same shape as
  `send_sms`'s equivalent gap, already closed (see docs/CHANGELOG.md): unit tests
  (`GoogleAdsSyncClientTest`/`MetaSyncClientTest`/`GoogleOAuthTokenProviderTest`)
  drive the real request-building/response-parsing logic via a fake `Curl`, and the integration test
  (`SyncAdAudiencesTest`) uses real DI/database (real segment/tag/customer rows, real `SegmentMemberResolver`
  query, real `PiiHasher`) but swaps `SyncClientInterface` for a `RecordingSyncClient` — so the actual HTTP
  calls to `googleads.googleapis.com`/`graph.facebook.com` (OAuth token exchange, offline user data job
  lifecycle, Custom Audience creation/replace) have never been exercised against live credentials.
- **`send_whatsapp` / WhatsApp templates have no test against a real Meta WhatsApp Business Account.** Note:
  the same since-fixed bug meant `getWhatsAppAccessToken()`/`getWhatsAppAppSecret()` returned ciphertext at
  runtime — every real Graph API call and every webhook signature check would have failed regardless of this
  gap. Same shape again: unit tests (`WhatsAppSenderTest`/`WhatsAppTemplateClientTest`) drive the real Graph API
  request-building/response-parsing logic via a fake `Curl`, and `WhatsAppSignatureValidatorTest`/`WebhookTest`
  use a real HMAC-SHA256 signature — but template submission (`SubmitForReview`), approval polling
  (`RefreshStatus`), and an actual template message send have never been exercised against a live WABA/phone
  number, and the webhook receiver has never received a genuine callback from Meta.

### MFTF/scenario coverage

Full inventory with what's covered and why: `Test/Mftf/SCENARIOS.md`. Every row there is currently ✅ — no open
gaps. Kept as the standing scope check for anything newly added to the module (new trigger/condition/action/
controller/cron gets a row there before it's considered done).

## Full-codebase improvement audit (2026-09-10)

Five independent passes over the whole module (campaign engine, segmentation/RFM/scoring,
communication channels, commerce features, admin platform/UX/API), each grounded in the actual
code rather than guesswork. Organized by domain below. Tiers 0-4 from the original pass are all
fully closed — see docs/CHANGELOG.md for the full history of each.

### Campaign engine (`Model/CampaignDispatcher.php`, `Model/Queue/*`, Flow canvas)

Flow canvas UX (undo/redo, node duplication, palette search/filter, inline "send test") is now
fully closed — see docs/CHANGELOG.md.

### Commerce features (free gifts, order approval, reorder cycles, GDPR, product feed, dashboard)

*(the "Free Gift never applies to a cart" and "guest checkout bypasses approval" items are listed
as bugs above, not repeated here)*

## Documentation

- **GitHub Wiki covering every feature, bilingual PL/EN, with screenshots.** Published at
  `github.com/michalper/ordo/wiki` — all 8 capability pages plus Home/`_Sidebar`, content verified against the
  actual `Controller`/`Block`/`Model`/`Ui` classes and `view/adminhtml/ui_component/*.xml`, not invented, with real
  screenshots (from a running Magento 2.4.9 admin instance) embedded for every page. `docs/wiki/` in this repo is
  the source copy staged for edits before re-publishing. Still open: native PL review of the drafted text.

## Candidate new features

Not gaps in something existing — genuinely new capabilities, proposed after checking they don't already
exist in some form. Not prioritized against each other; listed for later scoping.

- **Cross-channel fallback for cart abandonment** — if the abandoned-cart email goes unopened for N hours,
  fall back to SMS/WhatsApp instead of adding another email step; reuses the existing `send_sms`/`send_whatsapp`
  actions and `ordo_message_log` open data. Small-medium scope, B2C.
- **B2B: "reorder cycle at risk" segment** — flag customers whose order cadence has drifted meaningfully from
  their own historical cycle (earlier signal than a full reorder-reminder miss), surfaced through the existing
  sales-rep digest. Medium scope, B2B.
- **Per-channel marketing consent** — extend `ConsentManager`/`ordo_customer_consent` from one blanket opt-out to
  granular consent per channel (email/SMS/WhatsApp/push), a common compliance requirement for multi-channel
  automation. Medium scope, shared foundation.
- **A/B testing for campaign variants** — split traffic on a campaign action (e.g. two email variants) and
  auto-pick a winner from `ordo_message_log` CTR data; pairs naturally with predictive send-time optimization.
  Medium-large scope, shared foundation.
- **Local LLM (Ollama) content generation** — a `generate_ai_content` campaign action (or an extension of the
  existing `Add Dynamic Content` action) that calls a self-hosted Ollama instance to personalize email subject/
  body per customer from context already in the dispatch (tags, RFM/CLV, order history) — no data leaves the
  store, consistent with this module's own "no external MA subscription" positioning. Same fail-soft posture as
  every other send action (`Model/Ai/OllamaClient.php` mirroring `Model/Http/JsonApiClient.php`'s shape): a
  timeout or unreachable Ollama falls back to static content rather than blocking the send, and outbound calls
  go through the existing `OutboundRateLimiter` so a queue burst can't flood a local instance. Medium scope,
  shared foundation.

## Priorytet kolejnych kroków

Kolejność uwzględnia wagę (błędy finansowe > dług niezawodności > UX > tematy zależne od
zasobów zewnętrznych):

1. **Nowe funkcje (sekcja "Candidate new features" powyżej)** — do rozważenia razem z
   biznesem/produktem pod kątem priorytetu.
2. **Testy na żywych kontach (Google Ads/Meta/WhatsApp)** — zależne od dostępności realnych
   poświadczeń testowych.
3. **GitHub Wiki (PL/EN, screenshots)** — opublikowane pod `github.com/michalper/ordo/wiki`;
   zostaje tylko recenzja natywna tekstu PL.
