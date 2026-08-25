# MFTF tests

Standard Magento MFTF layout (`Test/`, and `ActionGroup/`/`Data/`/`Page/`/`Section/` as needed) per the [Adobe MFTF getting-started guide](https://developer.adobe.com/commerce/testing/functional-testing-framework/getting-started).

## What exists

- **`AdminCreateCampaignTest.xml`** — admin creates a campaign (one condition, one action) via the Phase 4 form and confirms it saves and appears in the grid. XML-well-formed and written against standard, stable Magento action groups (`AdminLoginActionGroup`/`AdminLogoutActionGroup`) — **has not been run against a real Magento instance** (no MFTF runtime available in this dev environment). Verification status matches the same caveat already documented for `CheapestItemFree`.

## What's still missing (planned, not written)

- The dispatcher actually firing on a real trigger — e.g. place a real order over a spend limit in checkout, confirm the held status and the approval email/link round-trip.
- Offer self-extension from the storefront.
- Credit limit checkout behavior (order blocked / allowed at the threshold).
- The tracking JS snippet actually setting a cookie and posting an event in a real browser session (this class of test is closer to what MFTF is for than a unit test can cover, since it involves real client-side JS).
