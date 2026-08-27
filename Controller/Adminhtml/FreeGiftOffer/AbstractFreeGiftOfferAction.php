<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\App\Action;

abstract class AbstractFreeGiftOfferAction extends Action
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::free_gifts';
}
