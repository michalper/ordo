<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;

class DispatchOrderRefundedCampaigns extends AbstractDispatchOrderSubEntityCampaigns
{
    protected function triggerEventCode(): string
    {
        return 'order_refunded';
    }

    protected function eventObjectKey(): string
    {
        return 'creditmemo';
    }

    protected function isGenuinelyNew(Shipment|Creditmemo $subEntity): bool
    {
        return $subEntity->getOrigData(CreditmemoInterface::ENTITY_ID) === null;
    }

    /**
     * @param Creditmemo $subEntity
     * @return array<string, mixed>
     */
    protected function extraContext(Shipment|Creditmemo $subEntity): array
    {
        return [
            'creditmemo_id' => (int) $subEntity->getEntityId(),
            // The credit memo's OWN total, deliberately a separate key from 'order_total' above
            // (the order's total) - see TRIGGER_ORDER_REFUNDED's own docblock for why conflating
            // the two would silently change what OrderTotalAtLeast means for this one trigger.
            'refund_total' => (float) $subEntity->getBaseGrandTotal(),
        ];
    }
}
