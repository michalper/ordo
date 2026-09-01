<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Segment;

use Magento\Backend\App\Action;

abstract class AbstractSegmentAction extends Action
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::segments';
}
