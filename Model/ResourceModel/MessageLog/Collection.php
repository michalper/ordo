<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\MessageLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\MessageLog as MessageLogModel;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(MessageLogModel::class, MessageLogResource::class);
    }
}
