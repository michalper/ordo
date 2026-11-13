<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Referral;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only grid over ordo_referral - every referral signup, with duplicate_ip_flag surfaced as
 * a filterable/sortable column for admin review. Mirrors Controller\Adminhtml\AnomalyAlert\Index,
 * the simplest existing grid controller in this module. See docs/adr/0055.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::referral';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::campaigns');
        $resultPage->getConfig()->getTitle()->prepend(__('Referrals'));

        return $resultPage;
    }
}
