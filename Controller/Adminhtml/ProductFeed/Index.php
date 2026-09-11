<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ProductFeed;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only health grid over ordo_product_feed_run_log - "did the shopping feed actually
 * generate successfully for each store, and when" is no longer invisible without a raw DB query
 * (the ROADMAP.md gap this closes, alongside multi-store support in
 * Model\ProductFeed\GoogleMerchantFeedGenerator).
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::product_feed';

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
        $resultPage->getConfig()->getTitle()->prepend(__('Product Feed Health'));

        return $resultPage;
    }
}
