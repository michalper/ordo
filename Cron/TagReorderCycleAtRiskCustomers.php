<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ReorderCycle\ReorderCycleDriftCalculator;

/**
 * Nightly pass tagging customers whose reorder-cycle drift ratio (ReorderCycleDriftCalculator)
 * has reached the configured threshold — same "pure data classification, a separate cron does
 * the emailing" split as TagInactiveCustomers/SendWinBackEmails. Cron\SendSalesRepDigest reads
 * this tag the same way it already reads TagInactiveCustomers::TAG_INACTIVE.
 */
class TagReorderCycleAtRiskCustomers
{
    public const string TAG_REORDER_AT_RISK = 'reorder_cycle_at_risk';

    public function __construct(
        private readonly Config $config,
        private readonly ReorderCycleDriftCalculator $driftCalculator,
        private readonly CustomerTagManager $customerTagManager,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isReorderReminderEnabled()) {
            return;
        }

        $threshold = $this->config->getReorderAtRiskDriftRatio();

        $atRiskIds = [];
        foreach ($this->driftCalculator->getDriftRatiosForAllCustomers() as $customerId => $ratio) {
            if ($ratio >= $threshold) {
                $atRiskIds[] = $customerId;
            }
        }

        // Anyone previously tagged whose drift ratio has since dropped back below the threshold
        // (they ordered again, or the cycle was recalculated) is no longer at risk -
        // syncTagForSet() clears the tag so a future drift can flag them fresh.
        $result = $this->customerTagManager->syncTagForSet($atRiskIds, self::TAG_REORDER_AT_RISK);

        $this->cronRunLogger->logSummary(sprintf(
            'tagged %d customers as reorder-cycle-at-risk, cleared %d',
            $result['tagged'],
            count($result['untagged'])
        ));
    }
}
