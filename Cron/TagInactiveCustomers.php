<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\CustomerTagManager;

/**
 * Nightly pass tagging customers as "inactive" once they haven't ordered in the configured
 * window. This is pure data classification — SendWinBackEmails is the cron that actually
 * emails anyone, so the two responsibilities stay separate and either can be disabled alone.
 */
class TagInactiveCustomers
{
    public const TAG_INACTIVE = 'inactive';

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerTagManager $customerTagManager,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isLifecycleEmailsEnabled()) {
            return;
        }

        $inactiveDays = $this->config->getWinBackInactiveDays();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$inactiveDays} days"));

        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        // Registered customers whose most recent order (if any) is older than the cutoff,
        // or who have never ordered but registered before the cutoff.
        $customerTable = $this->resourceConnection->getTableName('customer_entity');

        $select = $connection->select()
            ->from(['c' => $customerTable], ['entity_id'])
            ->joinLeft(
                ['o' => $orderTable],
                'o.customer_id = c.entity_id',
                ['last_order_at' => new \Zend_Db_Expr('MAX(o.created_at)')]
            )
            ->where('c.created_at <= ?', $cutoff)
            ->group('c.entity_id')
            ->having('last_order_at IS NULL OR last_order_at <= ?', $cutoff);

        /** @var array<int, int|string> $customerIds */
        $customerIds = $connection->fetchCol($select);

        $stillInactiveIds = array_map(static fn (int|string $id): int => (int) $id, $customerIds);

        // Anyone previously tagged "inactive" who has since ordered again is no longer inactive —
        // syncTagForSet() clears that tag; also clear the dependent win-back-sent tag for each
        // one so a future dry spell can trigger a fresh win-back cycle instead of staying
        // permanently marked as "already won back".
        $result = $this->customerTagManager->syncTagForSet($stillInactiveIds, self::TAG_INACTIVE);
        foreach ($result['untagged'] as $customerId) {
            $this->customerTagManager->removeTag($customerId, SendWinBackEmails::TAG_WIN_BACK_SENT);
        }

        $this->cronRunLogger->logSummary(sprintf(
            'tagged %d customers as inactive, cleared %d',
            $result['tagged'],
            count($result['untagged'])
        ));
    }
}
