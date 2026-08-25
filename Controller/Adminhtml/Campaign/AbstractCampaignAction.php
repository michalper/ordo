<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action;

abstract class AbstractCampaignAction extends Action
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::campaigns';
}
