<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

class NewAction extends AbstractCampaignAction
{
    public function execute()
    {
        return $this->_forward('edit');
    }
}
