<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

/**
 * On sales_order_save_after, NOT sales_order_place_after - moved here after a real CI run showed
 * the 'order_id' published below was always 0, never the real order: a fresh order's entity_id
 * is genuinely unpopulated at sales_order_place_after time for every normal (non-reorder)
 * checkout (see Observer\RecordCampaignOutcome's own docblock, which hit the identical trap for
 * an unrelated reason first). (int) null silently became (int) 0 here instead of throwing, so
 * nothing caught it until CampaignOutcomeLogger::markActed()'s self-exclusion (keyed on this
 * exact order_id) turned out to never match anything in AdminCampaignFunnelReflectsRealSendAndConversionTest.
 */
class DispatchOrderPlacedCampaigns extends AbstractDispatchOrderCampaigns
{
    protected function triggerEventCode(): string
    {
        return 'order_placed';
    }
}
