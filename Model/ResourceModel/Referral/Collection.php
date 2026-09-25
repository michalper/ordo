<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Referral;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\Referral as ReferralModel;
use Ordo\Automation\Model\ResourceModel\Referral as ReferralResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ReferralModel::class, ReferralResource::class);
    }

    public function addReferredCustomerFilter(int $customerId): self
    {
        $this->addFieldToFilter('referred_customer_id', ['eq' => $customerId]);
        return $this;
    }

    public function addStatusFilter(string $status): self
    {
        $this->addFieldToFilter('status', ['eq' => $status]);
        return $this;
    }
}
