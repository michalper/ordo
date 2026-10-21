<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\MessageSendRetry;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\MessageSendRetry as MessageSendRetryModel;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry as MessageSendRetryResource;
use Ordo\Automation\Model\ResourceModel\RetryDueFilterTrait;

class Collection extends AbstractCollection
{
    use RetryDueFilterTrait;

    protected function _construct(): void
    {
        $this->_init(MessageSendRetryModel::class, MessageSendRetryResource::class);
    }
}
