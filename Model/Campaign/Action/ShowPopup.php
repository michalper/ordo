<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\RewardTierSelector;
use Ordo\Automation\Model\CouponGenerator;
use Ordo\Automation\Model\PendingPopup;
use Ordo\Automation\Model\PendingPopupFactory;
use Ordo\Automation\Model\ResourceModel\PendingPopup as PendingPopupResource;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Params: {"headline": "...", "body": "...", "cta_label": "...", "cta_url": "...",
 * "reward_tiers": [{"label": "10% off", "weight": 50, "coupon_rule_id": 3}, {"label": "Try
 * again", "weight": 50}]}. Unlike every other action, this doesn't send anything itself — it
 * queues a row in ordo_pending_popup that Controller\Track\Poll hands out the next time the
 * target's browser polls (view/frontend/web/js/tracker.js), because there is no synchronous way
 * to push something onto a page from inside a campaign dispatch.
 *
 * Targets whichever identifier the triggering context actually has: context["customer_id"] for
 * customer-only triggers (order_placed, tag_added, ...), context["visitor_id"] for the anonymous
 * visitor_tag_added trigger. At least one must be present, or there is no browser to eventually
 * deliver this to and the action is a no-op (logged, not thrown — same fail-closed pattern as
 * every other action here).
 *
 * Frequency-capped: if the target already received a popup within the configured window
 * (Config::getPopupFrequencyCapHours(), default 24h, 0 disables it), this is a silent no-op —
 * not logged as an error, since skipping is the intended behavior, not a failure. This is also
 * the entire "can't just keep re-spinning" guard for reward_tiers below: a new wheel spin only
 * ever happens as part of a freshly-queued popup, which this same cap already gates, so there is
 * no separate per-wheel cap to invent.
 *
 * reward_tiers, when given and non-empty, turns this into a "spin the wheel"/scratch-card popup:
 * Model\Campaign\RewardTierSelector picks one tier (a real random roll per delivery, not sticky
 * per customer — see that class's own docblock for why it's not SplitVariantSelector), and a
 * tier carrying a coupon_rule_id gets a real single-use coupon minted for it via
 * Model\CouponGenerator, same as the generate_coupon action. A tier with no coupon_rule_id (e.g.
 * a "Try again" tier) only ever sets reward_label — never a coupon. reward_tiers absent or empty
 * (every pre-existing campaign) leaves the popup exactly as before: a plain static banner, no
 * reward_label/coupon_code set at all.
 */
class ShowPopup implements ActionInterface
{
    public function __construct(
        private readonly PendingPopupFactory $pendingPopupFactory,
        private readonly PendingPopupResource $pendingPopupResource,
        private readonly PendingPopupCollectionFactory $pendingPopupCollectionFactory,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly ContextTargetResolver $contextTargetResolver,
        private readonly RewardTierSelector $rewardTierSelector,
        private readonly CouponGenerator $couponGenerator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $target = $this->contextTargetResolver->resolveCustomerOrVisitor($context);
        $headline = trim((string) ($params['headline'] ?? ''));

        if ($target->isEmpty()) {
            $this->logger->error(
                'Ordo_Automation: popup action has no customer_id or visitor_id in context to target.'
            );
            return;
        }

        if ($headline === '') {
            $this->logger->error('Ordo_Automation: popup action is missing a headline.');
            return;
        }

        $capHours = $this->config->getPopupFrequencyCapHours();
        if ($capHours > 0) {
            $since = date('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - $capHours * 3600);
            $recentlyShown = $this->pendingPopupCollectionFactory->create()
                ->targetHasRecentlyReceivedPopup($target->customerId, $target->visitorId, $since);

            if ($recentlyShown) {
                return;
            }
        }

        $popup = $this->pendingPopupFactory->create();
        $popup->setCustomerId($target->customerId);
        $popup->setVisitorId($target->visitorId);
        $popup->setHeadline($headline);
        $popup->setBody($this->contextTargetResolver->nullableString($params['body'] ?? null));
        $popup->setCtaLabel($this->contextTargetResolver->nullableString($params['cta_label'] ?? null));
        $popup->setCtaUrl($this->contextTargetResolver->nullableString($params['cta_url'] ?? null));

        $this->applyRewardTier($popup, $params, $context);

        $this->pendingPopupResource->save($popup);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $context
     */
    private function applyRewardTier(PendingPopup $popup, array $params, array $context): void
    {
        $tiers = $this->normalizeRewardTiers($params['reward_tiers'] ?? null);
        if ($tiers === []) {
            return;
        }

        $tier = $this->rewardTierSelector->selectTier($tiers);
        $popup->setRewardLabel($tier['label']);

        $ruleId = $tier['coupon_rule_id'];
        if ($ruleId === null) {
            return;
        }

        $campaignId = isset($context['campaign_id']) ? (int) $context['campaign_id'] : null;

        try {
            $popup->setCouponCode($this->couponGenerator->generate($ruleId, 'ORDO-WHEEL', campaignId: $campaignId));
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: popup action failed to generate a reward coupon for rule #%d: %s',
                $ruleId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Validates/normalizes a raw reward_tiers param into a clean list of
     * {label, weight, coupon_rule_id} - malformed entries are dropped rather than crashing the
     * action, same fail-closed philosophy as CampaignDispatcher::normalizeVariants() for split's
     * own weighted-list param. Accepts either a real array (params saved via a direct API/JSON
     * call) or a JSON-encoded string (the admin's own plain-text param field for this, which has
     * no dedicated array-editor widget — see docs/adr/0059 for why that trade-off was accepted).
     *
     * @return array<int, array{label: string, weight: float, coupon_rule_id: int|null}>
     */
    private function normalizeRewardTiers(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        if (!is_array($raw)) {
            return [];
        }

        $tiers = [];
        foreach ($raw as $rawTier) {
            if (!is_array($rawTier) || !isset($rawTier['label']) || !is_string($rawTier['label'])
                || $rawTier['label'] === ''
            ) {
                continue;
            }

            $weight = $rawTier['weight'] ?? 0;
            if (!is_int($weight) && !is_float($weight)) {
                continue;
            }

            $ruleId = $rawTier['coupon_rule_id'] ?? null;
            $ruleId = (is_int($ruleId) || is_float($ruleId)) && (int) $ruleId > 0 ? (int) $ruleId : null;

            $tiers[] = [
                'label' => $rawTier['label'],
                'weight' => (float) $weight,
                'coupon_rule_id' => $ruleId,
            ];
        }

        return $tiers;
    }

    public function getParamFields(): array
    {
        return [
            ['name' => 'headline', 'label' => (string) __('Popup headline')],
            ['name' => 'body', 'label' => (string) __('Popup body')],
            ['name' => 'cta_label', 'label' => (string) __('CTA button label')],
            ['name' => 'cta_url', 'label' => (string) __('CTA button URL')],
            [
                'name' => 'reward_tiers',
                'label' => (string) __('Reward tiers (JSON)'),
                'notice' => (string) __(
                    'Optional: a JSON array of weighted reward tiers (label, weight, coupon_rule_id). '
                    . 'One tier is randomly picked per popup; leave blank for a plain static popup.'
                ),
            ],
        ];
    }
}
