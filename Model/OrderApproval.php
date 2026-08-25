<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;

class OrderApproval extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected function _construct(): void
    {
        $this->_init(OrderApprovalResource::class);
    }

    public function isPending(): bool
    {
        return $this->getData('status') === self::STATUS_PENDING;
    }
}
