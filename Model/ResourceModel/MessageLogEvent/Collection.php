<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\MessageLogEvent;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\MessageLogEvent as MessageLogEventModel;
use Ordo\Automation\Model\ResourceModel\MessageLogEvent as MessageLogEventResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(MessageLogEventModel::class, MessageLogEventResource::class);
    }

    public function addMessageLogIdFilter(int $messageLogId): self
    {
        $this->addFieldToFilter('message_log_id', ['eq' => $messageLogId]);

        return $this;
    }

    public function addEventTypeFilter(string $eventType): self
    {
        $this->addFieldToFilter('event_type', ['eq' => $eventType]);

        return $this;
    }
}
