<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\OrderApproval;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * First in-backend way to browse/act on pending order approvals - previously only reachable via
 * the original decision email or the REST API, with no in-backend fallback if that email was
 * lost. See Ui\Component\Listing\Column\OrderApprovalActions for the actual approve/reject links.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::order_approval';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    protected function activeMenu(): string
    {
        return 'Ordo_Automation::campaigns';
    }

    protected function pageTitle(): Phrase
    {
        return __('Order Approvals');
    }
}
