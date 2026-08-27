<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;

class CampaignTrigger extends AbstractModel implements CampaignTriggerInterface
{
    protected function _construct(): void
    {
        $this->_init(CampaignTriggerResource::class);
    }

    public function getEntityId(): ?int
    {
        $id = $this->getData(self::ENTITY_ID);
        return $id === null ? null : (int) $id;
    }

    public function setEntityId($entityId): self
    {
        $this->setData(self::ENTITY_ID, (int) $entityId);
        return $this;
    }

    public function getCampaignId(): int
    {
        return (int) $this->getData(self::CAMPAIGN_ID);
    }

    public function setCampaignId(int $campaignId): self
    {
        $this->setData(self::CAMPAIGN_ID, $campaignId);
        return $this;
    }

    public function getTriggerEvent(): string
    {
        return (string) $this->getData(self::TRIGGER_EVENT);
    }

    public function setTriggerEvent(string $triggerEvent): self
    {
        $this->setData(self::TRIGGER_EVENT, $triggerEvent);
        return $this;
    }
}
