<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\CampaignEntryGuard;
use Ordo\Automation\Model\Campaign\HoldoutSelector;
use Ordo\Automation\Model\Campaign\SplitVariantSelector;
use Ordo\Automation\Model\Condition\ConditionGroupEvaluator;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as CampaignConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction as CampaignScheduledActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Website\CollectionFactory as CampaignWebsiteCollectionFactory;
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
     * Flat cache tag covering every "campaign ids enabled for trigger event X" cache entry,
     * regardless of which trigger event it's for. Still stamped on every entry saved below (in
     * addition to that entry's own per-trigger-event tag, built from CACHE_KEY_PREFIX) so any
     * caller that flushes this tag alone — CampaignTriggerRepository, Controller\Adminhtml\
     * Campaign\Delete, Campaign\CampaignSaveProcessor — keeps invalidating everything it always
     * did. CampaignRepository::save()/delete() are the one place that flushes the narrower
     * per-trigger tags instead, since it already knows exactly which trigger events a
     * saved/deleted campaign touches.
     */
    public const CACHE_TAG = 'ordo_campaign';

    /**
     * Shared by both this class's own cache key for a trigger event's campaignIdsForTrigger()
     * entry AND that same entry's per-trigger-event cache tag (distinct namespaces, so no
     * collision) — public so a caller that knows only "these trigger events may have changed"
     * (e.g. CampaignRepository, from a saved campaign's own ordo_campaign_trigger rows) can build
     * the same tag itself (`CampaignDispatcher::CACHE_KEY_PREFIX . $triggerEvent`) and flush
     * exactly those entries instead of the flat CACHE_TAG covering every trigger event's lookup.
     */
    public const string CACHE_KEY_PREFIX = 'ordo_campaign_trigger_';

    public function __construct(
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly CampaignConditionCollectionFactory $campaignConditionCollectionFactory,
        private readonly CampaignActionCollectionFactory $campaignActionCollectionFactory,
        private readonly CampaignActionFactory $campaignActionFactory,
        private readonly CampaignScheduledActionFactory $campaignScheduledActionFactory,
        private readonly CampaignScheduledActionResource $campaignScheduledActionResource,
        private readonly ConditionGroupEvaluator $conditionGroupEvaluator,
        private readonly ActionPool $actionPool,
        private readonly SplitVariantSelector $splitVariantSelector,
        private readonly CampaignEntryGuard $campaignEntryGuard,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly CampaignWebsiteCollectionFactory $campaignWebsiteCollectionFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerStoreResolver $customerStoreResolver,
        private readonly HoldoutSelector $holdoutSelector,
        private readonly CampaignOutcomeLogger $campaignOutcomeLogger
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function dispatch(string $triggerEvent, array $context): void
    {
        $campaignMetaById = $this->campaignIdsForTrigger($triggerEvent);

        if ($campaignMetaById === []) {
            return;
        }

        $campaignMetaById = $this->filterByWebsite($campaignMetaById, $context);

        if ($campaignMetaById === []) {
            return;
        }

        $campaignIds = array_keys($campaignMetaById);

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
                $meta = $campaignMetaById[$campaignId] ?? ['logic' => 'all', 'holdout_percent' => 0];
                $this->runCampaign(
                    $campaignId,
                    $meta['logic'],
                    $meta['holdout_percent'],
                    static fn (): array => $conditionsByCampaign[$campaignId] ?? [],
                    static fn (): array => $actionsByCampaign[$campaignId] ?? [],
                    $context
                );
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

        // Unlike before this shared runCampaign() extraction, the entry-guard check now sits
        // inside the try - a guard failure is logged and swallowed here exactly as it always was
        // in dispatch(), instead of propagating up into the scheduled-trigger cron.
        try {
            $this->runCampaign(
                $campaignId,
                (string) $campaign->getData('condition_logic') === 'any' ? 'any' : 'all',
                // Not $campaign->getHoldoutPercent() - same reason condition_logic above reads
                // getData() directly rather than calling Campaign::getConditionLogic(): $campaign
                // here is a collection's getFirstItem() result, which PHPStan can't narrow past
                // \Magento\Framework\DataObject, so a typed accessor call resolves as mixed.
                max(0, min(100, (int) $campaign->getData('holdout_percent'))),
                function () use ($campaignId): array {
                    $conditionCollection = $this->campaignConditionCollectionFactory->create();
                    $conditionCollection->addCampaignFilter($campaignId);
                    return array_values(iterator_to_array($conditionCollection));
                },
                function () use ($campaignId): array {
                    $actionCollection = $this->campaignActionCollectionFactory->create();
                    $actionCollection->addCampaignFilter($campaignId);
                    return array_values(iterator_to_array($actionCollection));
                },
                $context
            );
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: scheduled campaign #%d failed: %s',
                $campaignId,
                $e->getMessage()
            ));
        }
    }

    /**
     * The per-campaign body dispatch() and dispatchScheduledTrigger() share: dedup guard,
     * condition evaluation, action run. Conditions/actions come in as lazy loaders - not arrays -
     * so a guard hit never loads either (dispatchScheduledTrigger() queries them per campaign),
     * and so a loader failure lands in the caller's own per-campaign error boundary, keeping its
     * original log wording. dispatch() just hands over its already-batch-loaded slices.
     *
     * Holdout check (docs/adr/0055) sits right here — after conditionsSatisfied() returns true,
     * before runActionsFrom() ever runs — so a held-out customer is logged once, for this whole
     * campaign run, instead of per-action the way QuietHoursGate/FrequencyCapGate gate each
     * Send* action individually. $holdoutPercent <= 0 (the default - no campaign is held out
     * unless an admin deliberately raises it above zero) and a context with no customer_id (no
     * stable identity to hold out *as*) both skip the check entirely, same fail-open-to-normal-
     * behavior as "zero conditions always passes" above conditionsSatisfied().
     *
     * @param 'all'|'any' $logic
     * @param callable(): CampaignCondition[] $loadConditions
     * @param callable(): CampaignAction[] $loadActions
     * @param array<string, mixed> $context
     */
    private function runCampaign(
        int $campaignId,
        string $logic,
        int $holdoutPercent,
        callable $loadConditions,
        callable $loadActions,
        array $context
    ): void {
        $customerId = $this->customerIdFromContext($context);
        if ($customerId !== null && $this->campaignEntryGuard->hasPendingEntry($campaignId, $customerId)) {
            // Already mid-flow (waiting on a delay_minutes resume) in this exact campaign - skip
            // re-entering it from scratch rather than accumulating a second, independent action
            // chain in parallel.
            return;
        }

        if (!$this->conditionsSatisfied($logic, $loadConditions(), $context)) {
            return;
        }

        if ($customerId !== null && $holdoutPercent > 0
            && $this->holdoutSelector->isHeldOut($campaignId, $customerId, $holdoutPercent)
        ) {
            $this->campaignOutcomeLogger->logHeldOut($campaignId, $customerId, $this->triggerOrderIdFromContext($context));
            return;
        }

        $this->runActionsFrom($campaignId, $loadActions(), 0, $context);
    }

    /**
     * @return array<int, array{logic: 'all'|'any', holdout_percent: int}> enabled campaign ids
     *  with a trigger row for $triggerEvent, mapped to that campaign's condition_logic and
     *  holdout_percent — carrying both through the cache here avoids a second per-campaign query
     *  just to read them back in dispatch().
     */
    private function campaignIdsForTrigger(string $triggerEvent): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $triggerEvent;
        $cached = $this->cache->load($cacheKey);

        if ($cached !== false) {
            $decoded = $this->serializer->unserialize($cached);
            // The cache round-trip erases the value type the writer below guarantees —
            // re-normalizing on read keeps the contract honest even against a stale/foreign
            // cache entry (including one written by a pre-holdout version of this class, back
            // when the cached value was a bare 'all'/'any' string).
            $metaByCampaign = [];
            foreach (is_array($decoded) ? $decoded : [] as $campaignId => $meta) {
                $metaByCampaign[(int) $campaignId] = is_array($meta)
                    ? [
                        'logic' => ($meta['logic'] ?? null) === 'any' ? 'any' : 'all',
                        'holdout_percent' => max(0, min(100, (int) ($meta['holdout_percent'] ?? 0))),
                    ]
                    : ['logic' => $meta === 'any' ? 'any' : 'all', 'holdout_percent' => 0];
            }

            return $metaByCampaign;
        }

        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addTriggerEventFilter($triggerEvent);

        $candidateIds = [];
        foreach ($triggers as $trigger) {
            $candidateIds[(int) $trigger->getCampaignId()] = true;
        }

        $metaByCampaign = [];
        if ($candidateIds !== []) {
            $campaigns = $this->campaignCollectionFactory->create();
            $campaigns->addIdsFilter(array_keys($candidateIds));
            $campaigns->addEnabledFilter();

            foreach ($campaigns as $campaign) {
                $conditionLogic = (string) $campaign->getData('condition_logic');
                // Same reason as dispatchScheduledTrigger()'s own holdout_percent read below -
                // not $campaign->getHoldoutPercent(), for consistency with condition_logic right
                // above it in this same loop.
                $metaByCampaign[(int) $campaign->getId()] = [
                    'logic' => $conditionLogic === 'any' ? 'any' : 'all',
                    'holdout_percent' => max(0, min(100, (int) $campaign->getData('holdout_percent'))),
                ];
            }
        }

        $this->cache->save(
            $this->serializer->serialize($metaByCampaign),
            $cacheKey,
            [self::CACHE_TAG, self::CACHE_KEY_PREFIX . $triggerEvent]
        );

        return $metaByCampaign;
    }

    /**
     * context['order_id'] is the convention every order-scoped trigger's own observer populates
     * (DispatchOrderPlacedCampaigns, ...) — the same key SendSms/SendEmail already read for
     * CampaignOutcomeLogger::logSent()'s own $triggerOrderId, reused here identically so a
     * held-out customer's row gets the same race protection a sent row's does (see that method's
     * own docblock).
     *
     * @param array<string, mixed> $context
     */
    private function triggerOrderIdFromContext(array $context): ?int
    {
        return isset($context['order_id']) ? (int) $context['order_id'] : null;
    }

    /**
     * Drops any candidate campaign that's website-scoped (has at least one ordo_campaign_website
     * row) but doesn't include the triggering customer's own website — a campaign with NO rows
     * at all is unscoped (matches every website, the default/backward-compatible behavior; see
     * docs/adr/0014) and is never touched here. Deliberately NOT baked into
     * campaignIdsForTrigger()'s own cached payload (which is keyed only by $triggerEvent, shared
     * across every customer/website that fires it) — this runs as a cheap extra filter AFTER
     * that cache hit/miss, against the already-narrowed candidate set, so none of the existing
     * cache-invalidation call sites for that cached lookup need to change.
     *
     * @param array<int, array{logic: 'all'|'any', holdout_percent: int}> $campaignMetaById
     * @param array<string, mixed> $context
     * @return array<int, array{logic: 'all'|'any', holdout_percent: int}>
     */
    private function filterByWebsite(array $campaignMetaById, array $context): array
    {
        $websiteRows = $this->campaignWebsiteCollectionFactory->create()
            ->addCampaignIdsFilter(array_keys($campaignMetaById));

        $websiteIdsByCampaign = [];
        foreach ($websiteRows as $row) {
            /** @var CampaignWebsite $row */
            $websiteIdsByCampaign[$row->getCampaignId()][] = $row->getWebsiteId();
        }

        if ($websiteIdsByCampaign === []) {
            // None of this trigger's candidate campaigns are website-scoped at all (by far the
            // common case) - skip resolving the customer's own website for nothing.
            return $campaignMetaById;
        }

        $customerWebsiteId = $this->customerWebsiteIdFromContext($context);

        foreach ($websiteIdsByCampaign as $campaignId => $websiteIds) {
            // Can't verify a website-scoped campaign's own scope condition without a resolved
            // customer website id - fails closed (skip, don't run) for THAT campaign, same
            // "can't verify, don't run" philosophy as an unknown condition/action type, rather
            // than silently running every website-scoped campaign for everyone.
            if ($customerWebsiteId === null || !in_array($customerWebsiteId, $websiteIds, true)) {
                unset($campaignMetaById[$campaignId]);
            }
        }

        return $campaignMetaById;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function customerWebsiteIdFromContext(array $context): ?int
    {
        $customerId = $this->customerIdFromContext($context);
        if ($customerId === null) {
            return null;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable) {
            return null;
        }

        return $this->customerStoreResolver->resolveWebsiteId($customer);
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
        // conditions either way (see SegmentMatcher's own docblock). Handled here, before ever
        // reaching ConditionGroupEvaluator, since that policy is specific to this caller.
        if ($conditions === []) {
            return true;
        }

        $specs = [];
        foreach ($conditions as $conditionRow) {
            $specs[] = ['type' => (string) $conditionRow->getData('type'), 'params' => $conditionRow->getParams()];
        }

        return $this->conditionGroupEvaluator->evaluate($specs, $logic, $context, 'campaign condition');
    }

    /**
     * Resumes a campaign's action chain starting at (and including) the action with entity_id
     * $resumeActionId, once its delay_minutes has elapsed — Cron\RunScheduledCampaignActions's
     * only real job is finding due rows and calling this.
     *
     * The three lookups behind a resume (campaign-enabled check, resume-row lookup, action list)
     * are identical for every row of the same (campaign, resume action) — a delayed broadcast can
     * mean thousands of such rows in one cron run. $lookupMemo caches them per caller: a caller
     * processing many rows (the cron) passes ONE array across its whole run, keeping the memo's
     * lifetime that of the caller's loop, never of this (potentially consumer-long-lived) object.
     * Mid-run staleness (a campaign disabled between rows) is the accepted trade for ~3 queries
     * per distinct campaign instead of per row.
     *
     * @param array<string, mixed> $context
     * @param array{
     *     enabled?: array<int, bool>,
     *     plan?: array<string, array{actions: array<int, CampaignAction>, start: int}|null>
     * } $lookupMemo
     */
    public function resumeScheduledAction(
        int $campaignId,
        int $resumeActionId,
        array $context,
        array &$lookupMemo = []
    ): void {
        $lookupMemo['enabled'][$campaignId] ??= $this->isCampaignEnabled($campaignId);
        if (!$lookupMemo['enabled'][$campaignId]) {
            return;
        }

        $planKey = $campaignId . ':' . $resumeActionId;
        if (!array_key_exists($planKey, $lookupMemo['plan'] ?? [])) {
            // array_key_exists, not ??=: a null plan ("nothing left to resume into") is itself a
            // memoized answer, not a cache miss to re-query on every row.
            $lookupMemo['plan'][$planKey] = $this->loadResumePlan($campaignId, $resumeActionId);
        }

        $plan = $lookupMemo['plan'][$planKey];
        if ($plan === null) {
            return;
        }

        // The action at the plan's start index is the one that WAS delayed — its wait is exactly
        // what just elapsed to get here, so it must run unconditionally this time, not be
        // re-scheduled again for another delay_minutes just because that column is still > 0 on
        // the row. Only actions AFTER it go through the normal delay check.
        $context['campaign_id'] = $campaignId;
        $this->runOneActionOrSplit($campaignId, $plan['actions'][$plan['start']], $context);
        $this->runActionsFrom($campaignId, $plan['actions'], $plan['start'] + 1, $context);
    }

    private function isCampaignEnabled(int $campaignId): bool
    {
        // Reported directly: disabling a campaign is documented as pausing it ("disable to pause
        // it without deleting it" - README/CLAUDE.md), but a customer already parked mid-chain on
        // a delay_minutes step would still get every remaining action run once the resume cron
        // reached them, since (unlike dispatch()/dispatchScheduledTrigger() above) nothing here
        // re-checked the campaign was still enabled. Same "not found" shape as the resume-row
        // guard in loadResumePlan() - existing but disabled isn't an error, just nothing to
        // resume into.
        $campaigns = $this->campaignCollectionFactory->create();
        $campaigns->addIdsFilter([$campaignId]);
        $campaigns->addEnabledFilter();

        return (bool) $campaigns->getFirstItem()->getId();
    }

    /**
     * @return array{actions: array<int, CampaignAction>, start: int}|null null when there is
     *     nothing left to resume into
     */
    private function loadResumePlan(int $campaignId, int $resumeActionId): ?array
    {
        // One small lookup for the resume row's own sort_order, so the main query below can be
        // filtered to "sort_order >= that" instead of loading every action in the campaign just
        // to linear-scan for the one entity_id we already know.
        $resumeRowCollection = $this->campaignActionCollectionFactory->create();
        $resumeRowCollection->addFieldToFilter('campaign_id', ['eq' => $campaignId]);
        $resumeRowCollection->addFieldToFilter('entity_id', ['eq' => $resumeActionId]);
        // getFirstItem() always returns a model instance - a fresh, id-less one when nothing
        // matched - never null, so getEntityId() === null is how "not found" shows up here.
        $resumeRow = $resumeRowCollection->getFirstItem();

        // The action (or the whole campaign) could have been deleted/edited between when this
        // was scheduled and now — nothing left to resume into, not an error.
        if ($resumeRow->getEntityId() === null) {
            return null;
        }

        $actions = $this->campaignActionCollectionFactory->create();
        $actions->addCampaignFilter($campaignId);
        $actions->addFieldToFilter('sort_order', ['gteq' => $resumeRow->getSortOrder()]);
        $actions = array_values(iterator_to_array($actions));
        $startIndex = null;
        foreach ($actions as $index => $actionRow) {
            if ((int) $actionRow->getEntityId() === $resumeActionId) {
                $startIndex = $index;
                break;
            }
        }

        // Same "nothing left to resume into" guard as above, for the (very unlikely) case where
        // the resume row itself still exists but no longer sorts as >= its own sort_order — e.g.
        // a concurrent edit between the two queries above.
        if ($startIndex === null) {
            return null;
        }

        return ['actions' => $actions, 'start' => $startIndex];
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

            $this->runOneActionOrSplit($campaignId, $actionRow, $context);
        }
    }

    /**
     * Same "reserved pseudo-type handled before the pool lookup" shape as 'group' is for
     * conditions (see evaluateOne() above) - 'split' is deliberately NOT an ActionPool entry,
     * since it has no send/tag/whatever effect of its own, only a branching one. Shared by both
     * runActionsFrom()'s own loop and resumeScheduledAction()'s direct call onto the just-elapsed
     * action - a split action with its own delay_minutes set is deferred via scheduleResume() the
     * same as any other action (see the delay check above), so the row resumeScheduledAction()
     * later runs unconditionally can itself be a 'split' row; calling runOneAction() on it
     * directly there (its pre-existing shape) looked it up in ActionPool, found nothing (by
     * design), and silently skipped the branch entirely instead of running either variant.
     *
     * @param array<string, mixed> $context
     */
    private function runOneActionOrSplit(int $campaignId, CampaignAction $actionRow, array &$context): void
    {
        if ((string) $actionRow->getData('type') === 'split') {
            $this->runSplit($campaignId, $actionRow, $context);
            return;
        }

        $this->runOneAction($actionRow, $context);
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

            $rawParams = $spec['params'] ?? [];

            /** @var CampaignAction $actionRow */
            $actionRow = $this->campaignActionFactory->create();
            $actionRow->setCampaignId($campaignId)
                ->setType($spec['type'])
                // Encoded as-is: the synthetic row's own getParams() re-decodes through
                // ParamsMap, which drops whatever non-string keys a hand-written variant spec's
                // params blob could contain - only "not an array at all" is handled here.
                ->setParamsJson((string) json_encode(is_array($rawParams) ? $rawParams : []))
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
     * philosophy as Model\Condition\GroupWalker's own malformed-nested-group-item handling.
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

        // Stamped so a Send* action can hand its own action id to QuietHoursGate - the one piece
        // of identity actions didn't already receive via context (campaign_id/ordo_split_variant
        // were already stamped elsewhere). Not persisted anywhere itself; only meaningful for the
        // duration of this one execute() call.
        $context['ordo_action_id'] = (int) $actionRow->getEntityId();

        $action->execute($context, $actionRow->getParams());
    }

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
        $this->writeScheduledAction(
            $campaignId,
            $resumeActionId,
            $context,
            date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes"))
        );
    }

    /**
     * Public counterpart to scheduleResume() for deferrals that aren't a fixed "N minutes from
     * now" delay - today only QuietHoursGate, which computes an explicit "quiet hours end" UTC
     * instant rather than a duration. Writes the exact same ordo_campaign_scheduled_action row
     * shape scheduleResume() does (same FK to a real, persisted ordo_campaign_action row -
     * $actionId must be one, same known limitation for synthetic split-variant actions as
     * delay_minutes already has, see runSplit()'s docblock), so resumeScheduledAction() and
     * CampaignEntryGuard's pending-entry dedup both apply to a deferred send automatically, with
     * no extra code on either side.
     *
     * @param array<string, mixed> $context
     */
    public function deferActionUntil(int $campaignId, int $actionId, string $runAtUtc, array $context): void
    {
        $this->writeScheduledAction($campaignId, $actionId, $context, $runAtUtc);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function writeScheduledAction(int $campaignId, int $resumeActionId, array $context, string $runAt): void
    {
        $scheduled = $this->campaignScheduledActionFactory->create();
        $scheduled->setCampaignId($campaignId);
        $scheduled->setResumeActionId($resumeActionId);
        $scheduled->setCustomerId($this->customerIdFromContext($context));
        $scheduled->setContext($context);
        $scheduled->setRunAt($runAt);

        $this->campaignScheduledActionResource->save($scheduled);
    }
}
