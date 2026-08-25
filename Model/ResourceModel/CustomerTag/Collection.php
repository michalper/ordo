<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\CustomerTag;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\CustomerTag as CustomerTagModel;
use Ordo\Automation\Model\ResourceModel\CustomerTag as CustomerTagResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CustomerTagModel::class, CustomerTagResource::class);
    }
}
