<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Catalog\Model\Product;
use Magento\Store\Model\StoreManagerInterface;
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
 * Same scope and skip-if-missing-a-required-field rule as the other two formats, shared via
 * AbstractProductFeedGenerator.
 */
class AiAgentFeedGenerator extends AbstractProductFeedGenerator
{
    public const string FEED_CODE = 'ai_agent';

    /**
     * @var array<int, array{
     *     sku: string, name: string, description: string, url: string, image_url: string,
     *     price: float, currency: string, in_stock: bool
     * }>
     */
    protected array $items = [];

    public function __construct(
        CatalogFeedProductFetcher $productFetcher,
        StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
        parent::__construct($productFetcher, $storeManager);
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

    public function finishFeed(): array
    {
        $content = (string) json_encode(
            ['products' => $this->items],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return ['content' => $content, 'productCount' => count($this->items)];
    }

    /**
     * @return array{
     *     sku: string, name: string, description: string, url: string, image_url: string,
     *     price: float, currency: string, in_stock: bool
     * }|null
     */
    protected function renderItem(Product $product): ?array
    {
        $fields = $this->extractCommonFields($product);
        if ($fields === null) {
            return null;
        }

        return [
            'sku' => $fields['sku'],
            'name' => $fields['name'],
            'description' => $fields['description'],
            'url' => $fields['url'],
            'image_url' => $fields['imageUrl'],
            'price' => round($fields['price'], 2),
            'currency' => $this->currencyCode,
            'in_stock' => $fields['inStock'],
        ];
    }
}
