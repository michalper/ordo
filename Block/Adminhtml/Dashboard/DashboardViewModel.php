<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Dashboard;

use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\Data\CampaignInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignOutcomeLogger;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\LoyaltyTierCalculator;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;
use Ordo\Automation\Model\TriggerOutcomeLogger;

/**
 * Reads directly from the collections (server-rendered), not the REST API — this page lives
 * inside the admin session already, so there's no separate auth/CORS story to solve.
 */
class DashboardViewModel implements ArgumentInterface
{
    private const array TRIGGER_LABELS = [
        CampaignTriggerInterface::TRIGGER_ORDER_PLACED => 'Order Placed',
        CampaignTriggerInterface::TRIGGER_CUSTOMER_REGISTERED => 'Customer Registered',
        CampaignTriggerInterface::TRIGGER_TAG_ADDED => 'Tag Added',
        CampaignTriggerInterface::TRIGGER_CART_ABANDONED => 'Cart Abandoned',
        CampaignTriggerInterface::TRIGGER_VISITOR_TAG_ADDED => 'Visitor Tag Added (anonymous)',
        CampaignTriggerInterface::TRIGGER_SCORE_THRESHOLD_CROSSED => 'Score Threshold Crossed',
    ];

    /**
     * The fixed, known trigger set (Model\Config\Source\TriggerEvent's list) — every one of
     * these always gets a dashboard row, including a trigger no campaign currently uses (0),
     * rather than only showing rows for triggers that happen to already have a campaign. That's
     * the point of a "per fixed trigger" breakdown: it answers "which of the triggers we
     * support are actually being used" just as much as "how many campaigns use each one".
     */
    private const array FIXED_TRIGGER_EVENTS = [
        CampaignTriggerInterface::TRIGGER_ORDER_PLACED,
        CampaignTriggerInterface::TRIGGER_CUSTOMER_REGISTERED,
        CampaignTriggerInterface::TRIGGER_TAG_ADDED,
        CampaignTriggerInterface::TRIGGER_CART_ABANDONED,
        CampaignTriggerInterface::TRIGGER_VISITOR_TAG_ADDED,
        CampaignTriggerInterface::TRIGGER_SCORE_THRESHOLD_CROSSED,
    ];

    /**
     * Labels for the 5 cron-driven triggers tracked by TriggerOutcomeLogger — a separate set
     * from TRIGGER_LABELS/FIXED_TRIGGER_EVENTS above, which are campaign trigger *events*
     * (order_placed, tag_added, ...), not these standalone scheduled sends.
     */
    private const array TRIGGER_OUTCOME_LABELS = [
        TriggerOutcomeLogger::TRIGGER_REORDER_REMINDER => 'Reorder Reminder',
        TriggerOutcomeLogger::TRIGGER_OFFER_EXPIRY => 'Offer Expiry',
        TriggerOutcomeLogger::TRIGGER_CREDIT_LIMIT_ALERT => 'Credit Limit Alert',
        TriggerOutcomeLogger::TRIGGER_ORDER_APPROVAL => 'Order Approval',
        TriggerOutcomeLogger::TRIGGER_WIN_BACK => 'Win-Back',
    ];

    /**
     * @var array<int, string>|null Trigger labels per campaign_id, keyed like
     * getTriggerLabelsForCampaign()'s return value — built in one query by getCampaigns()
     * so the per-campaign lookup below doesn't have to hit the database again.
     */
    private ?array $triggerLabelsByCampaignId = null;

