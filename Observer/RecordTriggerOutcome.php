<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

/**
 * On sales_order_save_after, NOT sales_order_place_after like HoldOrderForApproval (this one is
 * otherwise unrelated to it) - see Observer\RecordCampaignOutcome's own docblock (the identical
 * fix, same real bug, applied to both observers at once) for the full reasoning: a fresh order's
 * entity_id is genuinely
 * unpopulated at sales_order_place_after time for every normal (non-reorder) checkout, which
 * silently no-oped this every time since a real entity_id is required here (order_id is what
 * gets credited with the response) - confirmed the hard way via this module's own
 * AdminCampaignFunnelReflectsRealSendAndConversionTest.
 *
 * Closes the loop on Model\TriggerOutcomeLogger's own sent rows: if this customer was recently
 * sent one of the 5 cron-driven triggers and hasn't responded yet, this order counts as their
 * response. First-plausible-match, not exact attribution — see TriggerOutcomeLogger::markActed().
 *
 * The parent's own $outcomeLogger argument is pinned to Model\TriggerOutcomeLogger for this
 * class in etc/di.xml, the same "shared abstract constructor arg pinned per concrete subclass"
 * pattern Controller\ProductFeed\Index already uses for its own $feedGenerator.
 */
class RecordTriggerOutcome extends AbstractRecordOutcomeOnOrderSave
{
}
