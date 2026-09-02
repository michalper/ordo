<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ScoreRule extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_score_rule', 'entity_id');
    }
}
