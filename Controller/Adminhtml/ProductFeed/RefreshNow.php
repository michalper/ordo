<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ProductFeed;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ProductFeed\GoogleMerchantFeedGenerator;
use Ordo\Automation\Model\ProductFeed\ProductFeedCacheWriter;

/**
 * On-demand "refresh now" for the shopping feed, across every store — synchronous, unlike
 * Cron\RefreshProductFeed's own schedule. An admin who just fixed a product/config issue wants to
 * see it reflected in the feed immediately, not wait for the next cron tick.
 */
class RefreshNow extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::product_feed';

    public function __construct(
        Context $context,
        private readonly GoogleMerchantFeedGenerator $googleMerchantFeedGenerator,
        private readonly ProductFeedCacheWriter $productFeedCacheWriter,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('ordo/dashboard/index');

        $totalProducts = 0;
        $storesRefreshed = 0;
        $errors = [];

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();

            if (!$this->config->isShoppingFeedEnabled($storeId)) {
                continue;
            }

            try {
                $result = $this->googleMerchantFeedGenerator->generate($storeId);
                $this->productFeedCacheWriter->writeSuccess($storeId, $result['xml'], $result['productCount']);
                $totalProducts += $result['productCount'];
                $storesRefreshed++;
            } catch (\Throwable $e) {
                $this->productFeedCacheWriter->writeError($storeId, $e->getMessage());
                $errors[] = sprintf('%s: %s', $store->getName(), $e->getMessage());
            }
        }

        if ($storesRefreshed > 0) {
            $this->messageManager->addSuccessMessage(
                __('Shopping feed refreshed for %1 store(s): %2 product(s) total.', $storesRefreshed, $totalProducts)
            );
        }

        foreach ($errors as $error) {
            $this->messageManager->addErrorMessage(__('Shopping feed refresh failed: %1', $error));
        }

        return $resultRedirect;
    }
}
