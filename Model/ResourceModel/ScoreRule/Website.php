<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ScoreRule;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Website extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_score_rule_website', 'entity_id');
    }
}
