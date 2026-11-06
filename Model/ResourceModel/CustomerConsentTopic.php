<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CustomerConsentTopic extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_customer_consent_topic', 'entity_id');
    }
}
