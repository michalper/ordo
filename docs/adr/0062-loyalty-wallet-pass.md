# ADR 0062: Digital loyalty wallet pass - Google Wallet only, real-device verification accepted as a permanent gap

## Status

Accepted.

## Context

`ROADMAP.md`'s fifth-pass brainstorm flagged a real gap: `Model\LoyaltyPointsManager`'s points
balance and `Model\LoyaltyTierCalculator`'s bronze/silver/gold tier only ever surface through the
"My Offers" storefront account page and emails - there's no scannable membership card a customer
can add to their phone's wallet, a feature established loyalty platforms ship as a standard
retention surface.

Both Apple Wallet (a signed `.pkpass` bundle - a ZIP of JSON + images, PKCS#7-signed) and Google
Wallet (a JWT-signed "Save to Google Wallet" link, RS256-signed with a service account key) build
their *pass-construction/signing logic* without any vendor SDK, fully unit-testable with a
locally-generated test key - no live account needed for that part, the same precedent
`Model\Push\WebPushCrypto`/`VapidTokenBuilder` already set for Web Push's own hand-rolled RFC
8291/8188/8292 implementation. But a pass a real device will actually *accept and display* needs
credentials from a gated program on each side: Apple Developer Program enrollment (paid) for a
Pass Type ID signing certificate, or a Google Wallet Issuer account (Google Pay & Wallet Console
business verification) for Google. This project has neither - the same situation `ROADMAP.md`'s
"Test coverage" section already documents for Google Ads Customer Match (blocked on $50k lifetime
ad spend) and WhatsApp Business verification (blocked on requiring a Facebook Page the user has
declined to create). This ADR exists to make that trade-off explicit *before* writing code, not
discover it mid-implementation.

## Decision

- **Google Wallet only for v1, not both platforms.** Comparing the two payload formats directly:
  Apple's `.pkpass` needs a `pass.json` manifest, a SHA-1 manifest hash file, a `ZipArchive`-built
  ZIP container, and a PKCS#7 detached signature (`openssl_pkcs7_sign()`, which needs a real X.509
  certificate - a self-signed one works for unit testing the byte structure, but the format
  itself is heavier to build *correctly* end to end: multiple files, a manifest integrity
  cross-check, DER/PKCS#7 ASN.1 framing on top of what `Model\Push\Der` already hand-rolls for
  Web Push). Google Wallet's "Save to Google Wallet" flow is a single compact JWT
  (`header.payload.signature`, RS256) whose payload embeds the LoyaltyObject JSON inline - no ZIP,
  no manifest, no PKCS#7, and the service account's private key already arrives as a plain PKCS#8
  PEM (no DER reconstruction needed at all, unlike VAPID's raw EC scalar). Given a choice between
  splitting effort across both incompletely and building one solid, fully-tested implementation,
  Google Wallet's simpler, single-artifact format was picked to build first. Nothing here rules
  out adding Apple Wallet later as a separate ADR/PR if the Apple Developer Program gap closes.
- **New classes mirror the Web Push split (primitive / JWT / orchestrator), not one god class:**
  - `Model\Wallet\GoogleWalletLoyaltyObjectBuilder` - pure data projection: reads
    `LoyaltyPointsManager::getBalance()` and `LoyaltyTierCalculator::getTierForScore()`
    (via the customer's existing `CustomerScoreManager::getScore()`) and shapes them into a
    Google Wallet `LoyaltyObject` resource. No crypto, no network - trivially unit-testable.
  - `Model\Wallet\GoogleWalletJwtBuilder` - the signing primitive: RS256-signs
    `header.payload` with `openssl_sign()`/`openssl_pkey_get_private()` and returns the full
    `https://pay.google.com/gp/v/save/<jwt>` URL. The RS256/PEM equivalent of
    `Model\Push\VapidTokenBuilder`, minus the DER step VAPID's ES256 needs.
  - `Model\Wallet\GoogleWalletPassLinkBuilder` - the orchestrator both callers below actually use:
    config-gates the feature, checks all four Google Wallet credentials are configured, and wires
    the two classes above together. Same "one place builds the real link" reasoning
    `Model\OrderApprovalManagement::buildDecisionLinks()` documents for its own approve/reject
    URLs.
- **This module never calls the real Google Wallet Objects REST API** to pre-create the
  LoyaltyObject or LoyaltyClass server-side - doing so needs a live Issuer account. The loyalty
  *class* (program branding, hex colors, logo) still has to be created once, out of band, by the
  merchant in the Google Pay & Wallet Console; only its id is configured here
  (`Helper\Config::getWalletPassGoogleClassId()`). The LoyaltyObject itself is embedded inline in
  the JWT every time a save URL is requested, rebuilt fresh from the live points balance/tier -
  Google's own documented flow accepts an inline object in lieu of a pre-created one, and this
  avoids needing to keep a server-side object in sync with every points/tier change.
- **Two entry points, one builder.** `Api\LoyaltyWalletPassManagementInterface::getMyGoogleWalletSaveUrl()`
  (`GET /V1/ordo/loyalty-wallet-pass/google`, `self` resource - same shape as the existing
  `/V1/ordo/loyalty-points/*` routes) returns the save URL as a plain string, for a headless/PWA
  storefront. `Controller\LoyaltyWalletPass\Google` is a session-authenticated storefront GET
  action (same `AbstractCustomerAccountAction` base as `Controller\Offer\*`) that 302-redirects to
  the same URL - this is what the "My Offers" page's new "Add to Google Wallet" button links to,
  keeping the service account private key and JWT signing entirely server-side rather than a
  template assembling (or worse, caching) a save URL itself.
- **No new storefront page.** There's no dedicated loyalty-points account page today (points/tier
  are REST/GraphQL-only) - rather than build one just to host this button, the new
  `Block\Frontend\LoyaltyWalletPassViewModel` + `walletpass/google_button.phtml` were added to the
  existing "My Offers" page layout (`ordo_offer_index.xml`), the same "no admin CRUD UI, stay
  lean" reasoning the `Offer` entity itself already established for this page's existence.
- **Config fields follow the VAPID precedent exactly**: `google_issuer_id`/`google_class_id`/
  `google_service_account_email` are plain text, `google_private_key` is `type="obscure"` with
  `Magento\Config\Model\Config\Backend\Encrypted`, read back via `Helper\Config`'s existing
  `decryptedConfig()` helper - identical shape to `getVapidPrivateKey()`.

## Consequences

- **Permanent, accepted test-coverage gap - real-device verification never happens, now or
  later, without a gated account this project doesn't have.** Everything up to "produce bytes a
  real device would accept" is fully unit-tested: `GoogleWalletJwtBuilderTest` generates a real
  RSA keypair, signs a real JWT, and verifies the RS256 signature with `openssl_verify()` against
  the matching public key - proving the signing is byte-correct, not just "didn't throw."
  `Test\Integration\LoyaltyWalletPassEndpointTest` proves the real wiring end to end against a
  real customer/loyalty-ledger row and a real, self-signed test RSA key: a real REST/service-layer
  call returns a real, well-formed, independently-verifiable JWT embedding that customer's real
  points balance and tier. What neither test can do, and what no amount of local testing ever
  will without enrolling in the Google Wallet Issuer program (business verification, a live
  Issuer ID) is: generate a JWT signed by a *real* Google-issued service account key, hand the
  resulting save URL to an actual Google Wallet app on an actual phone, and confirm the pass
  renders and updates correctly on-device. This is the same class of permanent, accepted gap
  `ROADMAP.md`'s "Test coverage" section already documents for Google Ads Customer Match and
  WhatsApp Business verification - flagged here explicitly rather than left to be discovered
  later, and `ROADMAP.md` itself has been updated alongside this ADR to record it in that same
  section.
- **No Apple Wallet support.** A customer on iOS gets the same Google-flow link, which their
  device can't act on the way an Android device (or desktop Chrome, via Google's own
  cross-platform web flow) can. Revisiting Apple Wallet is a future, separate ADR/PR if the Apple
  Developer Program gap ever closes - not blocked by anything decided here, since
  `GoogleWalletLoyaltyObjectBuilder`'s data-projection step would be reusable almost as-is for an
  Apple `.pkpass`'s own `pass.json`.
- **The loyalty class (program branding) is a manual, out-of-band setup step** a merchant has to
  perform once in the Google Pay & Wallet Console before this feature does anything useful -
  misconfiguring or omitting it fails closed with a clear `LocalizedException`
  ("Google Wallet is not fully configured.") rather than silently producing a broken link.
