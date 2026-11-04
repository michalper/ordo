<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Api\Data\CampaignWebsiteInterface;
use Ordo\Automation\Model\ResourceModel\Campaign\Website as CampaignWebsiteResource;

class CampaignWebsite extends AbstractModel implements CampaignWebsiteInterface
{
    protected function _construct(): void
    {
        $this->_init(CampaignWebsiteResource::class);
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

    public function getWebsiteId(): int
    {
        return (int) $this->getData(self::WEBSITE_ID);
    }

    public function setWebsiteId(int $websiteId): self
    {
        $this->setData(self::WEBSITE_ID, $websiteId);
        return $this;
    }
}
