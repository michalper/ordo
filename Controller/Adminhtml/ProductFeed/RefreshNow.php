<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ProductFeed;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ordo\Automation\Model\ProductFeed\ProductFeedRefresher;

/**
 * On-demand "refresh now" for every registered product feed format, across every store —
 * synchronous, unlike Cron\RefreshProductFeed's own schedule. An admin who just fixed a
 * product/config issue wants to see it reflected in the feed immediately, not wait for the next
 * cron tick. Shares the actual walk/generate/write logic with the cron via
 * Model\ProductFeed\ProductFeedRefresher — this controller only translates its result into admin
 * success/error messages.
 */
class RefreshNow extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ordo_Automation::product_feed';

    public function __construct(
        Context $context,
        private readonly ProductFeedRefresher $productFeedRefresher
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('ordo/dashboard/index');

        $result = $this->productFeedRefresher->refreshAll();

        if ($result['generated'] > 0) {
            $this->messageManager->addSuccessMessage(
                __(
                    'Product feeds refreshed for %1 store/format combination(s): %2 product(s) total.',
                    $result['generated'],
                    $result['totalProducts']
                )
            );
        }

        foreach ($result['errors'] as $error) {
            $this->messageManager->addErrorMessage(__('Product feed refresh failed: %1', $error));
        }

        return $resultRedirect;
    }
}
