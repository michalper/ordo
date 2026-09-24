<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Setup\Patch\Data\AddSalesRepAttributes;

/**
 * One digest email per rep instead of one alert per signal — a rep with 40 accounts should not
 * get 40 separate emails the day a batch of them goes inactive. Groups every customer currently
 * tagged "inactive" (TagInactiveCustomers) or "reorder cycle at risk" (
 * TagReorderCycleAtRiskCustomers) by their assigned rep's email and sends each rep a single
 * weekly list per signal — a rep with only one of the two signals still gets exactly one email,
 * not a second empty one, since sendDigest() is only called when at least one list is non-empty.
 */
class SendSalesRepDigest
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_sales_rep_digest';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerTagManager $customerTagManager,
        private readonly CustomerMapBuilder $customerMapBuilder,
        private readonly ReminderEmailSender $emailSender,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isSalesRepDigestEnabled()) {
            return;
        }

        $inactiveByRep = $this->groupCustomersByRep(TagInactiveCustomers::TAG_INACTIVE);
        $atRiskByRep = $this->groupCustomersByRep(TagReorderCycleAtRiskCustomers::TAG_REORDER_AT_RISK);

        $repEmails = array_unique(array_merge(array_keys($inactiveByRep), array_keys($atRiskByRep)));

        $sent = 0;
        foreach ($repEmails as $repEmail) {
            $inactiveNames = $inactiveByRep[$repEmail] ?? [];
            $atRiskNames = $atRiskByRep[$repEmail] ?? [];

            try {
                $this->sendDigest($repEmail, $inactiveNames, $atRiskNames);
                $sent++;
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf('send sales rep digest to %s', $repEmail),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('sent %d sales rep digests', $sent));
    }

    /**
     * Each entry is `['name' => 'Customer Name (customer_id)']`, not a plain string — Magento's
     * own `{{for}}` email template directive (`Magento\Framework\Filter\DirectiveProcessor\
     * ForDirective::getLoopReplacementText()`) silently `continue`s past any loop item that
     * isn't already an array or `DataObject`, so a plain `string[]` here renders as an empty
     * list every time (the count in the subject would still be right — only the `{{for name in
     * ...}}` body silently produces nothing). Confirmed by exercising the real cron end to end
     * (see docs/CHANGELOG.md) — no unit test mocking `EmailSender` catches this, since the bug is
     * in what the *real* template engine does with the shape of the data, not in this class's
     * own logic.
     *
     * @return array<string, array{name: string}[]> rep email => list of {name: "Customer Name (customer_id)"}
     */
    private function groupCustomersByRep(string $tag): array
    {
        $grouped = [];

        $customerIds = $this->customerTagManager->getCustomerIdsWithTag($tag);
        $customerMap = $this->customerMapBuilder->build($customerIds);

        foreach ($customerIds as $customerId) {
            if (!isset($customerMap[$customerId])) {
                continue;
            }

            $customer = $customerMap[$customerId];

            $repEmailAttribute = $customer->getCustomAttribute(AddSalesRepAttributes::ATTRIBUTE_REP_EMAIL);
            $repEmailValue = $repEmailAttribute ? $repEmailAttribute->getValue() : null;
            $repEmail = is_scalar($repEmailValue) ? (string) $repEmailValue : '';

            if ($repEmail === '') {
                continue;
            }

            $grouped[$repEmail][] = [
                'name' => trim($customer->getFirstname() . ' ' . $customer->getLastname())
                    . " (#{$customerId})",
            ];
        }

        return $grouped;
    }

    /**
     * @param array{name: string}[] $inactiveNames
     * @param array{name: string}[] $atRiskNames
     */
    private function sendDigest(string $repEmail, array $inactiveNames, array $atRiskNames): void
    {
        $this->emailSender->send(
            self::XML_PATH_EMAIL_TEMPLATE,
            [
                'customer_count' => count($inactiveNames),
                'customer_names' => $inactiveNames,
                'at_risk_count' => count($atRiskNames),
                'at_risk_customer_names' => $atRiskNames,
                'total_count' => count($inactiveNames) + count($atRiskNames),
            ],
            $repEmail
        );
    }
}
