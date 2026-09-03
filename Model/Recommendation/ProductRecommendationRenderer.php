<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Recommendation;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;

/**
 * Turns a list of SKUs into an inline-styled HTML fragment ("recommended for you" grid) suitable
 * for embedding straight into a campaign email body via {{var recommended_products_html|raw}} —
 * email HTML must be table-based with inline styles (no external CSS, no flexbox/grid), matching
 * this module's other shipped templates (see view/frontend/email/campaign_generic.html).
 *
 * A SKU that no longer resolves to a product (deleted/disabled between recommendation-compute
 * time and email-send time) is skipped silently — same handling FreeGiftManagement::addGiftItem
 * relies on ProductRepositoryInterface::get() for; a stale SKU in someone's recommendation list
 * isn't an error condition worth logging.
 */
class ProductRecommendationRenderer
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Escaper $escaper,
        private readonly PricingHelper $pricingHelper
    ) {
    }

    /**
     * @param string[] $skus
     */
    public function renderHtml(array $skus): string
    {
        if ($skus === []) {
            return '';
        }

        $cells = [];
        foreach ($skus as $sku) {
            try {
                /** @var Product $product ProductRepositoryInterface::get() always returns the
                 *  concrete Product model in practice — only its interface is declared. */
                $product = $this->productRepository->get($sku);
            } catch (NoSuchEntityException) {
                continue;
            }

            $cells[] = $this->renderProductCell($product);
        }

        if ($cells === []) {
            return '';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr class="recommended-products"><td>'
            . '<p style="font-weight:bold;margin:0 0 10px;">Recommended for you</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
            . implode('', $cells)
            . '</tr></table>'
            . '</td></tr></table>';
    }

    private function renderProductCell(Product $product): string
    {
        $name = $this->escaper->escapeHtml($product->getName());
        $url = $this->escaper->escapeHtml((string) $product->getProductUrl());
        $price = $this->escaper->escapeHtml((string) $this->pricingHelper->currency($product->getFinalPrice(), true, false));

        return '<td style="padding:10px;text-align:center;">'
            . '<a href="' . $url . '" style="text-decoration:none;color:#333333;">'
            . '<p style="margin:0 0 5px;">' . $name . '</p>'
            . '<p style="margin:0;font-weight:bold;">' . $price . '</p>'
            . '</a></td>';
    }
}
