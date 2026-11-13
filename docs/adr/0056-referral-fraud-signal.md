# ADR 0056: Referral duplicate-identity signal

## Status

Accepted.

## Context

A fourth feature-gap brainstorm round (2026-11-13) flagged `Model\ReferralManager::recordSignup()`'s
only two guards: self-referral (`$referrerCustomerId === $referredCustomerId`) and "already has a
referral row" (one referral per referred customer, ever). Neither catches the common reward-farming
shape established referral/loyalty tools guard against: the referrer registering a second, disposable
account for themselves and "referring" it to collect both sides of a two-sided reward (see ADR 0048).
`Observer\RedeemReferralCode`/`Controller\Referral\Track` never captured or compared a remote
address at all before this change.

Two real data problems had to be settled before any guard could be written:

- **Nothing captures any customer's registration IP anywhere in this module today** - not just the
  referrer's. A guard comparing the referred customer's IP against "the referrer's own" IP would need
  new, always-on IP capture at *every* registration (referral or not), and even then every referrer who
  signed up before that change shipped would have no stored IP to compare against, forever - a
  permanent cold-start gap for the module's entire existing install base, not a temporary one.
- Established tools' own public guidance for this exact signal talks about two distinct comparisons:
  referrer-vs-referred, and referred-vs-other-referred-under-the-same-referrer. The second needs no
  data this guard doesn't already produce itself.

## Decision

- **Compare the new signup's IP against this same referrer's *other* referred signups, not the
  referrer's own registration IP.** Two "different" referred accounts sharing one IP, under the same
  referrer, is the stronger farming signal of the two anyway (it's the actual
  reward-farming-via-disposable-accounts shape the roadmap gap named - a referrer's own household/
  office network matching their referred friend's is weak evidence by itself), and it's entirely
  self-contained: no new capture point anywhere else in the codebase, no cold-start gap for any
  existing referrer (a referrer's very first referral simply has nothing to compare against yet, which
  is the correct "no signal" answer, not a false positive).
  - **Rejected:** capturing and comparing against the referrer's own IP. Needs IP capture at every
    customer registration, not just referral signups, and leaves every referrer who existed before
    that change shipped permanently uncheckable. Revisit only if "referred-vs-other-referred" proves
    too narrow in practice.
- **New `ordo_referral.signup_ip`** (nullable varchar(45), long enough for IPv6) - the referred
  customer's registration IP, captured by `Observer\RedeemReferralCode` via
  `Magento\Framework\HTTP\PhpEnvironment\RemoteAddress` (the exact DI/usage pattern
  `Model\OrderApprovalManagement::enforceRateLimit()` already uses) and passed into
  `ReferralManager::recordSignup()`'s new optional third parameter. New composite index
  `(referrer_customer_id, signup_ip)` for the lookup query this guard runs on every referral signup.
- **New `ordo_referral.duplicate_ip_flag`** (boolean, default 0), set once at insert time by a new
  private `ReferralManager::isDuplicateIpSignup()`: true when a referral row already exists for this
  same `referrer_customer_id` with the same `signup_ip`. Fails open (no flag) whenever there's nothing
  real to compare against - no IP captured for this signup (`RemoteAddress` returned nothing), or this
  referrer's first-ever referral - the same "no data to compare against" shape
  `Model\ChurnRisk\ChurnRiskCalculator` already uses (fewer than 2 non-canceled orders fails closed to
  null) and `Model\Rfm\RfmCalculator::getRecencyDays()` (no orders at all returns null): no signal
  captured is not the same thing as a flag raised.
- **Detect and surface, not detect and block.** A flagged referral is still recorded exactly as before
  (`recordSignup()` still returns `true`, `referral_signed_up` still publishes, the welcome incentive
  still fires) - only `duplicate_ip_flag` is set, surfaced on a new read-only admin grid
  (`ordo/referral/index`, mirroring `Controller\Adminhtml\AnomalyAlert\Index` - the simplest existing
  grid controller in this module) plus a dashboard stat card
  (`DashboardViewModel::getFlaggedReferralCount()`), same "Anomaly alerts (recent)" pattern. This is
  the same choice `Model\Anomaly\AnomalyAlert` already made for "suspicious but not necessarily wrong":
  a shared IP is real but weak evidence on its own (same household, same office, same mobile carrier
  NAT, a public library) - auto-blocking on it risks denying a genuine referral's welcome incentive
  with no recourse, which is a worse failure mode than one extra grid row a merchant can judge for
  themselves.
  - **Rejected:** blocking recordSignup() the same way self-referral/already-referred already do
    (returning `false`, recording nothing). Too high a false-positive cost for a signal this weak on
    its own, and unlike the other two guards (which are unambiguous - a customer cannot legitimately
    refer themselves, or be referred twice), "shared an IP" has entirely legitimate explanations.
- No new config toggle. This is a refinement of the existing `isReferralEnabled()`-gated referral
  signup path (same as the two guards it joins), not a separate feature with its own cron or schedule.

## Consequences

- No existing referral row gets a `duplicate_ip_flag`/`signup_ip` retroactively - this guard only ever
  evaluates at the moment a *new* signup is recorded, so historical data is simply absent (shown as
  blank/No in the grid), not backfilled or re-evaluated.
- A referrer who refers several genuinely different friends from behind the same NAT (a shared office,
  a university dorm, a small town's single ISP) will accumulate flagged rows with no real fraud
  behind them - this is the accepted cost of a weak-but-free signal that surfaces for human judgment
  rather than auto-blocks; if real usage shows this noise is too high, the next step is `duplicate_ip_flag`
  on only the *second and later* IP-sharing signup rather than every one of them, not a stronger
  auto-block.
- `ReferralManager::recordSignup()`'s signature grew an optional third parameter
  (`?string $signupIp = null`) rather than a new overload - every existing caller besides
  `RedeemReferralCode` (there are none in this module today) keeps working unchanged, and a future
  caller that can't supply an IP (e.g. a CLI-driven backfill) gets the same fail-open behavior as a
  `RemoteAddress` lookup that genuinely found nothing.
- This closes the gap for the self-referral-via-second-account shape specifically called out in the
  roadmap item. It does not add a general fraud-scoring engine, device fingerprinting, or a
  billing-address comparison (the roadmap bullet's own alternative) - those remain open if real usage
  later shows IP-only is insufficient.
