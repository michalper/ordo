<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ScoreRule;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ScoreRule as ScoreRuleModel;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ScoreRuleModel::class, ScoreRuleResource::class);
    }
}
