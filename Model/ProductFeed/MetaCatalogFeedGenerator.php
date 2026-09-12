<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Catalog\Helper\Image as CatalogImageHelper;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\ProductFeed\FeedGeneratorInterface;
use Ordo\Automation\Helper\Config;

/**
 * Builds a Meta Commerce Manager-compatible CSV product feed for the whole catalog — the second
 * feed format alongside GoogleMerchantFeedGenerator, which this class deliberately mirrors
 * structurally (same paging strategy, same skip-if-missing-a-required-field rule, same scope of
 * enabled/catalog-or-search-visible products only). CSV rather than XML because Meta's own feed
 * spec supports both and CSV is the simpler of the two to emit correctly (no namespace, no
 * per-field element nesting) — this module already has no existing XML-vs-CSV precedent to match
 * either way.
 *
 * Meta's Custom Audiences integration (Model\AdAudience\MetaSyncClient) pushes hashed customer
 * emails to the Marketing API; this is unrelated — a catalog feed is a URL Meta's crawler polls
 * on its own schedule, the same "publish a file, let the platform pull it" model as the Google
 * feed, not an API call this module makes itself.
 *
 * @see https://www.facebook.com/business/help/120325381656392 (feed spec / required fields)
 */
class MetaCatalogFeedGenerator implements FeedGeneratorInterface
{
    public const string FEED_CODE = 'meta_catalog';

    /** @see GoogleMerchantFeedGenerator::PAGE_SIZE */
    private const int PAGE_SIZE = 500;

    private const array CSV_HEADER = [
        'id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand',
    ];

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CatalogImageHelper $catalogImageHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    public function getFeedCode(): string
    {
        return self::FEED_CODE;
    }

    public function getContentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->config->isMetaCatalogFeedEnabled($storeId);
    }

    /**
     * @param int $storeId Which store's price/currency/base-URL scope (and default-brand
     *   config) to generate the feed for.
     * @return array{content: string, productCount: int}
     */
    public function generate(int $storeId): array
    {
        /** @var \Magento\Store\Model\Store $store getBaseCurrency()/getCurrentCurrencyCode()
         *  aren't declared on StoreInterface, only the concrete Store model — same real-world
         *  usage as GoogleMerchantFeedGenerator. */
        $store = $this->storeManager->getStore($storeId);
        $currencyCode = $store->getCurrentCurrencyCode();
        $baseCurrency = $store->getBaseCurrency();
        $defaultBrand = $this->config->getMetaCatalogFeedDefaultBrand($storeId);

        $rows = [];
        foreach ($this->fetchProductsByPage($storeId) as $product) {
            $row = $this->renderRow($product, $baseCurrency, $currencyCode, $defaultBrand);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $lines = [$this->toCsvLine(self::CSV_HEADER)];
        foreach ($rows as $row) {
            $lines[] = $this->toCsvLine($row);
        }

        return ['content' => implode("\r\n", $lines) . "\r\n", 'productCount' => count($rows)];
    }

    /**
     * @return \Generator<int, \Magento\Catalog\Model\Product>
     * @see GoogleMerchantFeedGenerator::fetchProductsByPage() same paging rationale.
     */
    private function fetchProductsByPage(int $storeId): \Generator
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

    /**
     * @return array<int, string>|null
     */
    private function renderRow(
        \Magento\Catalog\Model\Product $product,
        \Magento\Directory\Model\Currency $baseCurrency,
        string $currencyCode,
        string $defaultBrand
    ): ?array {
        $sku = (string) $product->getSku();
        $name = (string) $product->getName();
        $url = (string) $product->getProductUrl();
        $basePrice = (float) $product->getFinalPrice();

        if ($sku === '' || $name === '' || $url === '' || $basePrice <= 0) {
            return null;
        }

        $imageUrl = $this->getImageUrl($product);
        if ($imageUrl === null) {
            return null;
        }

        // Meta requires a brand per item; this module has no dedicated manufacturer/brand
        // attribute mapping (unlike Google, which has no equivalent required field), so the
        // configured store-wide default is the only source - a product with none configured is
        // skipped rather than emitted with a blank required field, same rule as a missing
        // price/image below.
        if ($defaultBrand === '') {
            return null;
        }

        $description = (string) $product->getData('description');
        $inStock = (bool) $product->getData('is_in_stock');

        // getFinalPrice() is always in the store's BASE currency, never its display currency —
        // convert() is required whenever those differ, exactly the multi-currency bug
        // ROADMAP.md flags for the Google feed (base price tagged with the display currency
        // code, no conversion). This generator gets it right from the start.
        $convertedPrice = $baseCurrency->convert($basePrice, $currencyCode);
        $priceValue = number_format($convertedPrice, 2, '.', '') . ' ' . $currencyCode;

        return [
            $sku,
            $name,
            $description,
            $inStock ? 'in stock' : 'out of stock',
            'new',
            $priceValue,
            $url,
            $imageUrl,
            $defaultBrand,
        ];
    }

    private function getImageUrl(\Magento\Catalog\Model\Product $product): ?string
    {
        $url = $this->catalogImageHelper->init($product, 'product_page_image_large')->getUrl();
        return $url !== '' ? $url : null;
    }

    /**
     * @param array<int, string> $fields
     */
    private function toCsvLine(array $fields): string
    {
        $escaped = array_map(static function (string $field): string {
            if (preg_match('/[",\r\n]/', $field) === 1) {
                return '"' . str_replace('"', '""', $field) . '"';
            }
            return $field;
        }, $fields);

        return implode(',', $escaped);
    }
}
