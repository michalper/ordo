<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\RevenueAnalytics\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Backing collection for the standalone revenue/channel analytics report grid
 * (ordo_revenueanalytics_listing) - same shape as Model\ResourceModel\ProductPerformance\Grid\
 * Collection: no entity table of its own, built entirely in _initSelect(). One row per order
 * (not per day/campaign/coupon) - coupon code, country/city and new-vs-returning are all
 * order-level facts, and the admin grid's own column filters/sort give "break down by X" for
 * free, so there's no need to pre-decide a single grouping dimension the way a classic BI
 * breakdown table would.
 *
 * Deliberately excludes a "device" column - unlike coupon_code (a flat sales_order column) and
 * country/city (a plain join to sales_order_address), no device/user-agent data is captured
 * anywhere in this codebase at order time or on ordo_visitor_event, and there is no visitor_id on
 * sales_order to join one in after the fact even if it were. Faking it with a speculative join
 * would be worse than leaving it out - see docs/adr/0017.
 *
 * campaign_name is left null for the (expected-to-be-common) case of an order with no row in
 * ordo_campaign_outcome_log at all - organic revenue, not an error. coupon_code is shown as-is;
 * it cannot be reliably attributed to "an Ordo-generated coupon" vs. a merchant-created one, since
 * Model\CouponGenerator sets exactly the same salesrule_coupon marker core's own coupon
 * mass-generator does - see docs/adr/0017.
 *
 * CampaignOutcomeLogger::markActed()'s own docblock says it can credit "the most recent un-acted
 * outcome row(s) ... across any campaign" to one order - i.e. ordo_campaign_outcome_log.order_id
 * is not unique, so a naive join would fan one order into several report rows and double-count
 * its revenue. $primaryOutcome below picks one campaign_id per order_id (the lowest, a stable if
 * arbitrary tie-break - this report shows "was a campaign involved and roughly which one", not a
 * claim of exact multi-touch attribution, same spirit as the first-plausible-match heuristic
 * markActed() itself already uses) before joining, so main_table stays one row per order.
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
        private readonly ResourceConnection $revenueAnalyticsResourceConnection,
        $mainTable = 'sales_order',
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

        $connection = $this->revenueAnalyticsResourceConnection->getConnection();
        $orderTable = $this->revenueAnalyticsResourceConnection->getTableName('sales_order');
        $addressTable = $this->revenueAnalyticsResourceConnection->getTableName('sales_order_address');
        $outcomeLogTable = $this->revenueAnalyticsResourceConnection->getTableName('ordo_campaign_outcome_log');
        $campaignTable = $this->revenueAnalyticsResourceConnection->getTableName('ordo_campaign');

        // One row per customer_id: their own earliest non-canceled order. Guest orders
        // (customer_id IS NULL) have no identity to compare repeats against, so they fall
        // through this join entirely and are bucketed as "new" below - the simplest honest
        // reading, not a claim that email-matching would make them a real returning customer.
        $firstOrders = $connection->select()
            ->from($orderTable, ['customer_id', 'first_order_id' => new \Zend_Db_Expr('MIN(entity_id)')])
            ->where('state != ?', 'canceled')
            ->where('customer_id IS NOT NULL')
            ->group('customer_id');

        $primaryOutcome = $connection->select()
            ->from($outcomeLogTable, ['order_id', 'campaign_id' => new \Zend_Db_Expr('MIN(campaign_id)')])
            ->where('order_id IS NOT NULL')
            ->group('order_id');

        $this->getSelect()
            ->where('main_table.state != ?', 'canceled')
            ->joinLeft(
                ['billing_address' => $addressTable],
                'billing_address.parent_id = main_table.entity_id AND billing_address.address_type = \'billing\'',
                [
                    'country_id' => 'billing_address.country_id',
                    'city' => 'billing_address.city',
                ]
            )
            ->joinLeft(
                ['outcome' => new \Zend_Db_Expr('(' . $primaryOutcome->assemble() . ')')],
                'outcome.order_id = main_table.entity_id',
                []
            )
            ->joinLeft(
                ['campaign' => $campaignTable],
                'campaign.entity_id = outcome.campaign_id',
                ['campaign_name' => 'campaign.name']
            )
            ->joinLeft(
                ['first_orders' => new \Zend_Db_Expr('(' . $firstOrders->assemble() . ')')],
                'first_orders.customer_id = main_table.customer_id',
                [
                    'is_new_customer' => new \Zend_Db_Expr(
                        'main_table.customer_id IS NULL OR first_orders.first_order_id = main_table.entity_id'
                    ),
                ]
            )
            ->columns([
                'revenue' => 'main_table.base_grand_total',
            ]);
    }
}
