# ADR 0001: Dispatch campaigns via the Magento queue, not synchronously from the observer

## Status

Accepted.

## Context

Triggers (`order_placed`, `customer_registered`, `tag_added`) originally called
`CampaignDispatcher::dispatch()` directly from the Magento event observer. The dispatcher
evaluates conditions and executes actions (including sending email, generating a coupon) —
execution time grows with the number of active campaigns and condition complexity, and all of
this happened on the thread handling the customer's request (checkout, registration).

## Decision

Observers publish a message on the `ordo.automation.campaign.dispatch` topic
(`Model/Queue/CampaignDispatchPublisher.php`), consumed asynchronously by
`Model/Queue/CampaignDispatchConsumer.php`. Customer checkout/registration no longer waits on
condition/action evaluation.

Environments without RabbitMQ (including the test environment, see `AGENTS.md`) fall back to
Magento's default DB-backed queue driver — the consumer then has to actually run in the
background (a long-running process or cron), otherwise messages just pile up in the queue table
without being processed.

## Consequences

- Dispatch is no longer immediate from the trigger's point of view — it's delayed by however
  long the queue takes to process (usually seconds, depending on the consumer).
- Integration/MFTF tests that want to verify the end-to-end effect must either call
  `CampaignDispatcher::dispatch()` directly (bypassing the queue — this is what
  `CampaignDispatchScenarioTest` does), or actually start the consumer as a subprocess and wait
  for a specific message (this is what `CampaignQueueWiringTest` and the
  `AdminCampaignScenarioEndToEndTest` MFTF test do).
- In environments without RabbitMQ, a consumer has to be deliberately kept alive
  (supervisord/cron) — without that, campaigns silently stop firing, with no error at all.
