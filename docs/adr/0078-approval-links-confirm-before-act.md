# ADR 0078: Order-approval email links render a confirm page on GET, act only on POST

## Status

Accepted.

## Context

A full-repo security audit (2026-11-23) flagged a real gap in `Controller\Approval\{Approve,Reject}`:
both actions only ever implemented `HttpGetActionInterface`, and `execute()` called
`OrderApprovalManagement::approveByToken()`/`rejectByToken()` directly from a bare GET. The
decision token is single-use (`OrderApprovalResource::claimPending()` atomically marks it
decided), which is fine against a double human click, but not against an automated GET that was
never a human click at all: corporate email-security gateways (Microsoft Defender Safe Links,
Proofpoint URL Defense, Mimecast) and some mail clients routinely pre-fetch or pre-render every
URL in an inbound email, before, or instead of, the recipient ever clicking it. That pre-fetch
silently and permanently approved or canceled a real order, burning the token before the actual
recipient (a manager who needs to approve/reject) ever saw the email - the opposite of what this
feature exists to guarantee.

## Decision

- **GET renders a confirmation page; the real decision requires a POST from that page's own
  button.** `AbstractApprovalAction` now implements `HttpGetActionInterface`,
  `HttpPostActionInterface`, and `CsrfAwareActionInterface`; `Approve`/`Reject::execute()` branch
  on `$this->getRequest()->isPost()` - `false` renders `view/frontend/templates/approval/confirm.phtml`
  via a new `ordo_approval_{approve,reject}.xml` layout handle, `true` runs the exact same
  approve/reject logic this class always had. One controller, two verbs, branching inside
  `execute()` - the same single-action shape `Controller\Email\Unsubscribe` already uses for RFC
  8058's `List-Unsubscribe-Post`, except that one does the same thing for either verb; this one
  deliberately differs (render vs. act), since an email-safe GET must never have a side effect.
- **The GET path never reads or validates the token at all.** The confirm page only echoes the
  token (from the request) into its own form's hidden field - it never calls
  `loadPendingApprovalByToken()`, so a prefetch of the confirm page itself cannot distinguish a
  valid token from an invalid one, let alone consume it. Validation, claiming, and the rate limit
  (`ApprovalRateLimiter`) all still happen exactly once, inside the POST branch, same as before.
- **CSRF-exempt**, same reasoning as `Controller\Email\Unsubscribe`: this is a sessionless,
  token-authenticated anonymous endpoint - the secret token in the URL/form IS the
  authentication, and there is no session/cookie-based login here for a forged cross-site request
  to exploit. `validateForCsrf()` returns `true` unconditionally; a `form_key` would add nothing
  a forged request doesn't already lack (knowledge of the real token).
- **No change to the emailed link itself or to `OrderApprovalManagement::buildDecisionLinks()`.**
  The URL stays `.../ordo/approval/{approve,reject}/token/<token>` - only the HTTP method
  reaching that URL changes what happens. This keeps every already-sent email's link working
  (it still resolves to the confirm page) and needs no change to the email template.
- **Accepted trade-off: one extra click for the admin/manager**, and MFTF coverage
  (`AdminApproveOrderViaEmailTest`, `AdminRejectOrderViaEmailTest`, `AdminApprovalRateLimiterTest`,
  `AdminOrderApprovalsGridTest`) now clicks the confirm button after following the link, instead
  of asserting the outcome straight off the GET. Considered and rejected: inspecting
  `User-Agent`/`Sec-Fetch-User` to distinguish a human click from a prefetch - fragile and
  spoofable, unlike a real POST, which no mail-scanner prefetch generates.

## Consequences

- A manager acting on a real approval email now clicks through a short confirmation page instead
  of the decision landing immediately - a deliberate, minor UX cost for closing a real
  silent-approval/silent-cancel gap.
- Any future "one-click from an email" action in this module should default to this same
  GET-renders/POST-acts split rather than a bare GET, unless (like `Controller\Email\Unsubscribe`)
  the action is genuinely safe to prefetch and reversible.
