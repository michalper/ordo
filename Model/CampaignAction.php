<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Api\Data\CampaignActionInterface;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;

class CampaignAction extends AbstractModel implements CampaignActionInterface
{
    protected function _construct(): void
    {
        $this->_init(CampaignActionResource::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        $raw = (string) $this->getData('params');
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($decoded) ? $decoded : [];
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

    public function getType(): string
    {
        return (string) $this->getData(self::TYPE);
    }

    public function setType(string $type): self
    {
        $this->setData(self::TYPE, $type);
        return $this;
    }

    public function getParamsJson(): string
    {
        return (string) $this->getData(self::PARAMS);
    }

    public function setParamsJson(string $paramsJson): self
    {
        $this->setData(self::PARAMS, $paramsJson);
        return $this;
    }

    public function getSortOrder(): int
    {
        return (int) $this->getData(self::SORT_ORDER);
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
