<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Api\Data\ReorderCycleInterface;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;

class ReorderCycle extends AbstractModel implements ReorderCycleInterface
{
    protected function _construct(): void
    {
        $this->_init(ReorderCycleResource::class);
    }

    public function getEntityId(): ?int
    {
        $id = $this->getData(self::ENTITY_ID);
        return $id === null ? null : (int) $id;
    }

    public function getCustomerId(): int
    {
        return (int) $this->getData(self::CUSTOMER_ID);
    }

    public function getSku(): string
    {
        return (string) $this->getData(self::SKU);
    }

    public function getAvgIntervalDays(): int
    {
        return (int) $this->getData(self::AVG_INTERVAL_DAYS);
    }

    public function getLastOrderDate(): string
    {
        return (string) $this->getData(self::LAST_ORDER_DATE);
    }

    public function getNextExpectedDate(): string
    {
        return (string) $this->getData(self::NEXT_EXPECTED_DATE);
    }

    public function getOrdersConsidered(): int
    {
        return (int) $this->getData(self::ORDERS_CONSIDERED);
    }

    public function getUpdatedAt(): ?string
    {
        return $this->getData(self::UPDATED_AT);
    }
}
