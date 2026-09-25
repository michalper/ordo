<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\CreditLimitCalculator;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Model\TriggerOutcomeLogger;

/**
 * Most systems only react once a customer is already blocked at 100% of their credit limit.
 * This warns proactively at a configurable threshold (default 80%) and again if they cross 100%,
 * each band alerted at most once per cooldown period so a customer sitting at 95% doesn't get
 * emailed every single day.
 */
class SendCreditLimitAlerts
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_credit_limit_warning';
    private const string REMINDER_LOG_TABLE = 'ordo_credit_limit_alert_log';
    private const int OVER_LIMIT_BAND = 100;

    public function __construct(
        private readonly Config $config,
        private readonly CreditLimitCalculator $creditLimitCalculator,
        private readonly CustomerMapBuilder $customerMapBuilder,
        private readonly ReminderEmailSender $emailSender,
        private readonly ReminderLogStore $reminderLogStore,
        private readonly SalesRepEmailContext $salesRepEmailContext,
        private readonly ConsentManager $consentManager,
        private readonly TriggerOutcomeLogger $triggerOutcomeLogger,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isCreditLimitAlertEnabled()) {
            return;
        }

        $warningThreshold = $this->config->getCreditLimitWarningThreshold();
        $sent = 0;

        $customerIds = $this->creditLimitCalculator->getCustomerIdsWithCreditLimit();
        $customerMap = $this->customerMapBuilder->build($customerIds);
        // One GROUP BY query for every customer here instead of one query per customer inside
        // the loop below (Model\CreditLimitCalculator::getUsedCredit() would do that) - found via
        // a performance audit, real impact at a few thousand credit-limit customers.
        $usedCreditByCustomer = $this->creditLimitCalculator->getUsedCreditForCustomers($customerIds);
        // One query for the whole batch instead of one hasConsent() call per customer inside the
        // loop below - found via a performance audit, same reasoning as getUsedCreditForCustomers().
        $consentByCustomer = $this->consentManager->hasConsentForCustomers($customerIds, ConsentChannel::Email);

        foreach ($customerIds as $customerId) {
            if (!isset($customerMap[$customerId])) {
                continue;
            }

            $customer = $customerMap[$customerId];
            // getCreditLimitFromCustomer(), not getCreditLimit($customerId) - $customer is
            // already loaded above, so this avoids a second, redundant EAV round trip through
            // customerRepository->getById() for every customer in the loop.
            $limit = $this->creditLimitCalculator->getCreditLimitFromCustomer($customer);
            $used = $usedCreditByCustomer[$customerId] ?? 0.0;
            $utilization = $limit > 0.0 ? round(($used / $limit) * 100, 2) : 0.0;
            $band = $this->resolveBand($utilization, $warningThreshold);

            if ($band === null) {
                continue;
            }

            if ($this->alertedRecently($customerId, $band)) {
                continue;
            }

            // A customer who opted out of email must never receive this alert, same consent gate
            // every other channel's send action applies before sending anything.
            if (!($consentByCustomer[$customerId] ?? true)) {
                continue;
            }

            // Claim (log) BEFORE sending, not after - see ReminderLogStore::deleteMatching()'s
            // own docblock: a crash between a successful send and the log write must never cause
            // a duplicate alert next tick, and a genuine send failure rolls the claim back so
            // this customer/band is retried. claim() (not a plain insert()) re-checks the
            // cooldown window atomically - the cheap alertedRecently() check above is only a
            // fast pre-filter, not the actual guard against a double-send.
            $alertLogRow = $this->buildAlertLogRow($customerId, $band, $utilization);
            if (!$this->reminderLogStore->claim(
                self::REMINDER_LOG_TABLE,
                $this->alertLogMatchConditions($customerId, $band),
                $alertLogRow
            )) {
                continue;
            }

            try {
                $this->sendAlert($customer, $utilization, $limit, $used, $band);
                $this->triggerOutcomeLogger->logSent(TriggerOutcomeLogger::TRIGGER_CREDIT_LIMIT_ALERT, $customerId);
                $sent++;
            } catch (\Throwable $e) {
                $this->reminderLogStore->deleteMatching(self::REMINDER_LOG_TABLE, $alertLogRow);
                $this->cronRunLogger->logFailure(
                    sprintf('send credit limit alert for customer #%d', $customerId),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('sent %d credit limit alerts', $sent));
    }

    private function resolveBand(float $utilization, int $warningThreshold): ?int
    {
        if ($utilization >= self::OVER_LIMIT_BAND) {
            return self::OVER_LIMIT_BAND;
        }

        if ($utilization >= $warningThreshold) {
            return $warningThreshold;
        }

        return null;
    }

    private function alertedRecently(int $customerId, int $band): bool
    {
        return $this->reminderLogStore->countMatching(
            self::REMINDER_LOG_TABLE,
            $this->alertLogMatchConditions($customerId, $band)
        ) > 0;
    }

    /**
     * @return array<string, int|string>
     */
    private function alertLogMatchConditions(int $customerId, int $band): array
    {
        $cooldownDays = $this->config->getCreditLimitAlertCooldownDays();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$cooldownDays} days"));

        return [
            'customer_id = ?' => $customerId,
            'threshold_percent = ?' => $band,
            'sent_at >= ?' => $cutoff,
        ];
    }

    private function sendAlert(
        CustomerInterface $customer,
        float $utilization,
        float $limit,
        float $used,
        int $band
    ): void {
        $this->emailSender->send(
            self::XML_PATH_EMAIL_TEMPLATE,
            array_merge([
                'customer_name' => $customer->getFirstname(),
                'utilization_percent' => $utilization,
                'credit_limit' => $limit,
                'used_credit' => $used,
                'is_over_limit' => $band >= self::OVER_LIMIT_BAND,
                'is_within_limit' => $band < self::OVER_LIMIT_BAND,
            ], $this->salesRepEmailContext->getForLoadedCustomer($customer)),
            $customer->getEmail(),
            $customer->getFirstname()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAlertLogRow(int $customerId, int $band, float $utilization): array
    {
        return [
            'customer_id' => $customerId,
            'threshold_percent' => $band,
            'utilization_percent' => $utilization,
            'sent_at' => date('Y-m-d H:i:s'),
        ];
    }
}
