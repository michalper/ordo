# ADR 0060: Two-way conversation replies

## Status

Accepted.

## Context

`ROADMAP.md`'s fifth-pass brainstorm flagged a real gap, checked directly against the code: an
inbound SMS/WhatsApp reply could only ever be viewed, never answered, from the admin.
`Controller\Adminhtml\ConversationMessage\Index` implements `HttpGetActionInterface` only - a
read-only grid over `ordo_conversation_message` - and `Model\Conversation\InboundMessageProcessor`'s
own docblock describes it as a one-way funnel that only ever revokes consent on a STOP keyword,
never sends anything back. A merchant seeing a customer's reply in the grid had no way to answer
them without leaving Magento entirely (calling or texting from outside the admin). The same
boundary was already identified and deliberately NOT crossed once before, for a much narrower
case: the "HELP keyword auto-reply" candidate elsewhere in `ROADMAP.md` was dropped specifically
because it would have made `InboundMessageProcessor` a sender as well as a receiver, for one
low-value, carrier-specific reply. An admin-initiated, human-composed reply is a different, real
need, but the same boundary concern applies just as much.

## Decision

- **A new, separate service - `Model\Conversation\OutboundReplySender` - not a change to
  `InboundMessageProcessor`.** `InboundMessageProcessor` stays exactly what its own docblock says
  it is: "the single funnel every inbound reply passes through", receive-only, unconditional
  STOP-keyword handling intact and untouched. `OutboundReplySender` is a sibling with the opposite
  direction: it loads an existing inbound `ordo_conversation_message` row, re-checks consent via
  `Model\ConsentManager` (the same check every other channel send in this module makes - a STOP
  reply could have arrived and been processed by `InboundMessageProcessor` in the gap between the
  customer's message and the admin's reply), dispatches to `Model\Sms\SmsSenderInterface` or
  `Model\WhatsApp\WhatsAppSender` directly based on the original message's own `channel` column,
  and writes a new `ordo_conversation_message` row for the reply. It is invoked only by the new
  `Controller\Adminhtml\ConversationMessage\Reply` (`HttpPostActionInterface`, reusing the existing
  `Ordo_Automation::conversation_message` ACL resource) - never by `InboundMessageProcessor`, and
  never by the campaign engine (`Model\Campaign\Action\SendSms`/`SendWhatsApp` keep their own,
  separate consent/quiet-hours/frequency-cap gated path, which an ad hoc admin reply correctly does
  not go through - quiet hours and frequency caps exist to pace unsolicited outbound marketing, not
  to delay a human answering a question a customer just asked).

- **`WhatsAppSender` gains `sendFreeform()`, a genuinely new capability, not a refactor of
  `send()`.** Every existing call to `WhatsAppSender::send()` sends an approved template, never
  free text, because a campaign dispatch (`Model\Campaign\Action\SendWhatsApp`) has no reliable way
  to know whether the recipient's 24-hour customer-service window is currently open, and Meta's
  Cloud API rejects a free-form message outside it. An admin reply is different: it is always a
  direct response to a message the customer just sent, which is by definition inside that window.
  `sendFreeform()` exists for exactly this one caller (`OutboundReplySender`) and is documented as
  never to be called from a campaign action. The two methods now share a small
  `extractMessageId()` helper for the identical "pull `messages[0].id` out of the response body"
  logic, rather than duplicating it a second time.

- **`direction` column on `ordo_conversation_message`, defaulting to `inbound`.** The table had no
  column distinguishing inbound from outbound rows - every row was implicitly inbound. A new
  `varchar(16)` column, `nullable="false" default="inbound"`, means every row written before this
  change (and every future row `InboundMessageProcessor` writes, since that class is unchanged)
  stays correct with zero backfill: the column is simply never set in PHP for an inbound row, and
  MySQL's own column default fills it in at insert time. Only `OutboundReplySender` ever sets it
  explicitly, to `outbound`. The `received_at` column name now technically undersells what it
  means for an outbound row ("when this was sent", not "received") - kept rather than renamed, to
  avoid a second migration and a resource-model/collection-ordering change for a cosmetic mismatch;
  its column comment now explains both.

