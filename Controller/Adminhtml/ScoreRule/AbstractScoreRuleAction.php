<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ScoreRule;

use Magento\Backend\App\Action;

abstract class AbstractScoreRuleAction extends Action
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::score_rules';
}
