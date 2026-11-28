<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\LoyaltyPointsReport;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\RendersGridPageTrait;

/**
 * Read-only loyalty points liability/redemption report (one row per calendar month: earned,
 * redeemed, expired, net, running outstanding liability, redemption rate) - same shape as
 * Controller\Adminhtml\CohortRetention\Index.
 */
class Index extends Action implements HttpGetActionInterface
{
    use RendersGridPageTrait;

    public const ADMIN_RESOURCE = 'Ordo_Automation::loyalty_points_report';

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
        return __('Loyalty Points Report');
    }
}
