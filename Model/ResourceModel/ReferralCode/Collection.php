<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ReferralCode;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ReferralCode as ReferralCodeModel;
use Ordo\Automation\Model\ResourceModel\ReferralCode as ReferralCodeResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ReferralCodeModel::class, ReferralCodeResource::class);
    }

    public function addCustomerFilter(int $customerId): self
    {
        $this->addFieldToFilter('customer_id', ['eq' => $customerId]);
        return $this;
    }

    public function addCodeFilter(string $code): self
    {
        $this->addFieldToFilter('code', ['eq' => $code]);
        return $this;
    }
}
