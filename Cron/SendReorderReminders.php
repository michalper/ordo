<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Model\TriggerOutcomeLogger;

/**
 * Reads reorder cycles calculated by CalculateReorderCycle and, for the ones whose
 * predicted next-order date has arrived, sends a reminder email to the customer.
 * Each cycle is only reminded once per predicted date — see the reminder log table.
 */
class SendReorderReminders extends AbstractBatchClaimedReminderCron
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_reorder_reminder';
    private const string REMINDER_LOG_TABLE = 'ordo_reorder_reminder_log';

    public function __construct(
        Config $config,
        private readonly ReorderCycleCollectionFactory $reorderCycleCollectionFactory,
        CustomerMapBuilder $customerMapBuilder,
        ReminderEmailSender $emailSender,
        ReminderLogStore $reminderLogStore,
        SalesRepEmailContext $salesRepEmailContext,
        ConsentManager $consentManager,
        TriggerOutcomeLogger $triggerOutcomeLogger,
        CronRunLogger $cronRunLogger,
        CustomerStoreResolver $customerStoreResolver
    ) {
        parent::__construct(
            $config,
            $customerMapBuilder,
            $emailSender,
            $reminderLogStore,
            $salesRepEmailContext,
            $consentManager,
            $triggerOutcomeLogger,
            $cronRunLogger,
            $customerStoreResolver
        );
    }

    protected function isEnabled(): bool
    {
        return $this->config->isReorderReminderEnabled();
    }

    protected function reminderLogTable(): string
    {
        return self::REMINDER_LOG_TABLE;
    }

    protected function emailTemplatePath(): string
    {
        return self::XML_PATH_EMAIL_TEMPLATE;
    }

    protected function consentTopic(): ConsentTopic
    {
        // AccountUpdates, not Promotional - a reorder reminder is about the customer's own
        // purchase pattern, see ConsentTopic::AccountUpdates's own docblock.
        return ConsentTopic::AccountUpdates;
    }

    protected function triggerOutcomeCode(): string
    {
        return TriggerOutcomeLogger::TRIGGER_REORDER_REMINDER;
    }

    protected function reminderLabel(): string
    {
        return 'reorder reminder';
    }

    protected function rowNoun(): string
    {
        return 'cycle';
    }

    protected function logIdColumn(): string
    {
        return 'reorder_cycle_id';
    }

    protected function logStaticConditions(): array
    {
        return ['DATE(sent_at) = ?' => date('Y-m-d')];
    }

    protected function fetchDueRows(): array
    {
        $leadDays = $this->config->getReorderLeadDays();
        $targetDate = date('Y-m-d', (int) strtotime("+{$leadDays} days"));

        $collection = $this->reorderCycleCollectionFactory->create();
        $collection->addDueTodayFilter($targetDate);

        $rows = [];
        foreach ($collection as $cycle) {
            /** @var ReorderCycle $cycle */
            $rows[] = [
                'id' => (int) $cycle->getEntityId(),
                'customerId' => (int) $cycle->getCustomerId(),
                'row' => $cycle,
            ];
        }

        return $rows;
    }

    protected function reminderLogMatchConditions(int $rowId): array
    {
        return [
            'reorder_cycle_id = ?' => $rowId,
            'DATE(sent_at) = ?' => date('Y-m-d'),
        ];
    }

    protected function buildReminderLogRow(int $rowId): array
    {
        return [
            'reorder_cycle_id' => $rowId,
            'sent_at' => date('Y-m-d H:i:s'),
        ];
    }

    protected function buildTemplateVars(object $row, CustomerInterface $customer): array
    {
        /** @var ReorderCycle $row */
        return array_merge([
            'customer_name' => $customer->getFirstname(),
            'sku' => $row->getSku(),
            'avg_interval_days' => $row->getAvgIntervalDays(),
        ], $this->salesRepEmailContext->getForLoadedCustomer($customer));
    }
}
