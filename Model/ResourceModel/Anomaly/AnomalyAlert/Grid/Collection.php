<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Anomaly\AnomalyAlert\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Backing collection for the Anomaly Alerts grid (ordo_anomalyalert_listing) — joins in the
 * campaign's own name for display, since ordo_anomaly_alert only stores campaign_id (no FK, see
 * that table's own comment). A left join, not inner: an alert for a since-deleted campaign still
 * shows (campaign_name null), same as every other historical-log grid in this module.
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
        private readonly ResourceConnection $anomalyAlertResourceConnection,
        $mainTable = 'ordo_anomaly_alert',
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

        $campaignTable = $this->anomalyAlertResourceConnection->getTableName('ordo_campaign');

        $this->getSelect()
            ->joinLeft(
                ['campaign' => $campaignTable],
                'campaign.entity_id = main_table.campaign_id',
                ['campaign_name' => 'campaign.name']
            );
    }
}
