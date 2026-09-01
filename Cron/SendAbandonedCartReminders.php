<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Finds quotes with items that have not been touched for the configured delay,
 * were not converted to an order, and belong to an identifiable customer/email,
 * then sends a one-time (or capped) recovery reminder.
 *
 * Also dispatches a "cart_abandoned" campaign event for quotes tied to a registered
 * customer, alongside the fixed reminder above — a store can layer a coupon or a tag
 * onto cart recovery via a campaign without touching this cron. Guest quotes (no
 * customer_id) only get the fixed email, since campaign conditions/actions here all
 * assume a real customer_id.
 *
 * @phpstan-type AbandonedCartRow array{
 *     entity_id: int|string,
 *     customer_id: int|string|null,
 *     customer_email: string,
 *     customer_firstname: string|null,
 *     subtotal: float|string,
 *     reminders_sent: int|string
 * }
 */
class SendAbandonedCartReminders
{
    private const XML_PATH_EMAIL_TEMPLATE = 'ordo_abandoned_cart_reminder';
    private const XML_PATH_EMAIL_SENDER = 'general';

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly QuoteFactory $quoteFactory,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isAbandonedCartEnabled()) {
            return;
        }

        $delayMinutes = $this->config->getAbandonedCartDelayMinutes();
        $minSubtotal = $this->config->getAbandonedCartMinSubtotal();
        $maxReminders = $this->config->getAbandonedCartMaxReminders();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$delayMinutes} minutes"));

        $connection = $this->resourceConnection->getConnection();
        $quoteTable = $this->resourceConnection->getTableName('quote');
        $logTable = $this->resourceConnection->getTableName('ordo_abandoned_cart_reminder_log');

        $select = $connection->select()
            ->from(['q' => $quoteTable], ['entity_id', 'customer_id', 'customer_email', 'customer_firstname', 'subtotal'])
            ->joinLeft(
                ['l' => $logTable],
                'l.quote_id = q.entity_id',
                ['reminders_sent' => new \Zend_Db_Expr('COUNT(l.entity_id)')]
            )
            ->where('q.is_active = 1')
            ->where('q.items_count > 0')
            ->where('q.updated_at <= ?', $cutoff)
            ->where('q.customer_email IS NOT NULL')
            ->where('q.subtotal >= ?', $minSubtotal)
            ->group('q.entity_id')
            ->having('reminders_sent < ?', $maxReminders);

        /** @var array<int, AbandonedCartRow> $rows */
        $rows = $connection->fetchAll($select);

        $sent = 0;
        foreach ($rows as $row) {
            try {
                $this->sendReminder($row);
                $this->logReminderSent((int) $row['entity_id']);
                $this->dispatchCampaigns($row);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        'Ordo_Automation: failed to send abandoned cart reminder for quote #%d: %s',
                        (int) $row['entity_id'],
                        $e->getMessage()
                    )
                );
            }
        }

        $this->logger->info(sprintf('Ordo_Automation: sent %d abandoned cart reminders.', $sent));
    }

    /**
     * @param AbandonedCartRow $row
     */
    private function sendReminder(array $row): void
    {
        $quote = $this->quoteFactory->create()->load((int) $row['entity_id']);
        $store = $this->storeManager->getStore();

        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = [
                'name' => $item->getName(),
                'qty' => $item->getQty(),
            ];
        }

        $this->inlineTranslation->suspend();

        $transport = $this->transportBuilder
            ->setTemplateIdentifier(self::XML_PATH_EMAIL_TEMPLATE)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
            ->setTemplateVars([
                'customer_name' => $row['customer_firstname'] ?: 'there',
                'cart_items' => $items,
                'cart_subtotal' => $row['subtotal'],
                'store' => $store,
            ])
            ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
            ->addTo($row['customer_email'], $row['customer_firstname'] ?: $row['customer_email'])
            ->getTransport();

        $transport->sendMessage();

        $this->inlineTranslation->resume();
    }

    /**
     * @param AbandonedCartRow $row
     */
    private function dispatchCampaigns(array $row): void
    {
        if (empty($row['customer_id'])) {
            return;
        }

        $this->campaignDispatcher->dispatch('cart_abandoned', [
            'customer_id' => (int) $row['customer_id'],
            'cart_subtotal' => (float) $row['subtotal'],
        ]);
    }

    private function logReminderSent(int $quoteId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_abandoned_cart_reminder_log');

        $connection->insert($table, [
            'quote_id' => $quoteId,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
