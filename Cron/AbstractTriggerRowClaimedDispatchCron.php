<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;

/**
 * Shared by the single-campaign (dispatchScheduledTrigger(), not the fan-out-to-every-campaign
 * dispatch()) trigger-scan crons: DispatchCustomerAnniversaryCampaigns,
 * DispatchCustomerInactiveCampaigns, DispatchSegmentBlastCampaigns. The sibling shape for
 * dispatch()-fan-out triggers already has its own base, AbstractCappedTriggerDispatchCron - this
 * is the equivalent for triggers scoped to one specific campaign_id.
 *
 * Each subclass only answers: is this trigger type enabled, which customers currently qualify
 * for a given trigger row (or null to skip that trigger entirely - its own params didn't
 * validate), and what does the claim row for one (trigger, customer) pair look like - the claim-
 * before-dispatch-with-rollback-on-failure shell itself is identical everywhere.
 */
abstract class AbstractTriggerRowClaimedDispatchCron
{
    public function __construct(
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly ReminderLogStore $reminderLogStore,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    abstract protected function isEnabled(): bool;

    abstract protected function triggerEventCode(): string;

    abstract protected function claimTable(): string;

    /**
     * @return int[]|null null (or an empty array) skips this trigger entirely.
     */
    abstract protected function candidateCustomerIds(CampaignTrigger $trigger): ?array;

    /**
     * @return array{match: array<string, int|string>, claim: array<string, mixed>}
     */
    abstract protected function buildClaimRow(CampaignTrigger $trigger, int $customerId): array;

    public function execute(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addTriggerEventsFilter([$this->triggerEventCode()]);
        $triggers->addEnabledCampaignFilter();

        $dispatched = 0;
        foreach ($triggers as $trigger) {
            /** @var CampaignTrigger $trigger */
            try {
                $dispatched += $this->dispatchForTrigger($trigger);
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf('scan %s trigger #%d', $this->triggerEventCode(), (int) $trigger->getEntityId()),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(
            sprintf('dispatched %d %s trigger(s)', $dispatched, $this->triggerEventCode())
        );
    }

    private function dispatchForTrigger(CampaignTrigger $trigger): int
    {
        $candidateIds = $this->candidateCustomerIds($trigger);
        if ($candidateIds === null || $candidateIds === []) {
            return 0;
        }

        $campaignId = $trigger->getCampaignId();
        $claimTable = $this->claimTable();
        $triggerEvent = $this->triggerEventCode();

        $dispatched = 0;
        foreach ($candidateIds as $customerId) {
            $rows = $this->buildClaimRow($trigger, $customerId);

            // Claim BEFORE dispatching, not after - a crash between a successful dispatch and
            // the claim write must never cause a duplicate dispatch on the next tick. claim()
            // re-checks this trigger's own claim condition atomically under its own lock.
            if (!$this->reminderLogStore->claim($claimTable, $rows['match'], $rows['claim'])) {
                continue;
            }

            try {
                $this->campaignDispatcher->dispatchScheduledTrigger($campaignId, [
                    'customer_id' => $customerId,
                    'trigger_event' => $triggerEvent,
                ]);
                $dispatched++;
            } catch (\Throwable $e) {
                $this->reminderLogStore->deleteMatching($claimTable, $rows['claim']);
                $this->cronRunLogger->logFailure(
                    sprintf(
                        'dispatch %s trigger for campaign #%d, customer #%d',
                        $triggerEvent,
                        $campaignId,
                        $customerId
                    ),
                    $e
                );
            }
        }

        return $dispatched;
    }
}
