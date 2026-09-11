<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\Campaign\ActionRetryQueue;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignScheduledAction;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction as CampaignScheduledActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\CollectionFactory as ScheduledActionCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Resumes campaign action chains paused on a delay_minutes step (see CampaignDispatcher and
 * etc/db_schema.xml's ordo_campaign_scheduled_action comment) once their run_at has passed.
 *
 * executed_at is claimed (set) BEFORE resuming, not after — an action's own execute() could
 * throw, and this must never re-run a row just because it failed once. The scheduled-action row
 * itself stays marked executed either way; a failure instead enqueues a separate persisted retry
 * (Model\Campaign\ActionRetryQueue, ordo_campaign_action_retry) that Cron\RetryFailedCampaignActions
 * re-attempts with backoff, closing the "a row that failed stays failed" gap this class used to
 * have.
 *
 * The claim itself is a single atomic conditional UPDATE (ResourceModel\Campaign\ScheduledAction
 * ::claim(), "... WHERE executed_at IS NULL"), not a load()-then-save() — two overlapping cron
 * runs racing on the same due row must never both win the claim and both dispatch the action.
 *
 * Due rows are processed in fixed-size batches (BATCH_SIZE), each batch re-queried after the
 * previous one is claimed, instead of loading every due row into memory up front — a single
 * cron tick's memory/runtime is bounded no matter how many rows are backlogged. If more than
 * MAX_BATCHES worth are due, the rest are left for the next tick (every 5 minutes, see
 * etc/crontab.xml) rather than turning one run into an unbounded loop.
 */
class RunScheduledCampaignActions
{
    private const int BATCH_SIZE = 500;

    private const int MAX_BATCHES = 20;

    public function __construct(
        private readonly ScheduledActionCollectionFactory $campaignScheduledActionCollectionFactory,
        private readonly CampaignScheduledActionResource $campaignScheduledActionResource,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly LoggerInterface $logger,
        private readonly CronRunLogger $cronRunLogger,
        private readonly ActionRetryQueue $actionRetryQueue
    ) {
    }

    public function execute(): void
    {
        $now = date('Y-m-d H:i:s');

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $due = $this->campaignScheduledActionCollectionFactory->create();
            $due->addDueFilter($now);
            $due->setPageSize(self::BATCH_SIZE);
            $due->setCurPage(1);

            $rowCount = 0;
            foreach ($due as $scheduled) {
                /** @var CampaignScheduledAction $scheduled */
                $rowCount++;
                $this->resumeOne($scheduled);
            }

            // Claiming (setExecutedAt) moves rows out of the "due" filter, so re-querying page 1
            // each time naturally advances — a batch smaller than BATCH_SIZE means nothing due
            // is left.
            if ($rowCount < self::BATCH_SIZE) {
                return;
            }
        }

        $this->logger->warning(sprintf(
            'Ordo_Automation: hit the %d-batch cap (%d rows) resuming scheduled campaign actions; '
            . 'remaining due rows will be picked up on the next cron run.',
            self::MAX_BATCHES,
            self::MAX_BATCHES * self::BATCH_SIZE
        ));
    }

    private function resumeOne(CampaignScheduledAction $scheduled): void
    {
        if (!$this->campaignScheduledActionResource->claim($scheduled, date('Y-m-d H:i:s'))) {
            // Lost the race to another overlapping cron run - it already claimed this row and
            // will (or already did) resume it. Not ours to process.
            return;
        }

        try {
            $this->campaignDispatcher->resumeScheduledAction(
                $scheduled->getCampaignId(),
                $scheduled->getResumeActionId(),
                $scheduled->getContext()
            );
        } catch (\Throwable $e) {
            $this->cronRunLogger->logFailure(
                sprintf(
                    'resume scheduled campaign action #%d (campaign #%d)',
                    (int) $scheduled->getEntityId(),
                    $scheduled->getCampaignId()
                ),
                $e
            );
            $this->actionRetryQueue->enqueue(
                $scheduled->getCampaignId(),
                $scheduled->getResumeActionId(),
                $scheduled->getContext(),
                $e
            );
        }
    }
}
