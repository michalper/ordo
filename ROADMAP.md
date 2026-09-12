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

### Communication channels (Email/SMS/WhatsApp/Push)

- Every send is still one synchronous, unbatched HTTP call per customer inline in the dispatch
  path — no concurrency control (client-side pacing per provider now exists, see docs/CHANGELOG.md
  — that's throttling one process's own call rate, not coordinating concurrency across multiple
  queue consumers/cron processes hitting the same provider at once).
- `send_push`'s per-subscription sends still have no persisted retry once `SendRetrier`'s 3
  in-process attempts are exhausted (`send_email`/`send_sms`/`send_whatsapp` now do, see
  docs/CHANGELOG.md) — a whole-action retry would risk re-sending to subscriptions that already
  succeeded the first time; a per-subscription retry queue would be needed to close this safely,
  not attempted yet.

### Commerce features (free gifts, order approval, reorder cycles, GDPR, product feed, dashboard)

*(the "Free Gift never applies to a cart" and "guest checkout bypasses approval" items are listed
as bugs above, not repeated here)*

- Product feed is still single-format (Google RSS only) — multi-store and health/history are now
  covered (see docs/CHANGELOG.md). A second feed format (beyond Google Merchant's RSS+g: namespace)
  is a bigger, separate abstraction.

## Localization

- **Native-speaker review of the 10 machine-translated locales** (`de_DE`, `fr_FR`, `es_ES`, `it_IT`, `pt_BR`,
  `zh_Hans_CN`, `ja_JP`, `ru_RU`, `uk_UA`, `nl_NL`) — shipped as a machine-translated first pass (see
  docs/CHANGELOG.md),
  not yet signed off by a human reviewer per locale. Highest priority: launch-blocking strings (error messages,
  delete confirmations) over descriptive/help text.

## Documentation

- **GitHub Wiki covering every feature, bilingual PL/EN, with screenshots.** Structure decided (bilingual-per-page,
  see `docs/wiki/README.md` for the reasoning) and all 8 capability pages plus a Home/`_Sidebar` drafted in
  `docs/wiki/` — content verified against the actual `Controller`/`Block`/`Model`/`Ui` classes and
  `view/adminhtml/ui_component/*.xml`, not invented. Real screenshots (from a running Magento 2.4.9 admin instance)
  are embedded for the dashboard, campaigns grid, segments grid, RFM report, score rules grid, free gift offers grid
  + tier-editing form, order approvals grid, reorder cycles grid, and the tracking configuration screen. Still
  needed: the campaign builder's Flow/Drawflow canvas screenshot (blocked by a pre-existing DI error in the test
  environment — `Controller/Adminhtml/Campaign/Edit` currently throws a circular-dependency `LogicException`,
  unrelated to this doc pass), the segment edit form screenshot, and native PL review of all the drafted text
  (same caveat as the machine-translated locale CSVs above). Not yet copied into the real GitHub Wiki
  (`github.com/michalper/ordo.wiki`) — `docs/wiki/` is staging content for manual review first.

## Candidate new features

Not gaps in something existing — genuinely new capabilities, proposed after checking they don't already
exist in some form. Not prioritized against each other; listed for later scoping.

- **Customer Lifetime Value (CLV) scoring** — a forward-looking counterpart to the existing RFM
  (`Model/Rfm/RfmCalculator.php`), exposed as a new condition/segment criterion and dashboard KPI. Medium
  scope, fits both B2B and B2C.
- **Price-drop & back-in-stock alerts** — customer/visitor opt-in on PDP, a cron watching catalog
  price/stock changes, two new trigger types through the existing `CampaignDispatcher` pipeline.
  Medium-large scope, primarily B2C.
- **Generic outbound webhook action + inbound webhook trigger** — a `send_webhook` action and a signed
  `webhook_received` trigger, giving external systems (ERP/CRM/PIM) a two-way integration point beyond
  today's provider-status-only inbound webhooks. Medium scope, higher value for B2B (ERP/CRM integration).
- **Predictive send-time optimization** — pick each customer's historically best send hour from existing
  `ordo_message_log` open/click data and hold the action via the existing delayed-action/resume mechanism
  (`Cron\RunScheduledCampaignActions`) instead of a fixed delay. Medium scope, fits both.
- **Two-way SMS/WhatsApp conversation handling** — capture inbound replies (today only delivery-status
  callbacks are handled), with mandatory keyword-based opt-out (STOP) and a conversation view tied to the
  customer record. Large scope (compliance-sensitive once inbound is accepted at all), fits both.
- **Multi-touch attribution / revenue-per-campaign reporting** — correlate `ordo_message_log`
  click-throughs to subsequent orders within a configurable window, surfaced as attributed revenue on the
  Campaign grid/dashboard. Medium scope, fits both.

## Priorytet kolejnych kroków

Kolejność uwzględnia wagę (błędy finansowe > dług niezawodności > UX > tematy zależne od
zasobów zewnętrznych):

1. **Nowe funkcje (sekcja "Candidate new features" powyżej)** — do rozważenia razem z
   biznesem/produktem pod kątem priorytetu; webhook action/trigger wygląda na najmniejszy koszt
   wejścia względem wartości.
2. **Drugi format product feedu** — większa, osobna abstrakcja; wymaga wyboru formatu
   docelowego przed implementacją.
3. **Testy na żywych kontach (Google Ads/Meta/WhatsApp)** — zależne od dostępności realnych
   poświadczeń testowych.
4. **Recenzja natywna 10 lokalizacji** — zależna od dostępności recenzentów per język.
5. **GitHub Wiki (PL/EN, screenshots)** — struktura ustalona, treść wszystkich 8 stron
   naszkicowana w `docs/wiki/`; zostały zrzuty ekranu kanwy Flow i formularza segmentu oraz
   recenzja natywna tekstu PL.

Uwaga poza roadmapą: na branchu `feature/reorder-cycle-build-cart` jest niedokończona,
nie-scommitowana praca nad akcją "build reorder cart" (temat sam w sobie już częściowo
pokryty w CHANGELOGu przez manual reminder, PR #91) — do domknięcia jako osobny temat, nie
część tej rundy porządkowej.
