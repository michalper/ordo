<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Deletes raw visitor events past the configured retention window (default 7 days). This is
 * the enforcement mechanism for the design decision documented on ordo_visitor_event and in
 * README → Phase 5: raw behavioral events are not meant to accumulate indefinitely the way
 * ordo_campaign/ordo_customer_tag do — only the tags VisitorAggregator derives from them are
 * long-lived.
 */
class PruneVisitorEvents extends AbstractSingleColumnRetentionPruneCron
{
    public function __construct(
        private readonly Config $config,
        ResourceConnection $resourceConnection,
        CronRunLogger $cronRunLogger
    ) {
        parent::__construct($resourceConnection, $cronRunLogger);
    }

    protected function tableName(): string
    {
        return 'ordo_visitor_event';
    }

    protected function timestampColumn(): string
    {
        return 'created_at';
    }

    protected function retentionDays(): int
    {
        return $this->config->getTrackingRetentionDays();
    }

    protected function rowNounPhrase(): string
    {
        return 'visitor events';
    }
}
