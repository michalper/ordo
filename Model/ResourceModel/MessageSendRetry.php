<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class MessageSendRetry extends AbstractDb implements RetryRowResourceInterface
{
    use ClaimsRetryRowTrait;

    protected function _construct(): void
    {
        $this->_init('ordo_message_send_retry', 'entity_id');
    }
}
