<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Dashboard;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ordo\Automation\Api\Data\CampaignInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;

/**
 * Reads directly from the collections (server-rendered), not the REST API — this page lives
 * inside the admin session already, so there's no separate auth/CORS story to solve.
 */
class DashboardViewModel implements ArgumentInterface
{
    private const TRIGGER_LABELS = [
        CampaignTriggerInterface::TRIGGER_ORDER_PLACED => 'Order Placed',
        CampaignTriggerInterface::TRIGGER_CUSTOMER_REGISTERED => 'Customer Registered',
        CampaignTriggerInterface::TRIGGER_TAG_ADDED => 'Tag Added',
        CampaignTriggerInterface::TRIGGER_CART_ABANDONED => 'Cart Abandoned',
        CampaignTriggerInterface::TRIGGER_VISITOR_TAG_ADDED => 'Visitor Tag Added (anonymous)',
    ];

    /**
     * The fixed, known trigger set (Model\Config\Source\TriggerEvent's list) — every one of
     * these always gets a dashboard row, including a trigger no campaign currently uses (0),
     * rather than only showing rows for triggers that happen to already have a campaign. That's
     * the point of a "per fixed trigger" breakdown: it answers "which of the triggers we
     * support are actually being used" just as much as "how many campaigns use each one".
     */
    private const FIXED_TRIGGER_EVENTS = [
        CampaignTriggerInterface::TRIGGER_ORDER_PLACED,
        CampaignTriggerInterface::TRIGGER_CUSTOMER_REGISTERED,
        CampaignTriggerInterface::TRIGGER_TAG_ADDED,
        CampaignTriggerInterface::TRIGGER_CART_ABANDONED,
        CampaignTriggerInterface::TRIGGER_VISITOR_TAG_ADDED,
    ];

    public function __construct(
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
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

        $campaigns = [];
        foreach ($collection as $campaign) {
            /** @var Campaign $campaign */
            $campaigns[] = $campaign;
        }

        return $campaigns;
    }

    public function getTotalCampaignCount(): int
    {
        return $this->campaignCollectionFactory->create()->getSize();
    }

    public function getEnabledCampaignCount(): int
    {
        $collection = $this->campaignCollectionFactory->create();
        $collection->addFieldToFilter('enabled', '1');

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

    /**
     * How many campaigns use this trigger. One row per (campaign_id, trigger_event) — the
     * unique constraint on ordo_campaign_trigger (see Save.php's saveTriggers()) means a
     * campaign can never have the same trigger twice, so a plain filtered count is already an
     * accurate campaign count, no explicit DISTINCT needed.
     */
    public function getCampaignCountForTrigger(string $triggerEvent): int
    {
        $collection = $this->campaignTriggerCollectionFactory->create();
        $collection->addFieldToFilter('trigger_event', $triggerEvent);

        return $collection->getSize();
    }

    /**
     * @return string[]
     */
    public function getFixedTriggerEvents(): array
    {
        return self::FIXED_TRIGGER_EVENTS;
    }

    /**
     * A campaign can fire on more than one trigger event now — the dashboard card shows all of
     * them, comma-separated, rather than just the first.
     */
    public function getTriggerLabelsForCampaign(int $campaignId): string
    {
        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addCampaignFilter($campaignId);

        $labels = [];
        foreach ($triggers as $trigger) {
            /** @var CampaignTrigger $trigger */
            $labels[] = $this->getTriggerLabel($trigger->getTriggerEvent());
        }

        return $labels ? implode(', ', $labels) : (string) __('No trigger configured');
    }
}
