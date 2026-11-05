<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\App\Action;

abstract class AbstractEmailTemplateDraftAction extends Action
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::email_template_drafts';
}
