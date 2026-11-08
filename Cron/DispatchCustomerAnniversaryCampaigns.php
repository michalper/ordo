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
class DispatchCustomerAnniversaryCampaigns
{
    private const string CLAIM_TABLE = 'ordo_customer_anniversary_trigger_claim';
    private const string DATE_SOURCE_DOB = 'dob';
    private const string DATE_SOURCE_REGISTRATION = 'registration';

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly ReminderLogStore $reminderLogStore,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isCustomerAnniversaryTriggerEnabled()) {
            return;
        }

        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addTriggerEventsFilter([CampaignTriggerInterface::TRIGGER_CUSTOMER_ANNIVERSARY]);
        $triggers->addEnabledCampaignFilter();

        $dispatched = 0;
        foreach ($triggers as $trigger) {
            /** @var CampaignTrigger $trigger */
            try {
                $dispatched += $this->dispatchForTrigger($trigger);
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf('scan customer_anniversary trigger #%d', (int) $trigger->getEntityId()),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('dispatched %d customer_anniversary trigger(s)', $dispatched));
    }

    private function dispatchForTrigger(CampaignTrigger $trigger): int
    {
        $rawDateSource = $trigger->getParams()['date_source'] ?? '';
        $dateSource = is_string($rawDateSource) ? $rawDateSource : '';
        if ($dateSource !== self::DATE_SOURCE_DOB && $dateSource !== self::DATE_SOURCE_REGISTRATION) {
            return 0;
        }

        $campaignId = $trigger->getCampaignId();
        $firedYear = (int) date('Y');

        $dispatched = 0;
        foreach ($this->findCustomersWithAnniversaryToday($dateSource) as $customerId) {
            $matchConditions = [
                'customer_id = ?' => $customerId,
                'campaign_id = ?' => $campaignId,
                'fired_year = ?' => $firedYear,
            ];

            // Claim BEFORE dispatching, not after - a crash between a successful dispatch and
            // the claim write must never cause a duplicate dispatch on the next tick. claim()
            // re-checks "already fired this year" atomically under its own lock, same reasoning
            // Cron\DispatchCustomerInactiveCampaigns' own cooldown claim has.
            $claimRow = ['customer_id' => $customerId, 'campaign_id' => $campaignId, 'fired_year' => $firedYear];
            if (!$this->reminderLogStore->claim(self::CLAIM_TABLE, $matchConditions, $claimRow)) {
                continue;
            }

            try {
                $this->campaignDispatcher->dispatchScheduledTrigger($campaignId, [
                    'customer_id' => $customerId,
                    'trigger_event' => CampaignTriggerInterface::TRIGGER_CUSTOMER_ANNIVERSARY,
                ]);
                $dispatched++;
            } catch (\Throwable $e) {
                $this->reminderLogStore->deleteMatching(self::CLAIM_TABLE, $claimRow);
                $this->cronRunLogger->logFailure(
                    sprintf(
                        'dispatch customer_anniversary trigger for campaign #%d, customer #%d',
                        $campaignId,
                        $customerId
                    ),
                    $e
                );
            }
        }

        return $dispatched;
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
}
