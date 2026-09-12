<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ProductFeed;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\ProductFeed\FeedGeneratorPool;
use Ordo\Automation\Model\ProductFeed\ProductFeedCacheWriter;

/**
 * On-demand "refresh now" for every registered product feed format (FeedGeneratorPool), across
 * every store — synchronous, unlike Cron\RefreshProductFeed's own schedule. An admin who just
 * fixed a product/config issue wants to see it reflected in the feed immediately, not wait for
 * the next cron tick.
 */
class RefreshNow extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::product_feed';

    public function __construct(
        Context $context,
        private readonly FeedGeneratorPool $feedGeneratorPool,
        private readonly ProductFeedCacheWriter $productFeedCacheWriter,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('ordo/dashboard/index');

        $totalProducts = 0;
        $refreshed = 0;
        $errors = [];

        $stores = $this->storeManager->getStores();

        foreach ($this->feedGeneratorPool->getAll() as $generator) {
            foreach ($stores as $store) {
                $storeId = (int) $store->getId();

                if (!$generator->isEnabled($storeId)) {
                    continue;
                }

                try {
                    $result = $generator->generate($storeId);
                    $this->productFeedCacheWriter->writeSuccess(
                        $generator->getFeedCode(),
                        $storeId,
                        $result['content'],
                        $result['productCount']
                    );
                    $totalProducts += $result['productCount'];
                    $refreshed++;
                } catch (\Throwable $e) {
                    $this->productFeedCacheWriter->writeError($generator->getFeedCode(), $storeId, $e->getMessage());
                    $errors[] = sprintf('%s (%s): %s', $store->getName(), $generator->getFeedCode(), $e->getMessage());
                }
            }
        }

        if ($refreshed > 0) {
            $this->messageManager->addSuccessMessage(
                __('Product feeds refreshed for %1 store/format combination(s): %2 product(s) total.', $refreshed, $totalProducts)
            );
        }

        foreach ($errors as $error) {
            $this->messageManager->addErrorMessage(__('Product feed refresh failed: %1', $error));
        }

        return $resultRedirect;
    }
}
