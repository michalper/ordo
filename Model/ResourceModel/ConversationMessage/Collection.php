<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ConversationMessage;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ConversationMessage as ConversationMessageModel;
use Ordo\Automation\Model\ResourceModel\ConversationMessage as ConversationMessageResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ConversationMessageModel::class, ConversationMessageResource::class);
    }

    public function addCustomerFilter(int $customerId): self
    {
        $this->addFieldToFilter('customer_id', ['eq' => $customerId]);
        $this->setOrder('received_at', self::SORT_ORDER_DESC);

        return $this;
    }

    /**
     * The one query Model\Conversation\InboundMessageProcessor uses to best-effort resolve a
     * reply to a customer_id — reuses ordo_message_log rather than a separate phone-book table,
     * since every outbound send already recorded this exact phone -> customer_id link.
     */
    public function addFromAddressFilter(string $fromAddress): self
    {
        $this->addFieldToFilter('from_address', ['eq' => $fromAddress]);
        $this->setOrder('received_at', self::SORT_ORDER_DESC);

        return $this;
    }
}
