<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\PushSendRetry;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\PushSendRetry as PushSendRetryModel;
use Ordo\Automation\Model\ResourceModel\PushSendRetry as PushSendRetryResource;
use Ordo\Automation\Model\ResourceModel\RetryDueFilterTrait;

class Collection extends AbstractCollection
{
    use RetryDueFilterTrait;

    protected function _construct(): void
    {
        $this->_init(PushSendRetryModel::class, PushSendRetryResource::class);
    }
}
