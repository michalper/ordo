<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Model\TriggerOutcomeLogger;

/**
 * Reads reorder cycles calculated by CalculateReorderCycle and, for the ones whose
 * predicted next-order date has arrived, sends a reminder email to the customer.
 * Each cycle is only reminded once per predicted date — see the reminder log table.
 */
class SendReorderReminders
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_reorder_reminder';
    private const string REMINDER_LOG_TABLE = 'ordo_reorder_reminder_log';

    public function __construct(
        private readonly Config $config,
        private readonly ReorderCycleCollectionFactory $reorderCycleCollectionFactory,
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
        if (!$this->config->isReorderReminderEnabled()) {
            return;
        }

        $leadDays = $this->config->getReorderLeadDays();
        $targetDate = date('Y-m-d', (int) strtotime("+{$leadDays} days"));

        $collection = $this->reorderCycleCollectionFactory->create();
        $collection->addDueTodayFilter($targetDate);

        $cycles = [];
        $customerIds = [];
        foreach ($collection as $cycle) {
            /** @var ReorderCycle $cycle */
            $cycles[] = $cycle;
            $customerIds[] = (int) $cycle->getCustomerId();
        }

        $customerMap = $this->customerMapBuilder->build($customerIds);
        // One query for the whole batch instead of one hasConsent() call per cycle inside the
        // loop below - found via a performance audit, same reasoning as
        // CreditLimitCalculator::getUsedCreditForCustomers().
        $consentByCustomer = $this->consentManager->hasConsentForCustomers($customerIds, ConsentChannel::Email);

        $sent = 0;
        foreach ($cycles as $cycle) {
            /** @var ReorderCycle $cycle */
            if ($this->reminderAlreadySentToday((int) $cycle->getEntityId())) {
                continue;
            }

            $customerId = (int) $cycle->getCustomerId();
            if (!isset($customerMap[$customerId])) {
                continue;
            }

            // A customer who opted out of email must never receive this reminder, same consent
            // gate every other channel's send action applies before sending anything.
            if (!($consentByCustomer[$customerId] ?? true)) {
                continue;
            }

            // Claim (log) BEFORE sending, not after - see ReminderLogStore::deleteMatching()'s
            // own docblock for why: a crash between a successful send and the log write must
            // never cause a resend next tick, and a genuine send failure rolls the claim back so
            // this cycle is retried. claim() (not a plain insert()) re-checks "already sent
            // today" atomically - the cheap reminderAlreadySentToday() check above is only a
            // fast pre-filter, not the actual guard against a double-send.
            $reminderLogRow = $this->buildReminderLogRow((int) $cycle->getEntityId());
            if (!$this->reminderLogStore->claim(
                self::REMINDER_LOG_TABLE,
                $this->reminderLogMatchConditions((int) $cycle->getEntityId()),
                $reminderLogRow
            )) {
                continue;
            }

            try {
                $customer = $customerMap[$customerId];
                $this->emailSender->send(
                    self::XML_PATH_EMAIL_TEMPLATE,
                    $this->buildTemplateVars($cycle, $customer),
                    $customer->getEmail(),
                    $customer->getFirstname()
                );
                $this->triggerOutcomeLogger->logSent(TriggerOutcomeLogger::TRIGGER_REORDER_REMINDER, $customerId);
                $sent++;
            } catch (\Throwable $e) {
                $this->reminderLogStore->deleteMatching(self::REMINDER_LOG_TABLE, $reminderLogRow);
                $this->cronRunLogger->logFailure(
                    sprintf('send reorder reminder for cycle #%d', (int) $cycle->getEntityId()),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('sent %d reorder reminders', $sent));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTemplateVars(ReorderCycle $cycle, CustomerInterface $customer): array
    {
        return array_merge([
            'customer_name' => $customer->getFirstname(),
            'sku' => $cycle->getSku(),
            'avg_interval_days' => $cycle->getAvgIntervalDays(),
        ], $this->salesRepEmailContext->getForLoadedCustomer($customer));
    }

    private function reminderAlreadySentToday(int $reorderCycleId): bool
    {
        return $this->reminderLogStore->countMatching(
            self::REMINDER_LOG_TABLE,
            $this->reminderLogMatchConditions($reorderCycleId)
        ) > 0;
    }

    /**
     * @return array<string, int|string>
     */
    private function reminderLogMatchConditions(int $reorderCycleId): array
    {
        return [
            'reorder_cycle_id = ?' => $reorderCycleId,
            'DATE(sent_at) = ?' => date('Y-m-d'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReminderLogRow(int $reorderCycleId): array
    {
        return [
            'reorder_cycle_id' => $reorderCycleId,
            'sent_at' => date('Y-m-d H:i:s'),
        ];
    }
}
