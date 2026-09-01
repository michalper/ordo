<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Api\Data\CampaignActionInterface;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;

class CampaignAction extends AbstractCampaignChildModel implements CampaignActionInterface
{
    protected function _construct(): void
    {
        $this->_init(CampaignActionResource::class);
    }

    public function setEntityId($entityId): self
    {
        $this->setData(self::ENTITY_ID, (int) $entityId);
        return $this;
    }

    public function setCampaignId(int $campaignId): self
    {
        $this->setData(self::CAMPAIGN_ID, $campaignId);
        return $this;
    }

    public function setType(string $type): self
    {
        $this->setData(self::TYPE, $type);
        return $this;
    }

    public function setParamsJson(string $paramsJson): self
    {
        $this->setData(self::PARAMS, $paramsJson);
        return $this;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->setData(self::SORT_ORDER, $sortOrder);
        return $this;
    }

    public function getDelayMinutes(): int
    {
        return (int) $this->getData(self::DELAY_MINUTES);
    }

    public function setDelayMinutes(int $delayMinutes): self
    {
        $this->setData(self::DELAY_MINUTES, $delayMinutes);
        return $this;
    }
}
