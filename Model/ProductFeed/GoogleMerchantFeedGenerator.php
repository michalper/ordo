<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ProductFeed;

use Magento\Catalog\Model\Product;
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
 * Google Merchant Center rejects/ignores items missing g:price or g:image_link anyway. See
 * AbstractProductFeedGenerator for the scope/skip rules shared with the other feed formats.
 *
 * @see https://support.google.com/merchants/answer/7052112 (feed spec)
 */
class GoogleMerchantFeedGenerator extends AbstractProductFeedGenerator
{
    public const string FEED_CODE = 'google_merchant';

    /** @var array<int, string> */
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
        return 'application/xml; charset=UTF-8';
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->config->isShoppingFeedEnabled($storeId);
    }

    public function finishFeed(): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
            . '<title>' . $this->escape($this->config->getShoppingFeedTitle($this->storeId)) . '</title>'
            . '<link>' . $this->escape($this->store->getBaseUrl()) . '</link>'
            . '<description>'
            . $this->escape($this->config->getShoppingFeedDescription($this->storeId))
            . '</description>'
            . implode('', $this->items)
            . '</channel></rss>';

        return ['content' => $xml, 'productCount' => count($this->items)];
    }

    protected function renderItem(Product $product): ?string
    {
        $fields = $this->extractCommonFields($product);
        if ($fields === null) {
            return null;
        }

        $priceValue = number_format($fields['price'], 2, '.', '');

        return '<item>'
            . '<g:id>' . $this->escape($fields['sku']) . '</g:id>'
            . '<title>' . $this->escape($fields['name']) . '</title>'
            . '<description>' . $this->escape($fields['description']) . '</description>'
            . '<link>' . $this->escape($fields['url']) . '</link>'
            . '<g:image_link>' . $this->escape($fields['imageUrl']) . '</g:image_link>'
            . '<g:condition>new</g:condition>'
            . '<g:availability>' . ($fields['inStock'] ? 'in stock' : 'out of stock') . '</g:availability>'
            . '<g:price>' . $priceValue . ' ' . $this->escape($this->currencyCode) . '</g:price>'
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
