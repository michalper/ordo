<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

/**
 * On sales_order_save_after, NOT sales_order_place_after - moved here after a real CI run showed
 * the 'order_id' published below was always 0, never the real order: a fresh order's entity_id
 * is genuinely unpopulated at sales_order_place_after time for every normal (non-reorder)
 * checkout (see Observer\RecordCampaignOutcome's own docblock, which hit the identical trap for
 * an unrelated reason first). (int) null silently became (int) 0 here instead of throwing, so
 * nothing caught it until CampaignOutcomeLogger::markActed()'s self-exclusion (keyed on this
 * exact order_id) turned out to never match anything in AdminCampaignFunnelReflectsRealSendAndConversionTest.
 *
 * Unlike DispatchOrderCanceledCampaigns (wired to the targeted order_cancel_after, fired once by
 * Order::cancel() itself), sales_order_save_after fires on EVERY subsequent save of the same
 * order too - admin edits, status transitions, invoice/shipment/credit-memo creation, order
 * comments - found during a full-repo /code-review re-publishing 'order_placed' (and running
 * every matching campaign's full action chain again) on every one of those. shouldDispatch()
 * below restricts this to the genuine first save: AbstractModel::afterSave() dispatches this
 * event BEFORE updateStoredData() refreshes origData, so getOrigData(ENTITY_ID) still reflects
 * pre-save state here - unset only for an order that has never been loaded/saved before.
 */
class DispatchOrderPlacedCampaigns extends AbstractDispatchOrderCampaigns
{
    protected function triggerEventCode(): string
    {
        return 'order_placed';
    }

    protected function shouldDispatch(Order $order): bool
    {
        return $order->getOrigData(OrderInterface::ENTITY_ID) === null;
    }
}
