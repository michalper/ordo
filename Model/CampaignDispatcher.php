<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\CampaignEntryGuard;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\Campaign\SplitVariantSelector;
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

    private const string CACHE_KEY_PREFIX = 'ordo_campaign_trigger_';

    public function __construct(
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly CampaignConditionCollectionFactory $campaignConditionCollectionFactory,
        private readonly CampaignActionCollectionFactory $campaignActionCollectionFactory,
        private readonly CampaignActionFactory $campaignActionFactory,
        private readonly CampaignScheduledActionFactory $campaignScheduledActionFactory,
        private readonly CampaignScheduledActionResource $campaignScheduledActionResource,
        private readonly ConditionPool $conditionPool,
        private readonly ActionPool $actionPool,
        private readonly SplitVariantSelector $splitVariantSelector,
        private readonly CampaignEntryGuard $campaignEntryGuard,
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
        $conditionLogicByCampaign = $this->campaignIdsForTrigger($triggerEvent);

        if (!$conditionLogicByCampaign) {
            return;
        }

        $campaignIds = array_keys($conditionLogicByCampaign);

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

        $customerId = $this->customerIdFromContext($context);

        foreach ($campaignIds as $campaignId) {
            try {
                if ($customerId !== null && $this->campaignEntryGuard->hasPendingEntry($campaignId, $customerId)) {
                    // Already mid-flow (waiting on a delay_minutes resume) in this exact
                    // campaign - skip re-entering it from scratch on this repeat trigger rather
                    // than accumulating a second, independent action chain in parallel.
                    continue;
                }

                $logic = $conditionLogicByCampaign[$campaignId] ?? 'all';
                if (!$this->conditionsSatisfied($logic, $conditionsByCampaign[$campaignId] ?? [], $context)) {
                    continue;
                }

                $this->runActionsFrom($campaignId, $actionsByCampaign[$campaignId] ?? [], 0, $context);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: campaign #%d failed for trigger "%s": %s',
                    $campaignId,
                    $triggerEvent,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Fires exactly one campaign's condition/action chain, bypassing the "which campaigns are
     * enabled for trigger event X" lookup dispatch() does above. scheduled_at/recurring_schedule
     * triggers are due per-campaign (each carries its own date or cron expression in its own
     * params, not a shared global event every registered campaign reacts to together), so
     * Cron\Campaign\DispatchScheduledTriggers already knows exactly which single campaign is
     * due right now and calls this directly instead of going through dispatch().
     *
     * @param array<string, mixed> $context
     */
    public function dispatchScheduledTrigger(int $campaignId, array $context): void
    {
        $campaigns = $this->campaignCollectionFactory->create();
        $campaigns->addIdsFilter([$campaignId]);
        $campaigns->addEnabledFilter();
        $campaign = $campaigns->getFirstItem();

        if (!$campaign->getId()) {
            return;
        }

        $customerId = $this->customerIdFromContext($context);
        if ($customerId !== null && $this->campaignEntryGuard->hasPendingEntry($campaignId, $customerId)) {
            // Already mid-flow in this exact campaign - see the identical guard in dispatch().
            return;
        }

        $logic = (string) $campaign->getData('condition_logic') === 'any' ? 'any' : 'all';

        try {
            $conditionCollection = $this->campaignConditionCollectionFactory->create();
            $conditionCollection->addCampaignFilter($campaignId);
            $conditions = array_values(iterator_to_array($conditionCollection));

            if (!$this->conditionsSatisfied($logic, $conditions, $context)) {
                return;
            }

            $actionCollection = $this->campaignActionCollectionFactory->create();
            $actionCollection->addCampaignFilter($campaignId);
            $actions = array_values(iterator_to_array($actionCollection));

            $this->runActionsFrom($campaignId, $actions, 0, $context);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: scheduled campaign #%d failed: %s',
                $campaignId,
                $e->getMessage()
            ));
        }
    }

    /**
     * @return array<int, string> enabled campaign ids with a trigger row for $triggerEvent,
     *  mapped to that campaign's condition_logic ('all'/'any') — carrying it through the cache
     *  here avoids a second per-campaign query just to read it back in dispatch().
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

        $conditionLogicByCampaign = [];
        if ($candidateIds) {
            $campaigns = $this->campaignCollectionFactory->create();
            $campaigns->addIdsFilter(array_keys($candidateIds));
            $campaigns->addEnabledFilter();

            foreach ($campaigns as $campaign) {
                $conditionLogic = (string) $campaign->getData('condition_logic');
                $conditionLogicByCampaign[(int) $campaign->getId()] = $conditionLogic === 'any' ? 'any' : 'all';
            }
        }

        $this->cache->save($this->serializer->serialize($conditionLogicByCampaign), $cacheKey, [self::CACHE_TAG]);

        return $conditionLogicByCampaign;
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
     * @param 'all'|'any' $logic
     * @param CampaignCondition[] $conditions
     * @param array<string, mixed> $context
     */
    private function conditionsSatisfied(string $logic, array $conditions, array $context): bool
    {
        // A campaign with zero conditions has always meant "fire unconditionally" regardless of
        // AND/OR — deliberately asymmetric from SegmentMatcher, which fails closed on zero
        // conditions either way (see SegmentMatcher's own docblock). Handled before the loop so
        // it doesn't depend on which logic happens to be selected.
        if ($conditions === []) {
            return true;
        }

        $specs = [];
        foreach ($conditions as $conditionRow) {
            $specs[] = ['type' => (string) $conditionRow->getData('type'), 'params' => $conditionRow->getParams()];
        }

        return $this->evaluateList($specs, $logic, $context);
    }

    /**
     * @param array<int, array{type: string, params: array<string, mixed>}> $specs
     * @param array<string, mixed> $context
     */
    private function evaluateList(array $specs, string $logic, array $context): bool
    {
        $matchAny = $logic === 'any';

        foreach ($specs as $spec) {
            $satisfied = $this->evaluateOne($spec, $context);

            if ($matchAny && $satisfied) {
                return true;
            }

            if (!$matchAny && !$satisfied) {
                return false;
            }
        }

        // Loop finished without an early return: under AND every entry passed, under OR none of
        // them did.
        return !$matchAny;
    }

    /**
     * A row whose type is the reserved 'group' pseudo-type holds its own nested
     * {"logic": ..., "conditions": [...]} in params instead of a real ConditionPool condition —
     * see Model\Segment\SegmentMatcher::evaluateGroup()'s docblock for why this needs no schema
     * change and isn't reachable via the Flow canvas yet.
     *
     * @param array{type: string, params: array<string, mixed>} $spec
     * @param array<string, mixed> $context
     */
    private function evaluateOne(array $spec, array $context): bool
    {
        if ($spec['type'] === 'group') {
            return $this->evaluateGroup($spec['params'], $context);
        }

        $condition = $this->conditionPool->get($spec['type']);

        if (!$condition instanceof \Ordo\Automation\Api\Campaign\ConditionInterface) {
            $this->logger->error(sprintf(
                'Ordo_Automation: unknown campaign condition type "%s".',
                $spec['type']
            ));
            return false;
        }

        return $condition->isSatisfied($context, $spec['params']);
    }

    /**
     * @param array<string, mixed> $groupParams
     * @param array<string, mixed> $context
     */
    private function evaluateGroup(array $groupParams, array $context): bool
    {
        $nestedLogic = ($groupParams['logic'] ?? 'all') === 'any' ? 'any' : 'all';
        $nested = $groupParams['conditions'] ?? null;

        if (!is_array($nested) || $nested === []) {
            // Unlike a campaign's top-level zero-conditions case, an empty nested group is NOT
            // "fire unconditionally" — it's a group an admin built and left empty, so it fails
            // closed the same way SegmentMatcher::evaluateGroup() does.
            return false;
        }

        $specs = [];
        foreach ($nested as $item) {
            if (!is_array($item) || !isset($item['type']) || !is_string($item['type'])) {
                continue;
            }
            $specs[] = ['type' => $item['type'], 'params' => $this->asStringKeyedArray($item['params'] ?? [])];
        }

        if ($specs === []) {
            return false;
        }

        return $this->evaluateList($specs, $nestedLogic, $context);
    }

    /**
     * Same normalization as Model\Segment\SegmentMatcher::asStringKeyedArray() - a decoded-JSON
     * 'group' params blob's nested "conditions" entries aren't guaranteed to be string-keyed
     * maps the way a real CampaignCondition row's getParams() already is.
     *
     * @return array<string, mixed>
     */
    private function asStringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
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
        $startIndex = array_find_key($actions, fn ($actionRow) => (int) $actionRow->getEntityId() === $resumeActionId);

        // The action (or the whole campaign) could have been deleted/edited between when this
        // was scheduled and now — nothing left to resume into, not an error.
        if ($startIndex === null) {
            return;
        }

        // The action at $startIndex is the one that WAS delayed — its wait is exactly what just
        // elapsed to get here, so it must run unconditionally this time, not be re-scheduled
        // again for another delay_minutes just because that column is still > 0 on the row.
        // Only actions AFTER it go through the normal delay check.
        $context['campaign_id'] = $campaignId;
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
        // Stamped here (not once in dispatch()) so it also survives resumeScheduledAction()'s own
        // call into this method after a delay, and so every Send* action can attribute its
        // ordo_message_log row back to the campaign without CampaignDispatcher needing to know
        // which action types actually send anything.
        $context['campaign_id'] = $campaignId;

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

            // Same "reserved pseudo-type handled before the pool lookup" shape as 'group' is for
            // conditions (see evaluateOne() above) - 'split' is deliberately NOT an ActionPool
            // entry, since it has no send/tag/whatever effect of its own, only a branching one.
            if ((string) $actionRow->getData('type') === 'split') {
                $this->runSplit($campaignId, $actionRow, $context);
                continue;
            }

            $this->runOneAction($actionRow, $context);
        }
    }

    /**
     * Resolves which variant this dispatch belongs to (SplitVariantSelector - deterministic per
     * customer/visitor identity, and reused rather than re-rolled if this exact split node was
     * already resolved earlier in the same dispatch/resume chain), stamps the choice into
     * context so every Send* action inside the variant's own chain can attribute its
     * ordo_message_log row to it, then runs that variant's actions the same way any other action
     * list runs - including, transitively, any delay_minutes pause they cause.
     *
     * Variant actions are synthetic (never-persisted) CampaignAction rows built fresh from the
     * split's own `params` JSON on every call - not loaded from ordo_campaign_action, since they
     * don't have their own rows there. A known limitation (phase 1 of split testing): a variant
     * action's own delay_minutes is deliberately ignored/forced to 0 here - scheduleResume()'s
     * resume_action_id column is a real FK to ordo_campaign_action.entity_id, which a synthetic
     * row has no matching entry for, so honoring an in-variant delay would need either a schema
     * change or a distinct resume-id encoding scheme, neither of which phase 1 takes on.
     *
     * @param array<string, mixed> $context
     */
    private function runSplit(int $campaignId, CampaignAction $splitActionRow, array &$context): void
    {
        $variants = $this->normalizeVariants($splitActionRow->getParams()['variants'] ?? null);
        if ($variants === []) {
            $this->logger->error(sprintf(
                'Ordo_Automation: split action #%d on campaign #%d has no usable variants.',
                (int) $splitActionRow->getEntityId(),
                $campaignId
            ));
            return;
        }

        $variant = $this->splitVariantSelector->selectVariant(
            $campaignId,
            (int) $splitActionRow->getEntityId(),
            $variants,
            $context
        );
        $context['ordo_split_variant'] = $variant['key'];

        $variantActions = [];
        foreach ($variant['actions'] as $sortOrder => $spec) {
            if (!is_array($spec) || !isset($spec['type']) || !is_string($spec['type'])) {
                continue;
            }

            /** @var CampaignAction $actionRow */
            $actionRow = $this->campaignActionFactory->create();
            $actionRow->setCampaignId($campaignId)
                ->setType($spec['type'])
                ->setParamsJson((string) json_encode($this->asStringKeyedArray($spec['params'] ?? [])))
                ->setSortOrder((int) $sortOrder)
                ->setDelayMinutes(0);
            $variantActions[] = $actionRow;
        }

        $this->runActionsFrom($campaignId, $variantActions, 0, $context);
    }

    /**
     * Validates/normalizes a split action's raw `params['variants']` into a clean list of
     * {key, weight, actions} - malformed entries (missing key, non-numeric weight, missing
     * actions list) are dropped rather than crashing the whole dispatch, same fail-closed
     * philosophy as evaluateGroup()'s own malformed-item handling above.
     *
     * @return array<int, array{key: string, weight: float, actions: array<int|string, mixed>}>
     */
    private function normalizeVariants(mixed $rawVariants): array
    {
        if (!is_array($rawVariants)) {
            return [];
        }

        $variants = [];
        foreach ($rawVariants as $rawVariant) {
            if (!is_array($rawVariant) || !isset($rawVariant['key']) || !is_string($rawVariant['key'])
                || $rawVariant['key'] === ''
            ) {
                continue;
            }

            $weight = $rawVariant['weight'] ?? 0;
            if (!is_int($weight) && !is_float($weight)) {
                continue;
            }

            $actions = $rawVariant['actions'] ?? [];
            $variants[] = [
                'key' => $rawVariant['key'],
                'weight' => (float) $weight,
                'actions' => is_array($actions) ? $actions : [],
            ];
        }

        return $variants;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function runOneAction(CampaignAction $actionRow, array &$context): void
    {
        $action = $this->actionPool->get((string) $actionRow->getData('type'));

        if (!$action instanceof \Ordo\Automation\Api\Campaign\ActionInterface) {
            $this->logger->error(sprintf(
                'Ordo_Automation: unknown campaign action type "%s".',
                $actionRow->getData('type')
            ));
            return;
        }

        $action->execute($context, $actionRow->getParams());
    }

    /**
     * @param array<string, mixed> $context
     */
    /**
     * context['customer_id'] is the universal convention key every trigger observer populates
     * (CustomerTagManager, VisitorEventLogger, TriggerOutcomeLogger, SegmentMatcher, ...) - reused
     * here for both CampaignEntryGuard's lookup and scheduleResume()'s denormalized column. Null
     * when the context doesn't identify a customer at all (a purely order-scoped trigger, in
     * theory), in which case the guard is skipped entirely rather than deduping against nothing.
     *
     * @param array<string, mixed> $context
     */
    private function customerIdFromContext(array $context): ?int
    {
        $customerId = $context['customer_id'] ?? null;
        return is_numeric($customerId) ? (int) $customerId : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function scheduleResume(int $campaignId, int $resumeActionId, int $delayMinutes, array $context): void
    {
        $scheduled = $this->campaignScheduledActionFactory->create();
        $scheduled->setCampaignId($campaignId);
        $scheduled->setResumeActionId($resumeActionId);
        $scheduled->setCustomerId($this->customerIdFromContext($context));
        $scheduled->setContext($context);
        $scheduled->setRunAt(date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes")));

        $this->campaignScheduledActionResource->save($scheduled);
    }
}
