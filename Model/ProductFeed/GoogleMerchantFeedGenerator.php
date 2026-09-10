<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Catalog\Helper\Image as CatalogImageHelper;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;

/**
 * Builds a Google Merchant Center-compatible RSS 2.0 + g: namespace product feed for the whole
 * catalog — distinct from Model\ContentBlock\Producer\ProductFeedProducer, which renders a small
 * curated HTML product grid inside a campaign email/on-site block, not an exportable feed file.
 *
 * Scope: enabled, catalog/search-visible products only (a not-visible-individually product has
 * no standalone product page for g:link to point at). One <item> per product; a product missing
 * a resolvable price or image is skipped rather than emitted with a blank required field, since
 * Google Merchant Center rejects/ignores items missing g:price or g:image_link anyway.
 *
 * @see https://support.google.com/merchants/answer/7052112 (feed spec)
 */
class GoogleMerchantFeedGenerator
{
    /**
     * Bounds how many product models the collection materializes in memory at once - without
     * this, generate() loaded the WHOLE catalog collection (every enabled, visible product, with
     * every EAV attribute join addAttributeToSelect() pulls in) in a single query/result set
     * before rendering a single <item>, which is exactly the "unbounded ... single-pass memory
     * build" ROADMAP.md Tier 4 flags as a real memory-exhaustion risk on a large catalog.
     */
    private const int PAGE_SIZE = 500;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CatalogImageHelper $catalogImageHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{xml: string, productCount: int}
     */
    public function generate(): array
    {
        /** @var \Magento\Store\Model\Store $store getCurrentCurrencyCode() isn't declared on
         *  StoreInterface, only the concrete Store model — same real-world usage as core's own
         *  currency-formatting code throughout Magento. */
        $store = $this->storeManager->getStore();
        $currencyCode = $store->getCurrentCurrencyCode();

        $items = [];
        foreach ($this->fetchProductsByPage() as $product) {
            $item = $this->renderItem($product, $currencyCode);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
            . '<title>' . $this->escape($this->config->getShoppingFeedTitle()) . '</title>'
            . '<link>' . $this->escape($store->getBaseUrl()) . '</link>'
            . '<description>' . $this->escape($this->config->getShoppingFeedDescription()) . '</description>'
            . implode('', $items)
            . '</channel></rss>';

        return ['xml' => $xml, 'productCount' => count($items)];
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
    private function fetchProductsByPage(): \Generator
    {
        $collection = $this->makeCollection();
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

    private function makeCollection(): ProductCollection
    {
        $collection = $this->productCollectionFactory->create();
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

    private function renderItem(\Magento\Catalog\Model\Product $product, string $currencyCode): ?string
    {
        $sku = (string) $product->getSku();
        $name = (string) $product->getName();
        $url = (string) $product->getProductUrl();
        $price = $product->getFinalPrice();

        if ($sku === '' || $name === '' || $url === '' || $price <= 0) {
            return null;
        }

        $imageUrl = $this->getImageUrl($product);
        if ($imageUrl === null) {
            return null;
        }

        $description = (string) $product->getData('description');
        $inStock = (bool) $product->getData('is_in_stock');
        $priceValue = number_format((float) $price, 2, '.', '');

        return '<item>'
            . '<g:id>' . $this->escape($sku) . '</g:id>'
            . '<title>' . $this->escape($name) . '</title>'
            . '<description>' . $this->escape($description) . '</description>'
            . '<link>' . $this->escape($url) . '</link>'
            . '<g:image_link>' . $this->escape($imageUrl) . '</g:image_link>'
            . '<g:condition>new</g:condition>'
            . '<g:availability>' . ($inStock ? 'in stock' : 'out of stock') . '</g:availability>'
            . '<g:price>' . $priceValue . ' ' . $this->escape($currencyCode) . '</g:price>'
            . '</item>';
    }

    private function getImageUrl(\Magento\Catalog\Model\Product $product): ?string
    {
        $url = $this->catalogImageHelper->init($product, 'product_page_image_large')->getUrl();
        return $url !== '' ? $url : null;
    }

    /**
     * Magento\Framework\Escaper::escapeHtml() has no ENT_XML1 equivalent (it always encodes as
     * HTML, not XML), so this deliberately calls htmlspecialchars() directly rather than
     * reaching for the discouraged-function sniff's suggested replacement, which would produce
     * invalid XML for values containing e.g. a literal "'" (HTML-encodes it as &#039;, which is
     * not a predefined XML entity name the way &amp;/&lt;/&gt;/&quot; are).
     */
    private function escape(string $value): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
