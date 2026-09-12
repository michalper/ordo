<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\ProductFeed;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\ProductFeed\FeedGeneratorInterface;

/**
 * Public, unauthenticated endpoint a shopping-channel platform polls on its own schedule — same
 * trust model as Controller\Track\Event, no auth/CSRF concerns for a GET that only ever reads.
 * Always serves the cached row Cron\RefreshProductFeed last wrote for the current request's
 * store, never generates on demand (see that class's own doc for why). One concrete subclass per
 * feed format/route (Index = Google Merchant, MetaCatalog = Meta) so each keeps its own URL,
 * while the cache-lookup logic below — identical for every format — lives in exactly one place.
 */
abstract class AbstractFeedAction extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly RawFactory $resultRawFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly FeedGeneratorInterface $feedGenerator
    ) {
        parent::__construct($context);
    }

    public function execute(): Raw
    {
        $result = $this->resultRawFactory->create();
        $result->setHeader('Content-Type', $this->feedGenerator->getContentType());

        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->feedGenerator->isEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            return $result;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_product_feed_cache');
        $content = (string) $connection->fetchOne(
            $connection->select()
                ->from($table, 'xml')
                ->where('feed_code = ?', $this->feedGenerator->getFeedCode())
                ->where('store_id = ?', $storeId)
        );

        if ($content === '') {
            $result->setHttpResponseCode(404);
            return $result;
        }

        $result->setContents($content);

        return $result;
    }
}
