<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\LoyaltyPointsReport\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Ordo\Automation\Model\LoyaltyPointsManager;
use Psr\Log\LoggerInterface;

/**
 * Backing collection for the standalone loyalty points liability/redemption report grid
 * (ordo_loyaltypointsreport_listing) - same "no real per-row entity, replace the FROM clause
 * entirely" departure CohortRetention\Grid\Collection's own docblock already explains and flags
 * as the only other place doing it. A row here is one calendar month, aggregated from
 * ordo_loyalty_points_ledger (append-only, see docs/adr/0044 and Model\LoyaltyPointsManager) -
 * there is no month entity table to join against.
 *
 * "outstanding_liability" is a running total (MySQL SUM() OVER (ORDER BY month), same window-
 * function technique CohortRetention's own ROW_NUMBER() OVER already establishes as safe for
 * this module's MySQL 8 target) - the LATEST row's value is the real, current, store-wide
 * unredeemed/unexpired point balance across every customer (the same SUM(points_delta) definition
 * Model\LoyaltyPointsManager::getBalance() uses per customer, just unfiltered here), which is
 * exactly the "real owed number a finance team needs" ROADMAP.md's own candidate asked for -
 * reading it off the grid's last row avoids a separate summary widget/endpoint for one number
 * this report's own trend column already produces as a side effect.
 *
 * "redemption_rate" is redeemed/earned for that month only (NULLIF guards the zero-earned-in-a-
 * month case, not divide-by-zero across the table) - a month with earns but no redemptions yet
 * (most months, right after this feature ships) reads 0%, not null, since "nobody redeemed yet"
 * is meaningfully different from "nobody earned anything to redeem against" (a month with zero
 * earned keeps redemption_rate null, since 0/0 has no meaningful rate at all).
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
        private readonly ResourceConnection $loyaltyPointsReportResourceConnection,
        $mainTable = 'ordo_loyalty_points_ledger',
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

        $connection = $this->loyaltyPointsReportResourceConnection->getConnection();
        $ledgerTable = $this->loyaltyPointsReportResourceConnection->getTableName('ordo_loyalty_points_ledger');

        $monthly = $connection->select()
            ->from($ledgerTable, [
                'month' => new \Zend_Db_Expr("DATE_FORMAT(created_at, '%Y-%m-01')"),
                'earned' => new \Zend_Db_Expr(
                    "SUM(CASE WHEN type = '" . LoyaltyPointsManager::TYPE_EARN . "' THEN points_delta ELSE 0 END)"
                ),
                'redeemed' => new \Zend_Db_Expr(
                    "SUM(CASE WHEN type = '" . LoyaltyPointsManager::TYPE_REDEEM . "' THEN -points_delta ELSE 0 END)"
                ),
                'expired' => new \Zend_Db_Expr(
                    "SUM(CASE WHEN type = '" . LoyaltyPointsManager::TYPE_EXPIRE . "' THEN -points_delta ELSE 0 END)"
                ),
                'net' => new \Zend_Db_Expr('SUM(points_delta)'),
            ])
            ->group('month');

        $withRunningTotal = $connection->select()
            ->from(
                ['m' => new \Zend_Db_Expr('(' . $monthly->assemble() . ')')],
                [
                    'entity_id' => 'm.month',
                    'month' => 'm.month',
                    'earned' => 'm.earned',
                    'redeemed' => 'm.redeemed',
                    'expired' => 'm.expired',
                    'net' => 'm.net',
                    'outstanding_liability' => new \Zend_Db_Expr('SUM(m.net) OVER (ORDER BY m.month)'),
                    'redemption_rate' => new \Zend_Db_Expr('ROUND(m.redeemed / NULLIF(m.earned, 0) * 100, 1)'),
                ]
            );

        $this->getSelect()->reset(Select::FROM);
        $this->getSelect()->from(['main_table' => new \Zend_Db_Expr('(' . $withRunningTotal->assemble() . ')')]);
    }
}
