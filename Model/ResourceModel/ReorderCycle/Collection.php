<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ReorderCycle;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ReorderCycle as ReorderCycleModel;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ReorderCycleModel::class, ReorderCycleResource::class);
    }

    /**
     * Restrict the collection to cycles whose next expected order date has arrived (or passed)
     * and that have not been sent a reminder yet today.
     */
    public function addDueTodayFilter(string $today): self
    {
        $this->addFieldToFilter('next_expected_date', ['lteq' => $today]);
        return $this;
    }
}
