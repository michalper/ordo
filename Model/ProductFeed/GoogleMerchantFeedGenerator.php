<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\ProductFeed\FeedGeneratorInterface;
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
class GoogleMerchantFeedGenerator implements FeedGeneratorInterface
{
    public const string FEED_CODE = 'google_merchant';

    private int $storeId = 0;
    // The concrete Store, not StoreInterface - getCurrentCurrencyCode()/getBaseUrl() below aren't
    // declared on the interface, only the concrete model (same reasoning the old local @var
    // annotation gave before this became a property across startFeed()/finishFeed() calls).
    private \Magento\Store\Model\Store $store;
    private string $currencyCode = '';
    private \Magento\Directory\Model\Currency $baseCurrency;
    /** @var array<int, string> */
    private array $items = [];

    public function __construct(
        private readonly CatalogFeedProductFetcher $productFetcher,
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
        return 'application/xml; charset=UTF-8';
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->config->isShoppingFeedEnabled($storeId);
    }

    public function startFeed(int $storeId): void
    {
        $this->storeId = $storeId;
        /** @var \Magento\Store\Model\Store $store getCurrentCurrencyCode()/getBaseUrl() aren't
         *  declared on StoreInterface, only the concrete Store model — same real-world usage as
         *  core's own currency-formatting code throughout Magento. */
        $store = $this->storeManager->getStore($storeId);
        $this->store = $store;
        $this->currencyCode = $store->getCurrentCurrencyCode();
        $this->baseCurrency = $store->getBaseCurrency();
        $this->items = [];
    }

    public function addProduct(\Magento\Catalog\Model\Product $product): void
    {
        $item = $this->renderItem($product, $this->baseCurrency, $this->currencyCode);
        if ($item !== null) {
            $this->items[] = $item;
        }
    }

    public function finishFeed(): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
            . '<title>' . $this->escape($this->config->getShoppingFeedTitle($this->storeId)) . '</title>'
            . '<link>' . $this->escape($this->store->getBaseUrl()) . '</link>'
            . '<description>' . $this->escape($this->config->getShoppingFeedDescription($this->storeId)) . '</description>'
            . implode('', $this->items)
            . '</channel></rss>';

        return ['content' => $xml, 'productCount' => count($this->items)];
    }

    private function renderItem(
        \Magento\Catalog\Model\Product $product,
        \Magento\Directory\Model\Currency $baseCurrency,
        string $currencyCode
    ): ?string {
        $sku = (string) $product->getSku();
        $name = (string) $product->getName();
        $url = (string) $product->getProductUrl();
        $price = $product->getFinalPrice();

        if ($sku === '' || $name === '' || $url === '' || $price <= 0) {
            return null;
        }

        $imageUrl = $this->productFetcher->getImageUrl($product);
        if ($imageUrl === null) {
            return null;
        }

        $description = (string) $product->getData('description');
        $inStock = (bool) $product->getData('is_in_stock');
        // getFinalPrice() is base currency (from catalog_product_index_price) - it must be
        // converted to the store's current (display) currency before being tagged with
        // $currencyCode, or every price is off by the currency rate on a store where display
        // currency != base currency.
        $priceValue = number_format((float) $baseCurrency->convert($price, $currencyCode), 2, '.', '');

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
