<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as CampaignConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
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
 */
class CampaignDispatcher
{
    public function __construct(
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly CampaignConditionCollectionFactory $campaignConditionCollectionFactory,
        private readonly CampaignActionCollectionFactory $campaignActionCollectionFactory,
        private readonly ConditionPool $conditionPool,
        private readonly ActionPool $actionPool,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function dispatch(string $triggerEvent, array $context): void
    {
        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addTriggerEventFilter($triggerEvent);

        $campaignIds = [];
        foreach ($triggers as $trigger) {
            $campaignIds[(int) $trigger->getCampaignId()] = true;
        }

        if (!$campaignIds) {
            return;
        }

        $campaigns = $this->campaignCollectionFactory->create();
        $campaigns->addIdsFilter(array_keys($campaignIds));
        $campaigns->addEnabledFilter();

        foreach ($campaigns as $campaign) {
            try {
                if (!$this->allConditionsSatisfied((int) $campaign->getId(), $context)) {
                    continue;
                }

                $this->runActions((int) $campaign->getId(), $context);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Ordo_Automation: campaign #%d failed for trigger "%s": %s', $campaign->getId(), $triggerEvent, $e->getMessage())
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function allConditionsSatisfied(int $campaignId, array $context): bool
    {
        $conditions = $this->campaignConditionCollectionFactory->create();
        $conditions->addCampaignFilter($campaignId);

        foreach ($conditions as $conditionRow) {
            $condition = $this->conditionPool->get((string) $conditionRow->getData('type'));

            if ($condition === null) {
                $this->logger->error(sprintf('Ordo_Automation: unknown campaign condition type "%s".', $conditionRow->getData('type')));
                return false;
            }

            if (!$condition->isSatisfied($context, $conditionRow->getParams())) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function runActions(int $campaignId, array $context): void
    {
        $actions = $this->campaignActionCollectionFactory->create();
        $actions->addCampaignFilter($campaignId);

        foreach ($actions as $actionRow) {
            $action = $this->actionPool->get((string) $actionRow->getData('type'));

            if ($action === null) {
                $this->logger->error(sprintf('Ordo_Automation: unknown campaign action type "%s".', $actionRow->getData('type')));
                continue;
            }

            $action->execute($context, $actionRow->getParams());
        }
    }
}
