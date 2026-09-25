<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\ProductFeed\FeedGeneratorInterface;
use Ordo\Automation\Helper\Config;

/**
 * Builds a JSON product feed shaped for autonomous AI shopping agents rather than a specific
 * shopping-channel platform (contrast GoogleMerchantFeedGenerator/MetaCatalogFeedGenerator, which
 * follow those platforms' own field-naming conventions) - a flat array of objects with plain,
 * machine-readable field names (sku, name, price, currency, in_stock, url, image_url) an agent
 * can parse without knowing Google's/Meta's feed dialects. Advertised at
 * "/.well-known/ai-plugin.json" (Controller\WellKnown\AiPluginManifest) alongside this feed's own
 * URL, gated behind the same Config::isAiAgentEnabled() toggle.
 *
 * Same scope and skip-if-missing-a-required-field rule as the other two formats (enabled,
 * catalog/search-visible products only; a product missing a resolvable price or image is
 * skipped rather than emitted incomplete).
 */
class AiAgentFeedGenerator implements FeedGeneratorInterface
{
    public const string FEED_CODE = 'ai_agent';

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
        return 'application/json; charset=UTF-8';
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->config->isAiAgentEnabled($storeId);
    }

    /**
     * @return array{content: string, productCount: int}
     */
    public function generate(int $storeId): array
    {
        /** @var \Magento\Store\Model\Store $store getCurrentCurrencyCode() isn't declared on
         *  StoreInterface, only the concrete Store model — same real-world usage as
         *  GoogleMerchantFeedGenerator. */
        $store = $this->storeManager->getStore($storeId);
        $currencyCode = $store->getCurrentCurrencyCode();
        $baseCurrency = $store->getBaseCurrency();

        $items = [];
        foreach ($this->productFetcher->fetchByPage($storeId) as $product) {
            $item = $this->renderItem($product, $baseCurrency, $currencyCode);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $content = (string) json_encode(
            ['products' => $items],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return ['content' => $content, 'productCount' => count($items)];
    }

    /**
     * @return array{
     *     sku: string, name: string, description: string, url: string, image_url: string,
     *     price: float, currency: string, in_stock: bool
     * }|null
     */
    private function renderItem(
        \Magento\Catalog\Model\Product $product,
        \Magento\Directory\Model\Currency $baseCurrency,
        string $currencyCode
    ): ?array {
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

        return [
            'sku' => $sku,
            'name' => $name,
            'description' => (string) $product->getData('description'),
            'url' => $url,
            'image_url' => $imageUrl,
            // getFinalPrice() is base currency (catalog_product_index_price) - convert to the
            // store's display currency, same reasoning as GoogleMerchantFeedGenerator.
            'price' => round((float) $baseCurrency->convert($price, $currencyCode), 2),
            'currency' => $currencyCode,
            'in_stock' => (bool) $product->getData('is_in_stock'),
        ];
    }
}
