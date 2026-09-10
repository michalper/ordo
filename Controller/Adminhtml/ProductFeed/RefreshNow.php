<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ProductFeed;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ordo\Automation\Model\ProductFeed\GoogleMerchantFeedGenerator;
use Ordo\Automation\Model\ProductFeed\ProductFeedCacheWriter;

/**
 * On-demand "refresh now" for the shopping feed — synchronous, unlike Cron\RefreshProductFeed's
 * own schedule. An admin who just fixed a product/config issue wants to see it reflected in the
 * feed immediately, not wait for the next cron tick.
 */
class RefreshNow extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::product_feed';

    public function __construct(
        Context $context,
        private readonly GoogleMerchantFeedGenerator $googleMerchantFeedGenerator,
        private readonly ProductFeedCacheWriter $productFeedCacheWriter
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('ordo/dashboard/index');

        try {
            $result = $this->googleMerchantFeedGenerator->generate();
            $this->productFeedCacheWriter->writeSuccess($result['xml'], $result['productCount']);
            $this->messageManager->addSuccessMessage(
                __('Shopping feed refreshed: %1 product(s).', $result['productCount'])
            );
        } catch (\Throwable $e) {
            $this->productFeedCacheWriter->writeError($e->getMessage());
            $this->messageManager->addErrorMessage(__('Shopping feed refresh failed: %1', $e->getMessage()));
        }

        return $resultRedirect;
    }
}
