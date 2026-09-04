<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\Recommendation\ProductRecommendationRenderer;
use Ordo\Automation\Model\Recommendation\ProductRecommender;

/**
 * Params: {"count": "4"} — how many products to recommend; a missing or non-numeric value
 * defaults to 4, same graceful-default pattern as this module's other simple actions. Context
 * must include "customer_id"; 0/missing is a no-op, matching sibling actions' fail-quiet
 * convention. Writes "recommended_products_html" into the context (even when it's an empty
 * string) so a later "send_email" action can render {{var recommended_products_html|raw}} — the
 * email template's own {{depend}} block handles the empty case, this action doesn't need to.
 */
class AddProductRecommendations implements ActionInterface
{
    private const int DEFAULT_COUNT = 4;

    public function __construct(
        private readonly ProductRecommender $productRecommender,
        private readonly ProductRecommendationRenderer $productRecommendationRenderer
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return;
        }

        $count = (int) ($params['count'] ?? self::DEFAULT_COUNT);
        if ($count <= 0) {
            $count = self::DEFAULT_COUNT;
        }

        $skus = $this->productRecommender->getRecommendedSkus($customerId, $count);
        $context['recommended_products_html'] = $this->productRecommendationRenderer->renderHtml($skus);
    }
}
