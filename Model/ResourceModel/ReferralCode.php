<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ReferralCode extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_referral_code', 'entity_id');
    }
}
