<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\CollectionFactory as ScheduledActionCollectionFactory;

/**
 * Campaign entry dedup - closes the ROADMAP.md campaign engine "No campaign entry dedup" gap.
 * `CampaignDispatcher::dispatch()`/`dispatchScheduledTrigger()` check this before entering a
 * campaign from action index 0; a customer who's already mid-flow (waiting on a delay_minutes
 * resume) for that same campaign is skipped for this dispatch instead of accumulating a second,
 * independent action chain in parallel. Deliberately distinct from `FrequencyCapManager`: that
 * caps message *volume*, this caps campaign *re-entry* - a customer could be under the volume cap
 * and still shouldn't restart the same flow from scratch while already partway through it.
 *
 * `resumeScheduledAction()` itself is NOT guarded by this class - it's the continuation of an
 * already-entered chain, not a new entry, so guarding it would break the customer's own in-flight
 * flow. See CampaignDispatcher for where this is (and isn't) wired in.
 */
class CampaignEntryGuard
{
    public function __construct(
        private readonly ScheduledActionCollectionFactory $scheduledActionCollectionFactory
    ) {
    }

    /**
     * True when this customer already has an unclaimed (executed_at IS NULL) scheduled-action
     * row for this campaign - i.e. they're currently waiting on a delay_minutes resume somewhere
     * in its action chain.
     */
    public function hasPendingEntry(int $campaignId, int $customerId): bool
    {
        $collection = $this->scheduledActionCollectionFactory->create();
        $collection->addPendingForCampaignAndCustomerFilter($campaignId, $customerId);

        return $collection->getSize() > 0;
    }
}
