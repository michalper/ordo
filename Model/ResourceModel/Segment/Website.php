<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Segment;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Website extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_segment_website', 'entity_id');
    }
}
