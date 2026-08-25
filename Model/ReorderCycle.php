<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;

class ReorderCycle extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ReorderCycleResource::class);
    }
}
