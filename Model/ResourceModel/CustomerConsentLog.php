<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CustomerConsentLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_customer_consent_log', 'entity_id');
    }
}
