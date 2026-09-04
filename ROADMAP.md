# Roadmap

What's still open — for shipped/stable features see [README.md](README.md), for implementation history/verification
detail see [CHANGELOG.md](docs/CHANGELOG.md) and [VERIFICATION.md](VERIFICATION.md), for the REST API reference see
[API.md](API.md), and the `Test/*/README.md` files.

Ownership split: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is
scoped from real hands-on marketing automation experience.

## Test coverage

- **Load/soak test** for Phase 7's dispatch performance work — the architectural bottlenecks (N+1, sync blocking,
  unbounded cron) are fixed, but no test has put a concurrent-throughput number on it.
- **`send_sms` has no test against a real Twilio account.** Unit tests (`TwilioSmsSenderTest`) drive the real SDK
  request-building/error-parsing logic via a fake `Twilio\Http\Client`, and the integration test
  (`CampaignSendSmsActionTest`) uses real DI/database but swaps out `SmsSenderInterface` for a
  `RecordingTwilioSmsSender` — so the actual Twilio API call (auth, delivery, and the
  `Controller\Sms\StatusCallback` webhook receiving a genuine signed callback) has never been exercised end to end
  against a live/trial Twilio account. `StatusCallbackTest` is unit-level too: it uses a real
  `Twilio\Security\RequestValidator` to compute a correct signature, but the collection/resource-model calls are
  mocked, so a real DB round trip (write on send → status update on callback) is untested.

## Tooling ideas, not yet actioned

- ~~PHPUnit 13~~ — done. Bumped `composer.json`'s `"php"` constraint from `>=8.2 <8.6` to
  `>=8.4 <8.6` and `phpunit/phpunit` to `^13.0`; moved the whole CI matrix (`ci.yml`,
  `coverage.yml`, `mftf.yml`) from PHP 8.3 to 8.4, including the `mftf.yml` nginx+PHP-FPM stack,
  which needed the `ppa:ondrej/php` PPA added since ubuntu-24.04's default apt repo only ships
  8.3 natively. Also bumped `rector.php`'s `withPhpSets()` from `php82` to `php84`, which flagged
  35 files needing `AddTypeToConstRector` (typed class constants) — applied. The real work was
  the "zero deprecation warnings" prerequisite: PHPUnit 13 deprecates `->method('x')->with(args)`
  used without a preceding `->expects(...)` (199 sites across 74 test files) — converted those to
  `->willReturnMap([[...args, value]])` (stub-style, no call-count assertion, matching the
  original behavior) rather than `->expects(self::any())`, since `any()` itself is *also*
  deprecated in this PHPUnit version ("will be removed in PHPUnit 14"). That conversion had a
  second-order effect: `willReturnMap()` doesn't set a "parameters rule" the way `->with()` did,
  so 93 of those same mocks newly tripped PHPUnit's separate "no expectations configured" notice
  — fixed by adding `#[AllowMockObjectsWithoutExpectations]`, the same attribute this suite
  already used elsewhere for exactly this case. Verified against the literal command
  `ci.yml` runs (`--fail-on-phpunit-notice --fail-on-notice --fail-on-warning
  --fail-on-deprecation`): exit 0, 992 tests, 6526 assertions, zero notices/deprecations.

## Code quality

- **Code duplication flagged by SonarCloud on the Setup/Patch/Data attribute patches and the
  percentile-condition/cron families.** Per the "New Code" duplication report: `Setup/Patch/Data/
  AddCustomerCreditLimitAttribute.php` (100%, 7 lines), `Setup/Patch/Data/
  AddCustomerSmsPhoneAttribute.php` (75.3%, 55 lines), `Model/Campaign/Condition/
  MonetaryPercentileAtLeast.php` (60.0%, 24 lines), `Model/Campaign/Condition/
  OrderFrequencyPercentileAtLeast.php` (60.0%, 24 lines), `Model/Campaign/Condition/
  RecencyPercentileAtLeast.php` (55.8%, 24 lines), `Cron/SendWinBackEmails.php` (51.2%, 22 lines),
  `Cron/SendOfferExpiryReminders.php` (41.5%, 22 lines), `Cron/SendReorderReminders.php` (36.4%,
  20 lines). The three `*PercentileAtLeast` condition classes are near-certainly the same
  NTILE-based percentile lookup copy-pasted per metric (recency/frequency/monetary) — a shared
  base class or trait is the likely fix, mirroring how the RFM report already shares one query
  shape across the same three metrics. The `Add*Attribute` Setup patches are boilerplate EAV
  attribute-creation patches that may be an acceptable, deliberate duplication (Magento's own core
  modules duplicate this same shape rather than share it, since each patch's dependency chain and
  attribute config genuinely differ) — worth a real look before assuming it needs deduplicating,
  not just acting on the percentage.

## Gaps vs. a full-market MA platform

Not a code review — a capability comparison against the category. Each is a real, separate stream of work:

