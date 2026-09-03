# Roadmap

What's still open — for shipped/stable features see [README.md](README.md), for implementation history/verification
detail see [CHANGELOG.md](CHANGELOG.md) and [VERIFICATION.md](VERIFICATION.md), for the REST API reference see
[API.md](API.md), and the `Test/*/README.md` files.

Ownership split: B2B direction is scoped by the technical/architecture side (this repo's maintainer); B2C direction is
scoped from real hands-on marketing automation experience.

## Test coverage

- **Load/soak test** for Phase 7's dispatch performance work — the architectural bottlenecks (N+1, sync blocking,
  unbounded cron) are fixed, but no test has put a concurrent-throughput number on it.

## Tooling ideas, not yet actioned

- **PHPUnit 13** (currently on `^12.0`) — blocked, not just a version bump: PHPUnit 13 requires PHP 8.4+, but every
  workflow (`ci.yml`, `coverage.yml`, `mftf.yml`) runs PHP 8.3, and composer.json's own supported range starts at
  `~8.2.0`. Needs a deliberate decision to move the whole CI matrix (and the module's minimum supported PHP version)
  to 8.4 first — not something to fold into an unrelated change. PHPUnit 13 also requires the suite to already run
  clean with zero deprecation warnings under PHPUnit 12.5 first, which `ci.yml`'s `--fail-on-*-deprecation` flags
  already enforce today, so that half of the prerequisite is already satisfied.

## Gaps vs. a full-market MA platform

Not a code review — a capability comparison against the category. Each is a real, separate stream of work:

- **Multichannel recovery** — SMS/WhatsApp/push. `cart_abandoned`/win-back only ever send email.

## Localization

- **Native-speaker review of the 10 machine-translated locales** (`de_DE`, `fr_FR`, `es_ES`, `it_IT`, `pt_BR`,
  `zh_Hans_CN`, `ja_JP`, `ru_RU`, `uk_UA`, `nl_NL`) — shipped as a machine-translated first pass (see CHANGELOG.md),
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
