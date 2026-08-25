<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\OrderApproval;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\OrderApproval as OrderApprovalModel;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(OrderApprovalModel::class, OrderApprovalResource::class);
    }

    /**
     * Approvals still pending after the given cutoff timestamp — the escalation cron's input.
     */
    public function addStalePendingFilter(string $cutoff): self
    {
        $this->addFieldToFilter('status', OrderApprovalModel::STATUS_PENDING);
        $this->addFieldToFilter('created_at', ['lteq' => $cutoff]);
        return $this;
    }
}
