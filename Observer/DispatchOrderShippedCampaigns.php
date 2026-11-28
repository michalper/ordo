<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;

class DispatchOrderShippedCampaigns extends AbstractDispatchOrderSubEntityCampaigns
{
    protected function triggerEventCode(): string
    {
        return 'order_shipped';
    }

    protected function eventObjectKey(): string
    {
        return 'shipment';
    }

    protected function isGenuinelyNew(Shipment|Creditmemo $subEntity): bool
    {
        return $subEntity->getOrigData(ShipmentInterface::ENTITY_ID) === null;
    }

    /**
     * @param Shipment $subEntity
     * @return array<string, mixed>
     */
    protected function extraContext(Shipment|Creditmemo $subEntity): array
    {
        $trackNumbers = [];
        $carrierTitles = [];
        foreach ($subEntity->getTracks() ?? [] as $track) {
            $trackNumbers[] = $track->getTrackNumber();
            $carrierTitles[] = $track->getTitle();
        }

        return [
            'shipment_id' => (int) $subEntity->getEntityId(),
            'tracking_numbers' => implode(', ', $trackNumbers),
            'carrier_titles' => implode(', ', $carrierTitles),
        ];
    }
}
