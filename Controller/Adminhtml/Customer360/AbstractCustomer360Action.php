<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Customer360;

use Magento\Backend\App\Action;

abstract class AbstractCustomer360Action extends Action
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::customer_360';
}
