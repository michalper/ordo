<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\CustomerTag as CustomerTagResource;

class CustomerTag extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(CustomerTagResource::class);
    }
}
