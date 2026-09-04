<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use Psr\Log\LoggerInterface;

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
        private readonly TriggerOutcomeLogger $triggerOutcomeLogger,
        private readonly LoggerInterface $logger
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

            try {
                $customer = $customerMap[$customerId];
                $this->emailSender->send(
                    self::XML_PATH_EMAIL_TEMPLATE,
                    $this->buildTemplateVars($cycle, $customer),
                    $customer->getEmail(),
                    $customer->getFirstname()
                );
                $this->logReminderSent((int) $cycle->getEntityId());
                $this->triggerOutcomeLogger->logSent(TriggerOutcomeLogger::TRIGGER_REORDER_REMINDER, $customerId);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        'Ordo_Automation: failed to send reorder reminder for cycle #%d: %s',
                        (int) $cycle->getEntityId(),
                        $e->getMessage()
                    )
                );
            }
        }

        $this->logger->info(sprintf('Ordo_Automation: sent %d reorder reminders.', $sent));
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
        ], $this->salesRepEmailContext->getForCustomer($cycle->getCustomerId()));
    }

    private function reminderAlreadySentToday(int $reorderCycleId): bool
    {
        return $this->reminderLogStore->countMatching(self::REMINDER_LOG_TABLE, [
            'reorder_cycle_id = ?' => $reorderCycleId,
            'DATE(sent_at) = ?' => date('Y-m-d'),
        ]) > 0;
    }

    private function logReminderSent(int $reorderCycleId): void
    {
        $this->reminderLogStore->insert(self::REMINDER_LOG_TABLE, [
            'reorder_cycle_id' => $reorderCycleId,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
