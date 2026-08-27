<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Dashboard;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ordo\Automation\Api\Data\CampaignInterface;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;

/**
 * Reads directly from the collections (server-rendered), not the REST API — this page lives
 * inside the admin session already, so there's no separate auth/CORS story to solve.
 */
class DashboardViewModel implements ArgumentInterface
{
    private const TRIGGER_LABELS = [
        CampaignInterface::TRIGGER_ORDER_PLACED => 'Order Placed',
        CampaignInterface::TRIGGER_CUSTOMER_REGISTERED => 'Customer Registered',
        CampaignInterface::TRIGGER_TAG_ADDED => 'Tag Added',
        CampaignInterface::TRIGGER_CART_ABANDONED => 'Cart Abandoned',
    ];

    public function __construct(
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly ReorderCycleCollectionFactory $reorderCycleCollectionFactory,
        private readonly FreeGiftOfferCollectionFactory $freeGiftOfferCollectionFactory
    ) {
    }

    /**
     * @return CampaignInterface[]
     */
    public function getCampaigns(): array
    {
        $collection = $this->campaignCollectionFactory->create();
        $collection->setOrder('entity_id', 'DESC');

        return $collection->getItems();
    }

    public function getTotalCampaignCount(): int
    {
        return $this->campaignCollectionFactory->create()->getSize();
    }

    public function getEnabledCampaignCount(): int
    {
        $collection = $this->campaignCollectionFactory->create();
        $collection->addFieldToFilter('enabled', 1);

        return $collection->getSize();
    }

    public function getReorderCycleCount(): int
    {
        return $this->reorderCycleCollectionFactory->create()->getSize();
    }

    public function getFreeGiftOfferCount(): int
    {
        return $this->freeGiftOfferCollectionFactory->create()->getSize();
    }

    public function getTriggerLabel(string $triggerEvent): string
    {
        return self::TRIGGER_LABELS[$triggerEvent] ?? $triggerEvent;
    }
}
