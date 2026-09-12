<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\ProductFeed;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\ProductFeed\MetaCatalogFeedGenerator;

/**
 * Serves the Meta Catalog CSV feed at "/ordo/productfeed/metacatalog" — see AbstractFeedAction
 * for the shared serving logic every feed format route uses.
 */
class MetaCatalog extends AbstractFeedAction
{
    public function __construct(
        Context $context,
        RawFactory $resultRawFactory,
        ResourceConnection $resourceConnection,
        StoreManagerInterface $storeManager,
        MetaCatalogFeedGenerator $feedGenerator
    ) {
        parent::__construct($context, $resultRawFactory, $resourceConnection, $storeManager, $feedGenerator);
    }
}
