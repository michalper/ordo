<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ChurnRisk\ChurnRiskCalculator;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\CustomerTagManager;

/**
 * Nightly pass tagging customers whose churn-risk ratio (ChurnRiskCalculator - elapsed days
 * since last order, divided by their own median order interval) has reached the configured
 * "at risk" threshold - mirrors Cron\TagReorderCycleAtRiskCustomers's exact add-then-clear-on-
 * recovery shape, generalized the same way Model\Campaign\Condition\ChurnRiskAtLeast already
 * generalizes ReorderCycleAtRisk to every customer with at least 2 orders, not just customers
 * with an already-detected, SKU-specific reorder cycle. Cron\SendSalesRepDigest reads this tag
 * the same way it already reads TagReorderCycleAtRiskCustomers::TAG_REORDER_AT_RISK.
 */
class TagChurnRiskCustomers
{
    public const string TAG_CHURN_RISK = 'churn_risk';

    public function __construct(
        private readonly Config $config,
        private readonly ChurnRiskCalculator $churnRiskCalculator,
        private readonly CustomerTagManager $customerTagManager,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isChurnRiskDigestEnabled()) {
            return;
        }

        $threshold = $this->config->getChurnRiskAtRiskRatio();

        $atRiskIds = [];
        foreach ($this->churnRiskCalculator->getChurnRiskRatiosForAllCustomers() as $customerId => $ratio) {
            if ($ratio >= $threshold) {
                $atRiskIds[] = $customerId;
            }
        }

        // One query for the whole batch instead of one hasTag() call per candidate - same
        // reasoning/pattern as TagReorderCycleAtRiskCustomers.
        $alreadyTagged = array_flip(
            $this->customerTagManager->getCustomerIdsWithTagFromSet($atRiskIds, self::TAG_CHURN_RISK)
        );

        $tagged = 0;
        foreach ($atRiskIds as $customerId) {
            if (!isset($alreadyTagged[$customerId])) {
                $this->customerTagManager->addTag($customerId, self::TAG_CHURN_RISK);
                $tagged++;
            }
        }

        // Anyone previously tagged whose ratio has since dropped back below the threshold (they
        // ordered again) is no longer at risk - clear the tag so a future drift can flag them
        // fresh, same as TagReorderCycleAtRiskCustomers untagging a customer who has recovered.
        $stillAtRiskLookup = array_flip($atRiskIds);
        $untagged = 0;
        foreach ($this->customerTagManager->getCustomerIdsWithTag(self::TAG_CHURN_RISK) as $customerId) {
            if (!isset($stillAtRiskLookup[$customerId])) {
                $this->customerTagManager->removeTag($customerId, self::TAG_CHURN_RISK);
                $untagged++;
            }
        }

        $this->cronRunLogger->logSummary(
            sprintf('tagged %d customers as churn-risk, cleared %d', $tagged, $untagged)
        );
    }
}