- **The reply UI is a plain, server-rendered form, not a per-row knockout modal.** The grid's
  `Ui\Component\Listing\Column\ConversationMessageActions` adds one plain navigational "Reply"
  link per inbound row (not a POST-with-confirm action like `AbstractEntityActionsColumn`'s
  edit/delete - there is nothing to confirm, and the reply text itself cannot be typed into a
  confirm dialog), pointing back at the same grid page with a `reply_to` query param. A new
  `Block\Adminhtml\ConversationMessage\ReplyForm` block + plain `<form method="post">` (same
  `getBlockHtml('formkey')` shape `Block\Adminhtml\Import\Form` already uses) renders below the
  grid, with its "Message ID" field pre-filled from that query param. Considered and rejected: a
  per-row inline textarea rendered via a custom knockout column template - genuinely nicer UX, but
  it is new, unverifiable-in-this-sandbox JS/knockout wiring for a v1 of a feature whose actual
  risk is in the send/consent logic, not the input form. The direction column itself renders as a
  plain translated label (`Inbound`/`Outbound`), the same "a plain text label is fine for v1" shape
  `is_stop_keyword` already uses on this grid - not a dedicated per-thread detail page.

- **System-level test: `Test\Integration\OutboundReplySenderTest`, not a new MFTF test.** The
  existing grid (`Controller\Adminhtml\ConversationMessage\Index`) already has MFTF coverage
  (`AdminWhatsAppInboundMessageConversationGridTest`, per `Test\Mftf\SCENARIOS.md` #23) proving the
  grid itself renders real rows - that page being admin-UI-observable is already answered. What
  this change adds that is NOT already proven anywhere is whether a reply actually reaches the
  right sender with the right phone number/channel, and whether consent is genuinely re-checked
  against a real `ordo_customer_consent` row - exactly the kind of real-wiring question
  `AGENTS.md`'s own testing rule flags Integration tests for catching (a DI-registered class with
  the wrong virtual type, a check that looks right but never actually runs) and that a browser-level
  test proves only indirectly, by reading a success/error message off the page. A full MFTF test
  would also have to be written and shipped without being able to run `mftf generate:tests`/
  `mftf run:test` against it first (the shared sandbox was off-limits for this change), which
  AGENTS.md's own MFTF pitfalls section shows is a real, recurring source of broken selectors/XSD
  violations that only surface in CI. `Test\Integration\OutboundReplySenderTest` follows
  `CampaignSendSmsActionTest`'s exact template: real DI, a real database, real `ConsentManager`
  consent checks against a real `ordo_customer_consent` row, and only the two provider calls at the
  very tail end faked (`RecordingTwilioSmsSender`, already shared with other tests, and the new
  `RecordingWhatsAppSender`, which overrides just `sendFreeform()` the same way
  `RecordingTransportBuilder` overrides just `getTransport()`). The controller itself stays thin and
  is covered at the Unit level with a mocked `OutboundReplySender`, the same split already used for
  every other controller in this module (`Index`/`Delete`/`Send`, etc.).

## Consequences

- An admin can now answer an inbound SMS/WhatsApp conversation directly from Magento, closing the
  gap `ROADMAP.md` tracked, without touching `InboundMessageProcessor`'s receive-only contract at
  all - a future reviewer diffing that class against this PR will see zero changes to it, by design.
- `ordo_conversation_message` is no longer implicitly inbound-only; any future reporting/export
  query against that table that assumed "every row is an inbound reply" needs to filter on
  `direction` going forward. `received_at`'s name is now a known, documented small mismatch for an
  outbound row rather than a renamed column.
- The reply form's UX is intentionally minimal (type a numeric Message ID, or follow the Reply
  link that pre-fills it) rather than a fully inline per-row composer. A future pass could replace
  it with a knockout-driven inline box once there is a sandbox-verified way to ship that kind of
  grid customization safely in this module, but nothing about this ADR's decisions blocks that
  later change.
- `WhatsAppSender::sendFreeform()` is a capability that, if ever called from the wrong place (a
  campaign action, a cron), would send an unsolicited free-form message outside a window Meta
  may reject or penalize for - its docblock says so explicitly, and it has exactly one caller today.
