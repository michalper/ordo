<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ContentBlock extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_content_block', 'entity_id');
    }
}
