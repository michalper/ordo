<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Reads reorder cycles calculated by CalculateReorderCycle and, for the ones whose
 * predicted next-order date has arrived, sends a reminder email to the customer.
 * Each cycle is only reminded once per predicted date — see the reminder log table.
 */
class SendReorderReminders
{
    private const XML_PATH_EMAIL_TEMPLATE = 'ordo_reorder_reminder';
    private const XML_PATH_EMAIL_SENDER = 'general';

    public function __construct(
        private readonly Config $config,
        private readonly ReorderCycleCollectionFactory $reorderCycleCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isReorderReminderEnabled()) {
            return;
        }

        $leadDays = $this->config->getReorderLeadDays();
        $targetDate = date('Y-m-d', strtotime("+{$leadDays} days"));

        $collection = $this->reorderCycleCollectionFactory->create();
        $collection->addDueTodayFilter($targetDate);

        $sent = 0;
        foreach ($collection as $cycle) {
            if ($this->reminderAlreadySentToday((int) $cycle->getId())) {
                continue;
            }

            try {
                $this->sendReminder($cycle);
                $this->logReminderSent((int) $cycle->getId());
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        'Ordo_Automation: failed to send reorder reminder for cycle #%d: %s',
                        $cycle->getId(),
                        $e->getMessage()
                    )
                );
            }
        }

        $this->logger->info(sprintf('Ordo_Automation: sent %d reorder reminders.', $sent));
    }

    private function sendReminder(\Ordo\Automation\Model\ReorderCycle $cycle): void
    {
        $customer = $this->customerRepository->getById((int) $cycle->getData('customer_id'));
        $store = $this->storeManager->getStore();

        $this->inlineTranslation->suspend();

        $transport = $this->transportBuilder
            ->setTemplateIdentifier(self::XML_PATH_EMAIL_TEMPLATE)
            ->setTemplateOptions(['area' => \Magento\Framework\App\Area::AREA_FRONTEND, 'store' => $store->getId()])
            ->setTemplateVars([
                'customer_name' => $customer->getFirstname(),
                'sku' => $cycle->getData('sku'),
                'avg_interval_days' => $cycle->getData('avg_interval_days'),
                'store' => $store,
            ])
            ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
            ->addTo($customer->getEmail(), $customer->getFirstname())
            ->getTransport();

        $transport->sendMessage();

        $this->inlineTranslation->resume();
    }

    private function reminderAlreadySentToday(int $reorderCycleId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_reorder_reminder_log');

        $count = $connection->fetchOne(
            $connection->select()
                ->from($table, 'COUNT(*)')
                ->where('reorder_cycle_id = ?', $reorderCycleId)
                ->where('DATE(sent_at) = ?', date('Y-m-d'))
        );

        return (int) $count > 0;
    }

    private function logReminderSent(int $reorderCycleId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_reorder_reminder_log');

        $connection->insert($table, [
            'reorder_cycle_id' => $reorderCycleId,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
