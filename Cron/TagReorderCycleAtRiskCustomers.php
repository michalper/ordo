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

        // One query for the whole batch instead of one hasTag() call per candidate - same
        // reasoning/pattern as TagInactiveCustomers.
        $alreadyTagged = array_flip(
            $this->customerTagManager->getCustomerIdsWithTagFromSet($atRiskIds, self::TAG_REORDER_AT_RISK)
        );

        $tagged = 0;
        foreach ($atRiskIds as $customerId) {
            if (!isset($alreadyTagged[$customerId])) {
                $this->customerTagManager->addTag($customerId, self::TAG_REORDER_AT_RISK);
                $tagged++;
            }
        }

        // Anyone previously tagged whose drift ratio has since dropped back below the threshold
        // (they ordered again, or the cycle was recalculated) is no longer at risk - clear the
        // tag so a future drift can flag them fresh, same as TagInactiveCustomers untagging a
        // customer who has ordered again.
        $stillAtRiskLookup = array_flip($atRiskIds);
        $untagged = 0;
        foreach ($this->customerTagManager->getCustomerIdsWithTag(self::TAG_REORDER_AT_RISK) as $customerId) {
            if (!isset($stillAtRiskLookup[$customerId])) {
                $this->customerTagManager->removeTag($customerId, self::TAG_REORDER_AT_RISK);
                $untagged++;
            }
        }

        $this->cronRunLogger->logSummary(
            sprintf('tagged %d customers as reorder-cycle-at-risk, cleared %d', $tagged, $untagged)
        );
    }
}
