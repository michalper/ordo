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
 * Finds every enabled campaign's own `customer_anniversary` trigger and, for each one, dispatches
 * it (via CampaignDispatcher::dispatchScheduledTrigger()) for every registered customer whose
 * `customer_dob` or account `created_at` (chosen by the trigger's own `date_source` param) has
 * the same month+day as today.
 *
 * Deliberately not shaped like Cron\DispatchCustomerInactiveCampaigns' cooldown-days claim - a
 * birthday/anniversary is a fixed calendar date, not a rolling threshold, so it can only
 * genuinely recur once a year. ordo_customer_anniversary_trigger_claim is keyed by
 * (customer_id, campaign_id, fired_year) instead, via ReminderLogStore::claim()'s same
 * atomic check-then-insert lock - no cooldown config to tune, "already fired this calendar year"
 * is the whole rule.
 */
class DispatchCustomerAnniversaryCampaigns extends AbstractTriggerRowClaimedDispatchCron
{
    private const string CLAIM_TABLE = 'ordo_customer_anniversary_trigger_claim';
    private const string DATE_SOURCE_DOB = 'dob';
    private const string DATE_SOURCE_REGISTRATION = 'registration';
    private const string DATE_SOURCE_FIRST_ORDER = 'first_order';

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
        return $this->config->isCustomerAnniversaryTriggerEnabled();
    }

    protected function triggerEventCode(): string
    {
        return CampaignTriggerInterface::TRIGGER_CUSTOMER_ANNIVERSARY;
    }

    protected function claimTable(): string
    {
        return self::CLAIM_TABLE;
    }

    protected function candidateCustomerIds(CampaignTrigger $trigger): ?array
    {
        $rawDateSource = $trigger->getParams()['date_source'] ?? '';
        $dateSource = is_string($rawDateSource) ? $rawDateSource : '';

        if ($dateSource === self::DATE_SOURCE_FIRST_ORDER) {
            return $this->findCustomersWithFirstOrderAnniversaryToday();
        }

        if ($dateSource !== self::DATE_SOURCE_DOB && $dateSource !== self::DATE_SOURCE_REGISTRATION) {
            return null;
        }

        return $this->findCustomersWithAnniversaryToday($dateSource);
    }

    protected function buildClaimRow(CampaignTrigger $trigger, int $customerId): array
    {
        $campaignId = $trigger->getCampaignId();
        $firedYear = (int) date('Y');

        return [
            // Real pre-existing bug fixed here: a bare column name (no '?') makes
            // Zend_Db_Select::where()'s quoteInto() a no-op (str_replace('?', ..., $text) with
            // nothing to replace), so ReminderLogStore::countMatching() silently degraded this to
            // a truthy-column check ("WHERE (customer_id) AND (campaign_id) AND (fired_year)") -
            // true for ANY existing row with non-zero columns, not just a matching one. Once any
            // row at all existed in this claim table, claim() falsely reported every OTHER
            // (customer, campaign) pair as already claimed, silently blocking every dispatch but
            // the first. See ReminderLogStore::countMatching()'s own docblock/test for the
            // required 'column = ?' key format - DispatchCustomerInactiveCampaigns already uses
            // it correctly; this class and DispatchSegmentBlastCampaigns did not.
            'match' => [
                'customer_id = ?' => $customerId,
                'campaign_id = ?' => $campaignId,
                'fired_year = ?' => $firedYear,
            ],
            'claim' => [
                'customer_id' => $customerId,
                'campaign_id' => $campaignId,
                'fired_year' => $firedYear,
            ],
        ];
    }

    /**
     * @return int[]
     */
    private function findCustomersWithAnniversaryToday(string $dateSource): array
    {
        // Chosen from a fixed internal enum just above, never from the trigger's own stored
        // string directly - this becomes a raw SQL column name below.
        $dateColumn = $dateSource === self::DATE_SOURCE_DOB ? 'dob' : 'created_at';

        $connection = $this->resourceConnection->getConnection();
        $customerTable = $this->resourceConnection->getTableName('customer_entity');

        $select = $connection->select()
            ->from($customerTable, ['entity_id'])
            ->where("{$dateColumn} IS NOT NULL")
            ->where("MONTH({$dateColumn}) = ?", (int) date('n'))
            ->where("DAY({$dateColumn}) = ?", (int) date('j'));

        /** @var array<int, int|string> $customerIds */
        $customerIds = $connection->fetchCol($select);

        return array_map(static fn (int|string $id): int => (int) $id, $customerIds);
    }

    /**
     * A customer's "first order" has no single column to read a date off (unlike dob/
     * registration, both plain customer_entity columns) - the same MIN(created_at)-per-customer
     * subselect shape CohortRetention\Grid\Collection::buildCohortSelect() already establishes
     * for "this customer's own first order", wrapped in an outer month/day filter instead of that
     * class's own cohort-bucketing. A guest order (customer_id IS NULL) never contributes a first-
     * order date here, same reasoning CohortRetention's own buildCohortSelect() excludes guests.
     *
     * @return int[]
     */
    private function findCustomersWithFirstOrderAnniversaryToday(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $firstOrders = $connection->select()
            ->from($orderTable, [
                'customer_id',
                'first_order_at' => new \Zend_Db_Expr('MIN(created_at)'),
            ])
            ->where('customer_id IS NOT NULL')
            ->group('customer_id');

        $select = $connection->select()
            ->from(['fo' => new \Zend_Db_Expr('(' . $firstOrders->assemble() . ')')], ['customer_id'])
            ->where('MONTH(fo.first_order_at) = ?', (int) date('n'))
            ->where('DAY(fo.first_order_at) = ?', (int) date('j'));

        /** @var array<int, int|string> $customerIds */
        $customerIds = $connection->fetchCol($select);

        return array_map(static fn (int|string $id): int => (int) $id, $customerIds);
    }
}
