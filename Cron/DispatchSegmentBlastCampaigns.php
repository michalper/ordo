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
class DispatchSegmentBlastCampaigns extends AbstractTriggerRowClaimedDispatchCron
{
    private const string CLAIM_TABLE = 'ordo_segment_blast_trigger_claim';

    public function __construct(
        private readonly Config $config,
        CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly SegmentMemberResolver $segmentMemberResolver,
        CampaignDispatcher $campaignDispatcher,
        ReminderLogStore $reminderLogStore,
        CronRunLogger $cronRunLogger
    ) {
        parent::__construct($campaignTriggerCollectionFactory, $campaignDispatcher, $reminderLogStore, $cronRunLogger);
    }

    protected function isEnabled(): bool
    {
        return $this->config->isSegmentBlastTriggerEnabled();
    }

    protected function triggerEventCode(): string
    {
        return CampaignTriggerInterface::TRIGGER_SEGMENT_BLAST;
    }

    protected function claimTable(): string
    {
        return self::CLAIM_TABLE;
    }

    protected function candidateCustomerIds(CampaignTrigger $trigger): ?array
    {
        $params = $trigger->getParams();
        $segmentId = $params['segment_id'] ?? null;
        if ($segmentId === null || !is_numeric($segmentId)) {
            return null;
        }

        $rawScheduledAt = $params['scheduled_at'] ?? '';
        $scheduledAt = trim(is_string($rawScheduledAt) ? $rawScheduledAt : '');
        if ($scheduledAt !== '' && strtotime($scheduledAt) > time()) {
            // Not due yet - the admin scheduled a future start, same gate TRIGGER_SCHEDULED_AT's
            // own ScheduledTriggerScanner applies.
            return null;
        }

        return $this->segmentMemberResolver->getMatchingCustomerIds((int) $segmentId);
    }

    protected function buildClaimRow(CampaignTrigger $trigger, int $customerId): array
    {
        $campaignId = $trigger->getCampaignId();

        // No date window here, unlike customer_inactive's cooldown claim - once claimed, this
        // (customer, campaign) pair never fires again.
        //
        // Real pre-existing bug fixed here: 'match' must use 'column = ?' keys, same as
        // DispatchCustomerInactiveCampaigns already does - a bare column name makes
        // Zend_Db_Select::where()'s quoteInto() a no-op, degrading ReminderLogStore::
        // countMatching() to a truthy-column check that falsely reports every OTHER (customer,
        // campaign) pair as already claimed once any row at all exists in this table. See
        // DispatchCustomerAnniversaryCampaigns::buildClaimRow()'s own docblock for the full
        // mechanism - this class had the identical bug.
        return [
            'match' => ['customer_id = ?' => $customerId, 'campaign_id = ?' => $campaignId],
            'claim' => ['customer_id' => $customerId, 'campaign_id' => $campaignId],
        ];
    }
}
