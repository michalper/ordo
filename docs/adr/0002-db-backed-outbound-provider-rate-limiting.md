# ADR 0002: Pace outbound provider calls through a DB-backed atomic claim, not the cache

## Status

Accepted. Introduced in #127.

## Context

`Model/RateLimit/OutboundRateLimiter.php` spaces consecutive calls to external providers
(Twilio SMS, WhatsApp, Web Push, outbound webhooks). It was originally `CacheInterface`-backed:
read the last-call time, sleep if needed, write the new time. That is a read-then-write race —
its own docblock admitted that "a handful of truly concurrent callers could each read the same
pre-update last-call time and under-throttle briefly". Multiple queue consumer instances and
overlapping cron runs genuinely call the same provider at the same moment from different
processes, each with its own racy view of the pacing state.

## Decision

Pacing state lives in the `ordo_provider_rate_limit` table (`Model/RateLimit/ProviderRateLimitStore.php`),
claimed with a single atomic `INSERT ... ON DUPLICATE KEY UPDATE` — the same
atomic-conditional-UPDATE idiom the module already uses for cross-process coordination elsewhere
(`ResourceModel\MessageSendRetry::claim()` and friends). `OutboundRateLimiter`'s public API
(`throttle(string $channel, float $maxPerSecond)`) is unchanged, so its callers
(`TwilioSmsSender`, `WhatsAppSender`, `PushSender`, `SendWebhook`) needed no changes.

Inbound throttling deliberately stays cache-backed (`Model/Approval/ApprovalRateLimiter.php`,
`Model/AiAgent/InboundRateLimiter.php`): those are coarse abuse guards on traffic the module
doesn't control the shape of, where a brief under-throttle is an acceptable trade-off and not
worth a DB table.

## Consequences

- Concurrent consumers and cron runs share one pacing state, so a provider's rate limit is
  respected across processes, not just within one.
- Every outbound call now costs one extra DB write on the claim path.
- There are two rate-limiting mechanisms in the codebase on purpose (DB for outbound, cache for
  inbound) — a new limiter should pick the one matching its direction, not the nearest example.
