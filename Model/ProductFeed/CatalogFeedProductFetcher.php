<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Catalog\Helper\Image as CatalogImageHelper;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;

/**
 * The enabled/catalog-or-search-visible product collection + paging + image-URL lookup shared by
 * every feed format generator (GoogleMerchantFeedGenerator, MetaCatalogFeedGenerator) - extracted
 * after SonarCloud flagged it as duplicated between the two, since a feed format differs in how it
 * renders/encodes a product, never in which products it scopes in or how it pages through them.
 */
class CatalogFeedProductFetcher
{
    /**
     * Bounds how many product models the collection materializes in memory at once - without
     * this, a feed generator loaded the WHOLE catalog collection (every enabled, visible product,
     * with every EAV attribute join addAttributeToSelect() pulls in) in a single query/result set
     * before rendering a single item, a real memory-exhaustion risk on a large catalog.
     */
    private const int PAGE_SIZE = 500;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CatalogImageHelper $catalogImageHelper
    ) {
    }

    /**
     * Pages through the whole catalog PAGE_SIZE products at a time instead of loading it all at
     * once - one collection object reused across pages (setCurPage() + clear() between each), the
     * standard Magento pattern for iterating a large collection without holding every page's
     * loaded product models in memory simultaneously. A do/while (not a while-precheck loop) so
     * an empty catalog still runs the loop body once - getLastPageNumber() is only meaningful
     * after the first page has actually loaded.
     *
     * @return \Generator<int, \Magento\Catalog\Model\Product>
     */
    public function fetchByPage(int $storeId): \Generator
    {
        $collection = $this->makeCollection($storeId);
        $collection->setPageSize(self::PAGE_SIZE);

        $page = 1;
        do {
            $collection->setCurPage($page);
            $collection->load();

            foreach ($collection as $product) {
                /** @var \Magento\Catalog\Model\Product $product */
                yield $product;
            }

            $lastPage = $collection->getLastPageNumber();
            $collection->clear();
            $page++;
        } while ($page <= $lastPage);
    }

    public function getImageUrl(\Magento\Catalog\Model\Product $product): ?string
    {
        $url = $this->catalogImageHelper->init($product, 'product_page_image_large')->getUrl();
        return $url !== '' ? $url : null;
    }

    private function makeCollection(int $storeId): ProductCollection
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStore($storeId);
        $collection->addAttributeToSelect(['name', 'description', 'price']);
        $collection->addAttributeToFilter('status', ['eq' => 1]);
        $collection->addAttributeToFilter('visibility', [
            'in' => [Visibility::VISIBILITY_BOTH, Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_IN_SEARCH],
        ]);
        $collection->addFinalPrice();
        $collection->joinField(
            'is_in_stock',
            'cataloginventory_stock_item',
            'is_in_stock',
            'product_id=entity_id',
            null,
            'left'
        );

        return $collection;
    }
}
