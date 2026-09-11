<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\ProductFeed;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;

/**
 * Public, unauthenticated endpoint a shopping-channel platform (Google Merchant Center) polls on
 * its own schedule — same trust model as Controller\Track\Event, no auth/CSRF concerns for a GET
 * that only ever reads. Always serves the cached row Cron\RefreshProductFeed last wrote for the
 * current request's store, never generates on demand (see that class's own doc for why).
 */
class Index extends Action implements HttpGetActionInterface
{
    private const string FEED_CODE = 'google_merchant';

    public function __construct(
        Context $context,
        private readonly RawFactory $resultRawFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultRawFactory->create();
        $result->setHeader('Content-Type', 'application/xml; charset=UTF-8');

        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->config->isShoppingFeedEnabled($storeId)) {
            $result->setHttpResponseCode(404);
            return $result;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_product_feed_cache');
        $xml = (string) $connection->fetchOne(
            $connection->select()
                ->from($table, 'xml')
                ->where('feed_code = ?', self::FEED_CODE)
                ->where('store_id = ?', $storeId)
        );

        if ($xml === '') {
            $result->setHttpResponseCode(404);
            return $result;
        }

        $result->setContents($xml);

        return $result;
    }
}
