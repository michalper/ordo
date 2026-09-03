<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ContentBlock;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;

/**
 * SKUs of enabled/in-stock-agnostic products directly assigned to a category, bounded by
 * $limit — the "source": "category" branch of a product_feed content block.
 */
class CategoryProductLister
{
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * @return string[]
     */
    public function getSkus(int $categoryId, int $limit): array
    {
        if ($categoryId <= 0 || $limit <= 0) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('sku');
        $collection->addCategoriesFilter(['eq' => [$categoryId]]);
        $collection->setPageSize($limit);

        $skus = [];
        foreach ($collection as $product) {
            $skus[] = (string) $product->getSku();
        }

        return $skus;
    }
}
