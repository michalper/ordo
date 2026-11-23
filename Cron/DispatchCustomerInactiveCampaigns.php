<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;

/**
 * Finds every enabled campaign's own `customer_inactive` trigger and, for each one, dispatches
 * it (via CampaignDispatcher::dispatchScheduledTrigger() - a specific campaign_id, not the
 * generic dispatch() that fans out to every campaign listening for an event name) for every
 * registered customer whose most recent non-canceled order - or registration, if they never
 * ordered - is older than that trigger's own `days` param.
 *
 * Unlike the already-existing Cron\TagInactiveCustomers/SendWinBackEmails (a single, fixed-
 * threshold, non-composable win-back email), this is a real campaign trigger: a store can build
 * several differently-shaped win-back campaigns at different inactivity windows (e.g. a 30-day
 * nudge and a separate 90-day "we miss you" with a bigger incentive), each condition/action chain
 * fully composable in the Flow builder - see docs/adr/0030. The two features are independent and
 * can both be enabled at once; this one does not read or write Cron\TagInactiveCustomers' own
 * "inactive" tag at all, it re-derives inactivity directly from sales_order per trigger, since
 * each trigger can have a different `days` threshold the shared tag can't represent.
 *
 * ordo_customer_inactive_trigger_claim is a plain append-only log, not a one-dispatch-per-
 * customer-ever claim (unlike ordo_review_request_log) - a customer can leave and re-enter
 * inactivity more than once, so re-firing is allowed once Config::
 * getCustomerInactiveRefireCooldownDays() has passed since this same (customer_id, campaign_id)
 * pair last fired, the same cooldown-window shape Cron\SendCreditLimitAlerts already uses.
 */
class DispatchCustomerInactiveCampaigns extends AbstractTriggerRowClaimedDispatchCron
{
    private const string CLAIM_TABLE = 'ordo_customer_inactive_trigger_claim';

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        CampaignDispatcher $campaignDispatcher,
        ReminderLogStore $reminderLogStore,
        CronRunLogger $cronRunLogger
    ) {
        parent::__construct($campaignTriggerCollectionFactory, $campaignDispatcher, $reminderLogStore, $cronRunLogger);
    }

    protected function isEnabled(): bool
    {
        return $this->config->isCustomerInactiveTriggerEnabled();
    }

    protected function triggerEventCode(): string
    {
        return CampaignTriggerInterface::TRIGGER_CUSTOMER_INACTIVE;
    }

    protected function claimTable(): string
    {
        return self::CLAIM_TABLE;
    }

    protected function candidateCustomerIds(CampaignTrigger $trigger): ?array
    {
        $days = (int) ($trigger->getParams()['days'] ?? 0);
        if ($days <= 0) {
            return null;
        }

        return $this->findInactiveCustomerIds($days);
    }

    protected function buildClaimRow(CampaignTrigger $trigger, int $customerId): array
    {
        $campaignId = $trigger->getCampaignId();
        $cooldownDays = $this->config->getCustomerInactiveRefireCooldownDays();
        $cooldownCutoff = date('Y-m-d H:i:s', (int) strtotime("-{$cooldownDays} days"));

        $match = [
            'customer_id = ?' => $customerId,
            'campaign_id = ?' => $campaignId,
            'fired_at >= ?' => $cooldownCutoff,
        ];

        // fired_at is set explicitly here (rather than left to the column's own
        // CURRENT_TIMESTAMP default) so it's part of the claim row - this table is a plain
        // append-only log, not a one-row-ever claim (a customer can leave and re-enter
        // inactivity more than once, see this class's own docblock), so on a genuine dispatch
        // failure, rolling back via deleteMatching() must match only the one row this claim()
        // call just inserted, not every historical fired_at for this same (customer_id,
        // campaign_id) pair - which an omitted fired_at would do, silently wiping this
        // customer's whole cooldown history on a single transient failure. Same explicit-value
        // pattern DispatchCustomerAnniversaryCampaigns already uses for its own fired_year.
        $claim = ['customer_id' => $customerId, 'campaign_id' => $campaignId, 'fired_at' => date('Y-m-d H:i:s')];

        return ['match' => $match, 'claim' => $claim];
    }

    /**
     * Same "most recent order, or registration if they never ordered, older than the cutoff"
     * shape as Cron\TagInactiveCustomers' own query - deliberately not reused as a shared helper
     * since that cron's own cutoff is one fixed global config value, while this one needs a
     * different cutoff per trigger (each campaign's own `days` param).
     *
     * @return int[]
     */
    private function findInactiveCustomerIds(int $days): array
    {
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$days} days"));

        $connection = $this->resourceConnection->getConnection();
        $customerTable = $this->resourceConnection->getTableName('customer_entity');
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $select = $connection->select()
            ->from(['c' => $customerTable], ['entity_id'])
            ->joinLeft(
                ['o' => $orderTable],
                'o.customer_id = c.entity_id AND o.state != \'canceled\'',
                ['last_order_at' => new \Zend_Db_Expr('MAX(o.created_at)')]
            )
            ->where('c.created_at <= ?', $cutoff)
            ->group('c.entity_id')
            ->having('last_order_at IS NULL OR last_order_at <= ?', $cutoff);

        /** @var array<int, int|string> $customerIds */
        $customerIds = $connection->fetchCol($select);

        return array_map(static fn (int|string $id): int => (int) $id, $customerIds);
    }
}
