<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as CampaignConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction as CampaignScheduledActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * The single entry point every trigger (order placed, customer registered, tag added, ...)
 * calls into: "here's what just happened (trigger event + context), run whatever campaigns
 * are configured for it." Campaigns, conditions and actions are all just database rows —
 * adding a new marketing rule is an admin/API action, not a code deploy.
 *
 * A campaign can fire on more than one trigger event (CampaignTrigger is its own child entity,
 * same as conditions/actions) — the lookup here is a two-step "which campaign_ids have a
 * trigger row for this event" then "of those, which are enabled", not a single-column equality
 * filter on ordo_campaign itself anymore.
 *
 * All conditions on a campaign are AND'd together; unknown condition/action types (e.g. a
 * campaign referencing a type this install's di.xml doesn't register) are treated as failing
 * closed — skip the condition (never satisfied) / skip the action (log and continue) rather
 * than crashing the whole dispatch for every other campaign.
 *
 * Actions run in sort_order, synchronously, until one has a delay_minutes > 0 — dispatch()
 * itself runs inline (inside an observer/cron, nothing here can literally sleep), so a delayed
 * action pauses the WHOLE remaining chain: one ordo_campaign_scheduled_action row is written
 * (resume_action_id = the delayed action, context = the dispatch context as mutated by every
 * action before it), and Cron\RunScheduledCampaignActions resumes exactly this same
 * loop — starting at that action — once run_at has passed. Chained delays (action 2 waits,
 * then action 4 waits again) work the same way each time the loop hits another delay.
 *
 * dispatch() is written to cost a constant number of queries regardless of how many campaigns
 * match a trigger: the "which campaigns are enabled for this trigger event" lookup is cached
 * (see campaignIdsForTrigger()), and conditions/actions for ALL matched campaigns are loaded in
 * one query each (addCampaignIdsFilter) rather than one query per campaign.
 */
class CampaignDispatcher
{
    /**
     * Cache tag used to invalidate the "campaign ids enabled for trigger event X" lookup —
     * flushed on ANY campaign/trigger/condition/action write (see CampaignRepository), since a
     * change to any of those can change which campaigns a given trigger event should fire.
     */
    public const CACHE_TAG = 'ordo_campaign';

    private const CACHE_KEY_PREFIX = 'ordo_campaign_trigger_';

    public function __construct(
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly CampaignConditionCollectionFactory $campaignConditionCollectionFactory,
        private readonly CampaignActionCollectionFactory $campaignActionCollectionFactory,
        private readonly CampaignScheduledActionFactory $campaignScheduledActionFactory,
        private readonly CampaignScheduledActionResource $campaignScheduledActionResource,
        private readonly ConditionPool $conditionPool,
        private readonly ActionPool $actionPool,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function dispatch(string $triggerEvent, array $context): void
    {
        $campaignIds = $this->campaignIdsForTrigger($triggerEvent);

        // TEMPORARY diagnostic logging — AdminCampaignScenarioEndToEndTest's coupon never
        // appears and the message ends at queue_message_status 4 with zero matching entries in
        // exception.log/system.log/debug.log, even from the per-campaign/shared-load catch
        // blocks below that DO normally log via $this->logger->error(). This traces how far
        // dispatch() actually gets so the next real CI run's logs answer that directly instead
        // of guessing further. Remove once the actual failure point is confirmed.
        $this->logger->info(sprintf(
            'ORDO_DEBUG dispatch start: trigger="%s" matchedCampaignIds=%s',
            $triggerEvent,
            implode(',', $campaignIds)
        ));

        if (!$campaignIds) {
            return;
        }

        // Loaded once for every matched campaign, not per-campaign (that's the N+1 this batching
        // avoids) — which also means a failure here can't be isolated to one campaign the way
        // the per-campaign try/catch below isolates a condition/action failure. That's an
        // acceptable trade: a query failing here would have failed identically for every
        // campaign in the old per-campaign version too, just logged N times instead of once.
        try {
            $conditionsByCampaign = $this->groupByCampaignId(
                $this->campaignConditionCollectionFactory->create()->addCampaignIdsFilter($campaignIds)
            );
            $actionsByCampaign = $this->groupByCampaignId(
                $this->campaignActionCollectionFactory->create()->addCampaignIdsFilter($campaignIds)
            );
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: failed loading conditions/actions for trigger "%s": %s',
                $triggerEvent,
                $e->getMessage()
            ));
            return;
        }

