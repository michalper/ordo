<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\CustomerConsentLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\CustomerConsentLog as CustomerConsentLogModel;
use Ordo\Automation\Model\ResourceModel\CustomerConsentLog as CustomerConsentLogResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CustomerConsentLogModel::class, CustomerConsentLogResource::class);
    }

    public function addCustomerFilter(int $customerId): self
    {
        $this->addFieldToFilter('customer_id', ['eq' => $customerId]);
        $this->setOrder('created_at', self::SORT_ORDER_DESC);
        return $this;
    }
}
