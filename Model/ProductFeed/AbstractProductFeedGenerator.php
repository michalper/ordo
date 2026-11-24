<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Catalog\Model\Product;
use Magento\Directory\Model\Currency;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\ProductFeed\FeedGeneratorInterface;

/**
 * Shared by GoogleMerchantFeedGenerator/MetaCatalogFeedGenerator/AiAgentFeedGenerator - found
 * during a full-repo /simplify sweep: all three resolved the same store/currency scope in
 * startFeed(), accumulated one item per addProduct() call the same way, and rejected a product
 * missing the same required fields (sku, name, url, a positive price, a resolvable image) before
 * ever reaching their own format-specific rendering. Only finishFeed() (XML/CSV/JSON) and the
 * format-specific fields themselves (Google's g: tags, Meta's required brand, the AI agent's
 * plain field names) differ enough to stay per-subclass.
 */
abstract class AbstractProductFeedGenerator implements FeedGeneratorInterface
{
    protected int $storeId = 0;
    // The concrete Store, not StoreInterface - getCurrentCurrencyCode()/getBaseUrl() aren't
    // declared on the interface, only the concrete model.
    protected Store $store;
    protected string $currencyCode = '';
    protected Currency $baseCurrency;
    /** @var array<int, mixed> */
    protected array $items = [];

    public function __construct(
        protected readonly CatalogFeedProductFetcher $productFetcher,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function startFeed(int $storeId): void
    {
        $this->storeId = $storeId;
        /** @var Store $store getCurrentCurrencyCode()/getBaseUrl() aren't declared on
         *  StoreInterface, only the concrete Store model — same real-world usage as core's own
         *  currency-formatting code throughout Magento. */
        $store = $this->storeManager->getStore($storeId);
        $this->store = $store;
        $this->currencyCode = $store->getCurrentCurrencyCode();
        $this->baseCurrency = $store->getBaseCurrency();
        $this->items = [];
    }

    public function addProduct(Product $product): void
    {
        $item = $this->renderItem($product);
        if ($item !== null) {
            $this->items[] = $item;
        }
    }

    /**
     * One rendered item/row in this format's own shape, or null to skip the product - called
     * once per addProduct(), after startFeed() has already resolved $this->store/$currencyCode/
     * $baseCurrency for the batch.
     */
    abstract protected function renderItem(Product $product): mixed;

    /**
     * The validation and field extraction every format applies identically before its own
     * rendering: sku/name/url/a positive price are required, as is a resolvable image (Google
     * Merchant Center/Meta Commerce Manager both reject/ignore items missing their own
     * price/image field, and the AI agent feed has no reason to list a product with no usable
     * image either) - null means this product must be skipped, not emitted with a blank
     * required field. $price is already converted from getFinalPrice()'s base currency to the
     * store's display currency.
     *
     * @return array{
     *     sku: string, name: string, url: string, description: string, inStock: bool,
     *     imageUrl: string, price: float
     * }|null
     */
    protected function extractCommonFields(Product $product): ?array
    {
        $sku = (string) $product->getSku();
        $name = (string) $product->getName();
        $url = (string) $product->getProductUrl();
        $price = (float) $product->getFinalPrice();

        if ($sku === '' || $name === '' || $url === '' || $price <= 0) {
            return null;
        }

        $imageUrl = $this->productFetcher->getImageUrl($product);
        if ($imageUrl === null) {
            return null;
        }

        return [
            'sku' => $sku,
            'name' => $name,
            'url' => $url,
            'description' => (string) $product->getData('description'),
            'inStock' => (bool) $product->getData('is_in_stock'),
            'imageUrl' => $imageUrl,
            'price' => (float) $this->baseCurrency->convert($price, $this->currencyCode),
        ];
    }
}
