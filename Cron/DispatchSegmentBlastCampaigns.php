<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;

/**
 * Finds every enabled campaign's own `segment_blast` trigger and, for each one, dispatches it
 * (via CampaignDispatcher::dispatchScheduledTrigger() - a specific campaign_id, not the generic
 * dispatch() that fans out to every campaign listening for an event name) for every customer
 * SegmentMemberResolver currently resolves for that trigger's own `segment_id` param.
 *
 * Closes the ROADMAP.md gap no other trigger fills: every existing trigger either reacts to a
 * real event, or - like TRIGGER_SCHEDULED_AT/TRIGGER_RECURRING_SCHEDULE - fires campaign-wide
 * with no customer_id in context at all. This is the "send this campaign to a segment, now or at
 * a scheduled time" primitive, built the same cron-scan shape every other trigger here uses
 * (CLAUDE.md's "everything is a cron-driven scan against claim tables" convention).
 *
 * ordo_segment_blast_trigger_claim is a one-dispatch-per-(customer_id, campaign_id)-pair-forever
 * claim (same shape as ordo_review_request_log, not customer_inactive's re-firable cooldown
 * window - a blast has no notion of leaving and re-entering to re-fire from). The segment itself
 * is re-resolved on every scan, not snapshotted once - see CampaignTriggerInterface::
 * TRIGGER_SEGMENT_BLAST's own docblock for what that means for a customer who joins the segment
 * after the blast started (they still get it, on a later scan, exactly once).
 */
class DispatchSegmentBlastCampaigns
{
    private const string CLAIM_TABLE = 'ordo_segment_blast_trigger_claim';

    public function __construct(
        private readonly Config $config,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly SegmentMemberResolver $segmentMemberResolver,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly ReminderLogStore $reminderLogStore,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isSegmentBlastTriggerEnabled()) {
            return;
        }

        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addTriggerEventsFilter([CampaignTriggerInterface::TRIGGER_SEGMENT_BLAST]);
        $triggers->addEnabledCampaignFilter();

        $dispatched = 0;
        foreach ($triggers as $trigger) {
            /** @var CampaignTrigger $trigger */
            try {
                $dispatched += $this->dispatchForTrigger($trigger);
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf('scan segment_blast trigger #%d', (int) $trigger->getEntityId()),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('dispatched %d segment_blast trigger(s)', $dispatched));
    }

    private function dispatchForTrigger(CampaignTrigger $trigger): int
    {
        $params = $trigger->getParams();
        $segmentId = $params['segment_id'] ?? null;
        if ($segmentId === null || !is_numeric($segmentId)) {
            return 0;
        }

        $rawScheduledAt = $params['scheduled_at'] ?? '';
        $scheduledAt = trim(is_string($rawScheduledAt) ? $rawScheduledAt : '');
        if ($scheduledAt !== '' && strtotime($scheduledAt) > time()) {
            // Not due yet - the admin scheduled a future start, same gate TRIGGER_SCHEDULED_AT's
            // own ScheduledTriggerScanner applies.
            return 0;
        }

        $campaignId = $trigger->getCampaignId();

        $dispatched = 0;
        foreach ($this->segmentMemberResolver->getMatchingCustomerIds((int) $segmentId) as $customerId) {
            $claimRow = ['customer_id' => $customerId, 'campaign_id' => $campaignId];

            // Claim BEFORE dispatching, not after - a crash between a successful dispatch and
            // the claim write must never cause a duplicate dispatch on the next scan. No date
            // window here, unlike customer_inactive's cooldown claim - once claimed, this
            // (customer, campaign) pair never fires again.
            if (!$this->reminderLogStore->claim(self::CLAIM_TABLE, $claimRow, $claimRow)) {
                continue;
            }

            try {
                $this->campaignDispatcher->dispatchScheduledTrigger($campaignId, [
                    'customer_id' => $customerId,
                    'trigger_event' => CampaignTriggerInterface::TRIGGER_SEGMENT_BLAST,
                ]);
                $dispatched++;
            } catch (\Throwable $e) {
                $this->reminderLogStore->deleteMatching(self::CLAIM_TABLE, $claimRow);
                $this->cronRunLogger->logFailure(
                    sprintf(
                        'dispatch segment_blast trigger for campaign #%d, customer #%d',
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