- ~~Multichannel recovery: SMS~~ — done. `send_sms` campaign action (`Model/Campaign/Action/SendSms` +
  `Model/Sms/TwilioSmsSender`, official `twilio/sdk`) — any campaign (`cart_abandoned`/win-back included) can add
  it alongside or instead of `send_email` from the Flow canvas. Phone resolves via a dedicated `ordo_sms_phone`
  customer attribute (not the unreliable core address telephone). Delivery is tracked in a new, deliberately
  channel-generic `ordo_message_log` table via `Controller/Sms/StatusCallback.php`, a signature-verified webhook
  (`Twilio\Security\RequestValidator`) Twilio POSTs status updates to. Opted-out recipients (Twilio error 21610)
  are recorded as `status=opted_out`, distinct from a generic failure, and the Flow canvas's SMS message field
  carries a TCPA/opt-out-instruction reminder notice.
    - **WhatsApp** — still open, and confirmed via Twilio's own docs to be materially more work than "same API,
      `whatsapp:` prefix": outside a 24-hour customer-service session window (started only when the *customer*
      messages first), only pre-approved message templates can be sent (Marketing/Utility/Authentication
      categories, each with separate Meta fees, ~minutes-to-48h approval turnaround). A cold-start marketing
      cart-recovery message is necessarily template-based, so this needs a template-authoring/approval-tracking
      admin UI, not just a new `SmsSenderInterface`-style action — scope this properly before starting, don't
      underestimate it as a copy of the SMS slice.
    - **Push notifications** — still open, not investigated yet.
    - **SendGrid-backed email delivery tracking** — not multichannel-recovery scope exactly, but a natural
      follow-up now that `ordo_message_log`/the webhook pattern exist: Twilio's SendGrid (Mail Send API + Event
      Webhook for opens/clicks/bounces) could replace `send_email`'s current fire-and-forget `TransportBuilder`
      call the same way `send_sms` now tracks delivery. A real, separate architectural decision (email sending is
      threaded through `TransportBuilder` in more places than just `SendEmail`), not a small addition.
    - ~~No admin visibility into `ordo_message_log`~~ — done. A read-only grid at Marketing → Ordo Automation →
      Message Log (`Controller/Adminhtml/MessageLog/Index.php`, `ordo_messagelog_listing.xml`) lists every
      send/opt-out/failure `Model\Sms\MessageLogWriter` wrote, with the status Twilio's delivery webhook
      (`Controller\Sms\StatusCallback`) later updated. Reuses the `Ordo_Automation::campaigns` ACL resource, same as
      the RFM report, rather than adding a new permission for an operational view. Will cover email too once the
      SendGrid tracking item above lands, since the table is already channel-generic.
    - ~~`ordo_sms_phone` has no format validation~~ — done. `SendSms::execute()` now rejects anything that doesn't
      match a basic E.164 shape (`+`, non-zero leading digit, 8-15 digits total) before spending a Twilio API call,
      logging and recording it as `failed` in `ordo_message_log` the same way a real send failure would be — this is
      a fail-fast sanity check, not a full numbering-plan validator, so country-specific length/prefix rules still
      aren't enforced and a syntactically valid but non-existent number still only fails at Twilio.
- **On-site product recommendation blocks** — a new content-block type (or campaign action) rendering personalized
  product suggestions using data the module already has (customer/visitor tags, RFM scores, segment membership),
  not a new AI/recommendation engine. Extends the existing content-block and campaign-action surface rather than
  bolting on a separate subsystem.
- **Campaign calendar view** — an admin grid/calendar overlay showing every campaign's trigger window and any
  delayed actions (`delay_minutes`) in one place. Pure UI on top of data already modeled — no new entities.
- **Loyalty tiers on top of lead scoring** — map `ordo_customer_score` ranges to named tiers (e.g. Bronze/Silver/Gold),
  surfaced as a new segment condition type and a dashboard stat. Small and additive to the scoring system already
  built for §4 (lead scoring), not a separate loyalty subsystem.
- **Persistent in-site notification action** — a sibling to the existing `popup` campaign action, but non-modal and
  persisting until read or expired, instead of one-shot. Extends the existing `ordo_pending_popup`-style delivery
  mechanism rather than introducing a new one.
- **Single-question satisfaction/NPS survey action** — a 0–10 post-purchase (or post-support) prompt, stored as a
  new `ordo_customer_survey_response`-style entity, feeding into existing segment conditions the same way tags and
  scores already do. Deliberately narrower than a full open-ended survey builder, which is a genuinely separate
  subsystem and not scoped here.

## Localization

- **Native-speaker review of the 10 machine-translated locales** (`de_DE`, `fr_FR`, `es_ES`, `it_IT`, `pt_BR`,
  `zh_Hans_CN`, `ja_JP`, `ru_RU`, `uk_UA`, `nl_NL`) — shipped as a machine-translated first pass (see
  docs/CHANGELOG.md),
  not yet signed off by a human reviewer per locale. Highest priority: launch-blocking strings (error messages,
  delete confirmations) over descriptive/help text.

## Documentation

- **GitHub Wiki (WIKI.md) covering every feature, bilingual PL/EN, with screenshots.** Not started. Scope: a walkthrough
  of each shipped capability (campaigns, segments, RFM, lead scoring, free gifts, order approval, tracking/popups,
  reorder cycles, dashboard) with a real admin-UI screenshot per feature and description text in both Polish and
  English, published to the repo's GitHub Wiki (not just this ROADMAP/README). Needs a decision on structure first:
  one bilingual page per feature vs. a language-split page tree (`Feature-Name` + `Feature-Name-PL`) — GitHub Wiki
  has no built-in i18n, so this is a real information-architecture choice, not just a writing task. Screenshots
  should come from a real, running instance (the MFTF pipeline's own screenshot-on-failure mechanism proved useful
  for debugging — the same live-instance approach, deliberately captured on success this time, is the right source
  here too, not mockups).
