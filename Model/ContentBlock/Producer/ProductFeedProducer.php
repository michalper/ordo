<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ContentBlock\Producer;

use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlock\CategoryProductLister;
use Ordo\Automation\Model\ContentBlock\RuleProductLister;
use Ordo\Automation\Model\Recommendation\ProductRecommendationRenderer;

/**
 * Config: {"source": "category"|"rule", "category_id": 15, "rule_id": 3, "item_count": 4}.
 * Resolves the configured SKU source, then reuses ProductRecommendationRenderer's grid markup
 * (same visual style as the "recommended for you" block a personalized email gets) with a
 * "New Arrivals" heading instead of a personalized one, since these SKUs aren't customer-specific.
 */
class ProductFeedProducer implements ProducerInterface
{
    private const int DEFAULT_ITEM_COUNT = 4;

    private const string HEADING = 'New Arrivals';

    public function __construct(
        private readonly CategoryProductLister $categoryProductLister,
        private readonly RuleProductLister $ruleProductLister,
        private readonly ProductRecommendationRenderer $productRecommendationRenderer
    ) {
    }

    public function render(ContentBlock $block): string
    {
        $config = $block->getConfigArray();
        $source = (string) ($config['source'] ?? '');
        $itemCount = (int) ($config['item_count'] ?? self::DEFAULT_ITEM_COUNT);
        if ($itemCount <= 0) {
            $itemCount = self::DEFAULT_ITEM_COUNT;
        }

        $skus = match ($source) {
            'category' => $this->categoryProductLister->getSkus((int) ($config['category_id'] ?? 0), $itemCount),
            'rule' => $this->ruleProductLister->getSkus((int) ($config['rule_id'] ?? 0), $itemCount),
            default => [],
        };

        if ($skus === []) {
            return '';
        }

        return $this->productRecommendationRenderer->renderHtml($skus, self::HEADING);
    }
}
