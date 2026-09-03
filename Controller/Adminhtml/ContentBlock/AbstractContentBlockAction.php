<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ContentBlock;

use Magento\Backend\App\Action;

abstract class AbstractContentBlockAction extends Action
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::content_blocks';
}
