# ADR 0051: WhatsApp read-receipt tracking

## Status

Accepted.

## Context

A second-round brainstorm candidate (2026-11-12) flagged a real gap: `Controller\WhatsApp\
Webhook`'s own `MESSAGE_STATUS_MAP` maps Meta's `DELIVERED`/`FAILED`/`SENT` statuses to
`MessageLog::STATUS_*` constants, but Meta's Cloud API webhook contract also sends a `READ`
status (the WhatsApp "seen" receipt) for every template message the recipient actually opens -
today that status arrives and is silently dropped, since it isn't in the map and there's no
`MessageLog::STATUS_READ` equivalent for it. `Model\Campaign\Condition\MessageEngaged`
(`engagement_type: "opened"|"clicked"`) only ever reads SendGrid's email events - WhatsApp has no
engagement signal a campaign can target at all today, even though Meta is already handing this
module exactly that signal for free.

## Decision

- **`READ` is an event, not a status.** Unlike `DELIVERED`/`FAILED`/`SENT`, a read receipt
  doesn't change `ordo_message_log`'s own delivery state (the message was already delivered) -
  it's an engagement signal layered on top. `Controller\WhatsApp\Webhook::processMessageStatus()`
  special-cases `READ` before the `MESSAGE_STATUS_MAP` lookup, routing it to a new
  `processReadReceipt()` that writes a `Model\MessageLogEvent::TYPE_READ` row via the existing
  `MessageLogEventWriter` - the same collaborator/table `Controller\Email\StatusCallback` already
  writes Opened/Clicked rows into, not a new ledger.
- **`message_engaged` becomes usable for WhatsApp for free.** `Model\Email\
  MessageEngagementResolver::hasEngaged()`/`getCustomerIdsWithEngagement()` already filter
  `ordo_message_log_event` by a plain `event_type` string, with zero email-specific logic - so
  adding `MessageLogEvent::TYPE_READ` as a `Model\Config\Source\MessageEngagementType` option
  immediately makes "built a `send_whatsapp` action, read within N days" a real, usable campaign
  condition, with no resolver changes. The condition's own field label changed from "Opened or
  clicked" to "Opened, clicked, or read (WhatsApp)" to stop implying it's email-only.
- **`Model\CampaignFunnelStats::getSendStats()`'s open/click-rate report is deliberately left
  alone.** It hardcodes `TYPE_OPENED`/`TYPE_CLICKED` into two report columns; a `read` event row
  simply falls through its `if`/`elseif` uncounted, same as any other unmapped event type would.
  Adding a third "read rate" column to that report is a separate, bigger UI feature this ROADMAP
  item didn't ask for - closing the *targeting* gap (`message_engaged`) doesn't require closing
  the *reporting* gap too.

## Consequences

- A merchant can now build a campaign that reacts to a customer having read a specific WhatsApp
  template send - the stated goal of the ROADMAP candidate, with no new webhook plumbing (same
  URL, same signature verification, same `ordo_message_log_event` table).
- The campaign funnel report on the edit page still only ever shows open/click rates, even for a
  campaign with `send_whatsapp` actions - a human reading the "WhatsApp read" condition option
  next to a report with no "read" column might reasonably ask for one next; that's a legitimate
  follow-up, not something this change silently broke.
