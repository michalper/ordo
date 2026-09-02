<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ScoreRule;

use Magento\Framework\App\Action\HttpGetActionInterface;

class NewAction extends AbstractScoreRuleAction implements HttpGetActionInterface
{
    public function execute()
    {
        return $this->_forward('edit');
    }
}
