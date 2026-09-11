<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CampaignDispatchDeadLetter extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_campaign_dispatch_dead_letter', 'entity_id');
    }
}
