<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Api\Data\CampaignConditionInterface;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;

class CampaignCondition extends AbstractCampaignChildModel implements CampaignConditionInterface
{
    protected function _construct(): void
    {
        $this->_init(CampaignConditionResource::class);
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
}
