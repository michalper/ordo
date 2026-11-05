<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\CampaignComparison\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Backing collection for the standalone cross-campaign comparison report grid
 * (ordo_campaigncomparison_listing) - same "no entity table of its own, everything in
 * _initSelect()" shape as Model\ResourceModel\RevenueAnalytics\Grid\Collection, but one row per
 * CAMPAIGN (ordo_campaign is the main table, so a campaign with zero sends still shows as an
 * honest 0/0/$0 row) rather than one row per order.
 *
 * Revenue comes from ordo_campaign_attribution (Model\Campaign\AttributionCalculator's
 * already-deduplicated equal-weight multi-touch split, unique on order_id+campaign_id), not
 * ordo_campaign_outcome_log.order_id - that column isn't unique (CampaignOutcomeLogger::markActed()
 * can credit more than one row to the same order, the same fan-out RevenueAnalytics\Grid\
 * Collection had to work around with a MIN(campaign_id) tie-break), and using it here would both
 * double-count revenue AND double-count sent/converted if two campaigns shared the same order.
 * sent/converted still come from ordo_campaign_outcome_log, grouped by campaign_id only (ignoring
 * variant - this report ranks CAMPAIGNS against each other, not split-test variants within one;
 * Model\CampaignFunnelStats already gives the per-variant breakdown for a single campaign).
 *
 * avg_converted_clv is the average ordo_customer_clv_score.clv_score (Model\Clv\ClvCalculator's
 * already-stored projection, not recomputed here) across the DISTINCT customers this campaign
 * converted - null, not 0, when a campaign has converted nobody yet, since 0 would misleadingly
 * read as "converts low-value customers" rather than "no data yet."
 */
class Collection extends SearchResult
{
    /**
     * @param string $mainTable
     * @param string|null $resourceModel
     * @param string|null $identifierName
     * @param string|null $connectionName
     */
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        private readonly ResourceConnection $campaignComparisonResourceConnection,
        $mainTable = 'ordo_campaign',
        $resourceModel = null,
        $identifierName = 'entity_id',
        $connectionName = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $identifierName,
            $connectionName
        );
    }

    protected function _initSelect(): void
    {
        parent::_initSelect();

        $connection = $this->campaignComparisonResourceConnection->getConnection();
        $outcomeLogTable = $this->campaignComparisonResourceConnection->getTableName('ordo_campaign_outcome_log');
        $attributionTable = $this->campaignComparisonResourceConnection->getTableName('ordo_campaign_attribution');
        $clvScoreTable = $this->campaignComparisonResourceConnection->getTableName('ordo_customer_clv_score');

        $outcomeStats = $connection->select()
            ->from($outcomeLogTable, [
                'campaign_id',
                'sent' => new \Zend_Db_Expr('COUNT(*)'),
                'converted' => new \Zend_Db_Expr('SUM(CASE WHEN acted_at IS NOT NULL THEN 1 ELSE 0 END)'),
            ])
            ->group('campaign_id');

        $revenueStats = $connection->select()
            ->from($attributionTable, [
                'campaign_id',
                'attributed_revenue' => new \Zend_Db_Expr('SUM(attributed_revenue)'),
            ])
            ->group('campaign_id');

        // Distinct (campaign_id, customer_id) first, THEN join to clv_score and average - a
        // customer converted more than once by the same campaign must only count once toward
        // that campaign's average, not once per conversion.
        $convertedCustomers = $connection->select()
            ->distinct()
            ->from($outcomeLogTable, ['campaign_id', 'customer_id'])
            ->where('acted_at IS NOT NULL');

        $clvStats = $connection->select()
            ->from(['cc' => new \Zend_Db_Expr('(' . $convertedCustomers->assemble() . ')')], ['campaign_id'])
            ->joinInner(['clv' => $clvScoreTable], 'clv.customer_id = cc.customer_id', [])
            ->columns(['avg_converted_clv' => new \Zend_Db_Expr('AVG(clv.clv_score)')])
            ->group('cc.campaign_id');

        $this->getSelect()
            ->joinLeft(
                ['outcome' => new \Zend_Db_Expr('(' . $outcomeStats->assemble() . ')')],
                'outcome.campaign_id = main_table.entity_id',
                []
            )
            ->joinLeft(
                ['revenue' => new \Zend_Db_Expr('(' . $revenueStats->assemble() . ')')],
                'revenue.campaign_id = main_table.entity_id',
                []
            )
            ->joinLeft(
                ['clvstats' => new \Zend_Db_Expr('(' . $clvStats->assemble() . ')')],
                'clvstats.campaign_id = main_table.entity_id',
                []
            )
            ->columns([
                'sent' => new \Zend_Db_Expr('COALESCE(outcome.sent, 0)'),
                'converted' => new \Zend_Db_Expr('COALESCE(outcome.converted, 0)'),
                'conversion_rate' => new \Zend_Db_Expr(
                    'CASE WHEN COALESCE(outcome.sent, 0) > 0'
                    . ' THEN ROUND((outcome.converted / outcome.sent) * 100, 1) ELSE 0 END'
                ),
                'revenue' => new \Zend_Db_Expr('COALESCE(revenue.attributed_revenue, 0)'),
                'avg_converted_clv' => 'clvstats.avg_converted_clv',
            ]);
    }
}
