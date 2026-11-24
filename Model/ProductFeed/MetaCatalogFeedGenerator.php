<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Catalog\Model\Product;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;

/**
 * Builds a Meta Commerce Manager-compatible CSV product feed for the whole catalog — the second
 * feed format alongside GoogleMerchantFeedGenerator, which this class deliberately mirrors
 * structurally (same scope/skip rules, shared via AbstractProductFeedGenerator). CSV rather than
 * XML because Meta's own feed spec supports both and CSV is the simpler of the two to emit
 * correctly (no namespace, no per-field element nesting) — this module already has no existing
 * XML-vs-CSV precedent to match either way.
 *
 * Meta's Custom Audiences integration (Model\AdAudience\MetaSyncClient) pushes hashed customer
 * emails to the Marketing API; this is unrelated — a catalog feed is a URL Meta's crawler polls
 * on its own schedule, the same "publish a file, let the platform pull it" model as the Google
 * feed, not an API call this module makes itself.
 *
 * @see https://www.facebook.com/business/help/120325381656392 (feed spec / required fields)
 */
class MetaCatalogFeedGenerator extends AbstractProductFeedGenerator
{
    public const string FEED_CODE = 'meta_catalog';

    private const array CSV_HEADER = [
        'id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand',
    ];

    /** @var array<int, array<int, string>> */
    protected array $items = [];

    private string $defaultBrand = '';

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
        return 'text/csv; charset=UTF-8';
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->config->isMetaCatalogFeedEnabled($storeId);
    }

    public function startFeed(int $storeId): void
    {
        parent::startFeed($storeId);
        $this->defaultBrand = $this->config->getMetaCatalogFeedDefaultBrand($storeId);
    }

    public function finishFeed(): array
    {
        $lines = [$this->toCsvLine(self::CSV_HEADER)];
        foreach ($this->items as $row) {
            $lines[] = $this->toCsvLine($row);
        }

        return ['content' => implode("\r\n", $lines) . "\r\n", 'productCount' => count($this->items)];
    }

    /**
     * @return array<int, string>|null
     */
    protected function renderItem(Product $product): ?array
    {
        $fields = $this->extractCommonFields($product);
        if ($fields === null) {
            return null;
        }

        // Meta requires a brand per item; this module has no dedicated manufacturer/brand
        // attribute mapping (unlike Google, which has no equivalent required field), so the
        // configured store-wide default is the only source - a product with none configured is
        // skipped rather than emitted with a blank required field, same rule as a missing
        // price/image in extractCommonFields().
        if ($this->defaultBrand === '') {
            return null;
        }

        $priceValue = number_format($fields['price'], 2, '.', '') . ' ' . $this->currencyCode;

        return [
            $fields['sku'],
            $fields['name'],
            $fields['description'],
            $fields['inStock'] ? 'in stock' : 'out of stock',
            'new',
            $priceValue,
            $fields['url'],
            $fields['imageUrl'],
            $this->defaultBrand,
        ];
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