        foreach ($campaignIds as $campaignId) {
            try {
                $satisfied = $this->allConditionsSatisfied($conditionsByCampaign[$campaignId] ?? [], $context);
                // TEMPORARY diagnostic logging — see the one at the top of dispatch().
                $this->logger->info(sprintf(
                    'ORDO_DEBUG campaign #%d: conditionsSatisfied=%s actionCount=%d',
                    $campaignId,
                    $satisfied ? 'yes' : 'no',
                    count($actionsByCampaign[$campaignId] ?? [])
                ));

                if (!$satisfied) {
                    continue;
                }

                $this->runActionsFrom($campaignId, $actionsByCampaign[$campaignId] ?? [], 0, $context);
                // TEMPORARY diagnostic logging — see the one at the top of dispatch().
                $this->logger->info(sprintf('ORDO_DEBUG campaign #%d: runActionsFrom returned normally', $campaignId));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: campaign #%d failed for trigger "%s": %s',
                    $campaignId,
                    $triggerEvent,
                    $e->getMessage()
                ));
            }
        }
        // TEMPORARY diagnostic logging — see the one at the top of dispatch().
        $this->logger->info(sprintf('ORDO_DEBUG dispatch end: trigger="%s"', $triggerEvent));
    }

    /**
     * @return int[] enabled campaign ids with a trigger row for $triggerEvent
     */
    private function campaignIdsForTrigger(string $triggerEvent): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $triggerEvent;
        $cached = $this->cache->load($cacheKey);

        if ($cached !== false) {
            return $this->serializer->unserialize($cached);
        }

        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addTriggerEventFilter($triggerEvent);

        $candidateIds = [];
        foreach ($triggers as $trigger) {
            $candidateIds[(int) $trigger->getCampaignId()] = true;
        }

        $campaignIds = [];
        if ($candidateIds) {
            $campaigns = $this->campaignCollectionFactory->create();
            $campaigns->addIdsFilter(array_keys($candidateIds));
            $campaigns->addEnabledFilter();

            foreach ($campaigns as $campaign) {
                $campaignIds[] = (int) $campaign->getId();
            }
        }

        $this->cache->save($this->serializer->serialize($campaignIds), $cacheKey, [self::CACHE_TAG]);

        return $campaignIds;
    }

    /**
     * Groups an already-loaded collection's rows by campaign_id, preserving each campaign's
     * relative ordering (collections are already sorted by sort_order).
     *
     * @param iterable<CampaignCondition|CampaignAction> $rows
     * @return array<int, array<int, CampaignCondition|CampaignAction>>
     */
    private function groupByCampaignId(iterable $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->getCampaignId()][] = $row;
        }

        return $grouped;
    }

    /**
     * @param CampaignCondition[] $conditions
     * @param array<string, mixed> $context
     */
    private function allConditionsSatisfied(array $conditions, array $context): bool
    {
        foreach ($conditions as $conditionRow) {
            $condition = $this->conditionPool->get((string) $conditionRow->getData('type'));

            if ($condition === null) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: unknown campaign condition type "%s".',
                    $conditionRow->getData('type')
                ));
                return false;
            }

            if (!$condition->isSatisfied($context, $conditionRow->getParams())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resumes a campaign's action chain starting at (and including) the action with entity_id
     * $resumeActionId, once its delay_minutes has elapsed — Cron\RunScheduledCampaignActions's
     * only real job is finding due rows and calling this.
     *
     * @param array<string, mixed> $context
     */
    public function resumeScheduledAction(int $campaignId, int $resumeActionId, array $context): void
    {
        $actions = $this->campaignActionCollectionFactory->create();
        $actions->addCampaignFilter($campaignId);
        $actions = array_values(iterator_to_array($actions));

        $startIndex = null;
        foreach ($actions as $index => $actionRow) {
            if ((int) $actionRow->getEntityId() === $resumeActionId) {
                $startIndex = $index;
                break;
            }
        }

        // The action (or the whole campaign) could have been deleted/edited between when this
        // was scheduled and now — nothing left to resume into, not an error.
        if ($startIndex === null) {
            return;
        }

        // The action at $startIndex is the one that WAS delayed — its wait is exactly what just
        // elapsed to get here, so it must run unconditionally this time, not be re-scheduled
        // again for another delay_minutes just because that column is still > 0 on the row.
        // Only actions AFTER it go through the normal delay check.
        $this->runOneAction($actions[$startIndex], $context);
        $this->runActionsFrom($campaignId, $actions, $startIndex + 1, $context);
    }

    /**
     * @param CampaignAction[] $actions in sort_order (addCampaignFilter() already orders them)
     * @param array<string, mixed> $context
     */
    private function runActionsFrom(int $campaignId, array $actions, int $startIndex, array $context): void
    {
        $actions = array_values($actions);

        for ($i = $startIndex, $count = count($actions); $i < $count; $i++) {
            $actionRow = $actions[$i];

            if ($actionRow->getDelayMinutes() > 0) {
                $this->scheduleResume(
                    $campaignId,
                    (int) $actionRow->getEntityId(),
                    $actionRow->getDelayMinutes(),
                    $context
                );
                return;
            }

            $this->runOneAction($actionRow, $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function runOneAction(CampaignAction $actionRow, array &$context): void
    {
        $action = $this->actionPool->get((string) $actionRow->getData('type'));

        if ($action === null) {
            $this->logger->error(sprintf(
                'Ordo_Automation: unknown campaign action type "%s".',
                $actionRow->getData('type')
            ));
            return;
        }

        // TEMPORARY diagnostic logging — see the one at the top of dispatch().
        $this->logger->info(sprintf(
            'ORDO_DEBUG running action type="%s" campaignId=%d actionId=%s',
            (string) $actionRow->getData('type'),
            $actionRow->getCampaignId(),
            (string) $actionRow->getEntityId()
        ));
        $action->execute($context, $actionRow->getParams());
        $this->logger->info(
            sprintf('ORDO_DEBUG action type="%s" returned normally', (string) $actionRow->getData('type'))
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function scheduleResume(int $campaignId, int $resumeActionId, int $delayMinutes, array $context): void
    {
        $scheduled = $this->campaignScheduledActionFactory->create();
        $scheduled->setCampaignId($campaignId);
        $scheduled->setResumeActionId($resumeActionId);
        $scheduled->setContext($context);
        $scheduled->setRunAt(date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes")));

        $this->campaignScheduledActionResource->save($scheduled);
    }
}