    public function __construct(
        private readonly CampaignCollectionFactory $campaignCollectionFactory,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly ReorderCycleCollectionFactory $reorderCycleCollectionFactory,
        private readonly FreeGiftOfferCollectionFactory $freeGiftOfferCollectionFactory,
        private readonly TriggerOutcomeLogger $triggerOutcomeLogger,
        private readonly CampaignOutcomeLogger $campaignOutcomeLogger,
        private readonly PricingHelper $pricingHelper,
        private readonly LoyaltyTierCalculator $loyaltyTierCalculator,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isShoppingFeedEnabled(): bool
    {
        return $this->config->isShoppingFeedEnabled();
    }

    /**
     * The real, public URL a shopping-channel platform would be given — built from the store's
     * own base URL, not a hardcoded frontName guess, so it's correct in every environment
     * (custom domain, subfolder store, https).
     */
    public function getShoppingFeedUrl(): string
    {
        try {
            /** @var \Magento\Store\Model\Store $store getBaseUrl() isn't declared on
             *  StoreInterface, only the concrete Store model. */
            $store = $this->storeManager->getStore();
            return rtrim((string) $store->getBaseUrl(), '/') . '/ordo/productfeed/index';
        } catch (\Throwable) {
            return '';
        }
    }

    public function formatCurrency(float $amount): string
    {
        return (string) $this->pricingHelper->currency($amount, true, false);
    }

    /**
     * @return CampaignInterface[]
     */
    public function getCampaigns(): array
    {
        $collection = $this->campaignCollectionFactory->create();
        $collection->setOrder('entity_id', 'DESC');

        $campaigns = [];
        $campaignIds = [];
        foreach ($collection as $campaign) {
            /** @var Campaign $campaign */
            $campaigns[] = $campaign;
            $campaignIds[] = (int) $campaign->getEntityId();
        }

        $this->triggerLabelsByCampaignId = $this->loadTriggerLabelsByCampaignId($campaignIds);

        return $campaigns;
    }

    public function getTotalCampaignCount(): int
    {
        return $this->campaignCollectionFactory->create()->getSize();
    }

    public function getEnabledCampaignCount(): int
    {
        $collection = $this->campaignCollectionFactory->create();
        $collection->addFieldToFilter('enabled', '1');

        return $collection->getSize();
    }

    public function getReorderCycleCount(): int
    {
        return $this->reorderCycleCollectionFactory->create()->getSize();
    }

    public function getFreeGiftOfferCount(): int
    {
        return $this->freeGiftOfferCollectionFactory->create()->getSize();
    }

    /**
     * @return array<int, array{tier: string, label: string, count: int}> bronze/silver/gold, in
     *   ascending order - same "always show every row, even zero" convention as
     *   getFixedTriggerEvents() above.
     */
    public function getLoyaltyTierDistribution(): array
    {
        $distribution = $this->loyaltyTierCalculator->getTierDistribution();

        $rows = [];
        foreach ($this->loyaltyTierCalculator->getAllTiers() as $tier) {
            $rows[] = [
                'tier' => $tier,
                'label' => $this->loyaltyTierCalculator->getTierLabel($tier),
                'count' => $distribution[$tier] ?? 0,
            ];
        }

        return $rows;
    }

    public function getTriggerLabel(string $triggerEvent): string
    {
        return self::TRIGGER_LABELS[$triggerEvent] ?? $triggerEvent;
    }

    /**
     * How many campaigns use this trigger. One row per (campaign_id, trigger_event) — the
     * unique constraint on ordo_campaign_trigger (see Save.php's saveTriggers()) means a
     * campaign can never have the same trigger twice, so a plain filtered count is already an
     * accurate campaign count, no explicit DISTINCT needed.
     */
    public function getCampaignCountForTrigger(string $triggerEvent): int
    {
        $collection = $this->campaignTriggerCollectionFactory->create();
        $collection->addFieldToFilter('trigger_event', $triggerEvent);

        return $collection->getSize();
    }

    /**
     * @return string[]
     */
    public function getFixedTriggerEvents(): array
    {
        return self::FIXED_TRIGGER_EVENTS;
    }

    /**
     * A campaign can fire on more than one trigger event now — the dashboard card shows all of
     * them, comma-separated, rather than just the first.
     */
    public function getTriggerLabelsForCampaign(int $campaignId): string
    {
        $this->triggerLabelsByCampaignId ??= $this->loadTriggerLabelsByCampaignId([$campaignId]);

        return $this->triggerLabelsByCampaignId[$campaignId] ?? (string) __('No trigger configured');
    }

    /**
     * One query for every campaign_id in $campaignIds, instead of one query per campaign —
     * the fix for the dashboard's former N+1 (getTriggerLabelsForCampaign() used to run its own
     * ordo_campaign_trigger query per campaign row).
     *
     * @param int[] $campaignIds
     * @return array<int, string> Comma-separated trigger labels, keyed by campaign_id; a
     * campaign with no triggers gets no entry here, resolved to "No trigger configured" by the
     * caller instead, to keep the shape identical to the old per-campaign method.
     */
    private function loadTriggerLabelsByCampaignId(array $campaignIds): array
    {
        if (!$campaignIds) {
            return [];
        }

        $triggers = $this->campaignTriggerCollectionFactory->create();
        $triggers->addFieldToFilter('campaign_id', ['in' => $campaignIds]);

        $labelsByCampaignId = [];
        foreach ($triggers as $trigger) {
            /** @var CampaignTrigger $trigger */
            $campaignId = $trigger->getCampaignId();
            $labelsByCampaignId[$campaignId][] = $this->getTriggerLabel($trigger->getTriggerEvent());
        }

        return array_map(
            static fn (array $labels): string => implode(', ', $labels),
            $labelsByCampaignId
        );
    }

    public function getTriggerOutcomeLabel(string $triggerType): string
    {
        return self::TRIGGER_OUTCOME_LABELS[$triggerType] ?? $triggerType;
    }

    /**
     * Cross-campaign totals (sent/converted/conversion_rate/revenue, summed across every
     * campaign and variant) for a single dashboard summary card — the per-campaign, per-variant
     * breakdown (plus opened/clicked, which this summary deliberately omits since they'd need a
     * broader scan across every campaign's ordo_message_log_event rows) lives on each campaign's
     * own edit page via Block\Adminhtml\Campaign\FunnelViewModel instead. Not a new menu item —
     * see etc/adminhtml/menu.xml's own comment on why sub-features surface as dashboard cards.
     *
     * @return array{sent: int, converted: int, conversion_rate: float, revenue: float}
     */
    public function getCampaignFunnelSummary(): array
    {
        $sent = 0;
        $converted = 0;
        $revenue = 0.0;

        foreach ($this->campaignOutcomeLogger->getStats() as $row) {
            $sent += $row['sent'];
            $converted += $row['converted'];
            $revenue += $row['revenue'];
        }

        return [
            'sent' => $sent,
            'converted' => $converted,
            'conversion_rate' => $sent > 0 ? round($converted / $sent * 100, 1) : 0.0,
            'revenue' => $revenue,
        ];
    }

    /**
     * Sent count, responded count, response rate percent for each of the 5 cron-driven
     * triggers — a fixed row per trigger, same "always show all, even zero" pattern as
     * getFixedTriggerEvents(), so a trigger that hasn't sent anything yet still gets a row.
     * One aggregate query via TriggerOutcomeLogger::getStats(), not one per trigger.
     *
     * @return array<string, array{
     *     label: string,
     *     sent: int,
     *     responded: int,
     *     response_rate: float,
     *     recovered_revenue: float
     * }>
     */
    public function getTriggerStats(): array
    {
        $stats = $this->triggerOutcomeLogger->getStats();

        $result = [];
        foreach (TriggerOutcomeLogger::TRIGGER_TYPES as $triggerType) {
            $triggerStats = $stats[$triggerType] ?? [
                'sent' => 0,
                'responded' => 0,
                'response_rate' => 0.0,
                'recovered_revenue' => 0.0,
            ];
            $result[$triggerType] = [
                'label' => $this->getTriggerOutcomeLabel($triggerType),
                'sent' => $triggerStats['sent'],
                'responded' => $triggerStats['responded'],
                'response_rate' => $triggerStats['response_rate'],
                'recovered_revenue' => $triggerStats['recovered_revenue'],
                'recovered_revenue_formatted' => $this->formatCurrency($triggerStats['recovered_revenue']),
            ];
        }

        return $result;
    }
}
