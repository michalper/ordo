<?php
declare(strict_types=1);

namespace Ordo\Automation\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Ordo\Automation\Model\Campaign\AttributionCalculator;

/**
 * "Attributed revenue" column on the Campaign grid — total revenue Model\Campaign\
 * AttributionCalculator's multi-touch split (ordo_campaign_attribution) has credited to each
 * campaign so far. Deliberately separate from any existing sent/converted/conversion-rate
 * columns sourced from ordo_campaign_outcome_log (Model\CampaignOutcomeLogger::getStats()) —
 * that table's "revenue" is whichever single order first-plausible-matched as the conversion,
 * this column's revenue is the equal-weight multi-touch share across every campaign that
 * touched the customer, so the two numbers are expected to disagree and neither is "wrong."
 */
class CampaignAttributedRevenue extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly AttributionCalculator $attributionCalculator,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $campaignIds = array_map(static fn (array $item): int => (int) $item['entity_id'], $dataSource['data']['items']);
        $totals = $this->attributionCalculator->getAttributedRevenueForCampaigns($campaignIds);
        $fieldName = (string) $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            $revenue = $totals[(int) $item['entity_id']]['revenue'] ?? 0.0;
            $item[$fieldName] = number_format($revenue, 2);
        }

        return $dataSource;
    }
}
