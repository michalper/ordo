<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\Campaign\OptionSourceFlattener;
use Ordo\Automation\Model\Config\Source\CartPriceRuleOptions;
use Ordo\Automation\Model\CouponGenerator;
use Psr\Log\LoggerInterface;

/**
 * Params: {"rule_id": "5", "prefix": "COMEBACK"}. Writes "coupon_code" into the context so a
 * later "send_email" action on the same campaign can reference {{var coupon_code}}.
 */
class GenerateCoupon implements ActionInterface
{
    public function __construct(
        private readonly CouponGenerator $couponGenerator,
        private readonly LoggerInterface $logger,
        private readonly CartPriceRuleOptions $cartPriceRuleOptions
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $ruleId = (int) ($params['rule_id'] ?? 0);
        if ($ruleId <= 0) {
            $this->logger->error('Ordo_Automation: generate_coupon action is missing a valid rule_id.');
            return;
        }

        $prefix = (string) ($params['prefix'] ?? 'ORDO');
        $campaignId = isset($context['campaign_id']) ? (int) $context['campaign_id'] : null;

        // Deliberately not caught here - found during a full-repo /code-review: this method
        // used to log-and-swallow a generate() failure, which meant CampaignDispatcher's own
        // fail-closed-on-action-exception design (ADR 0068) never saw anything wrong, and the
        // chain continued to a later send_email referencing {{var coupon_code}} with no code
        // ever set. CampaignDispatcher::runOneActionOrSplitStoppingChainOnFailure() is the one
        // place that's supposed to catch an action's own exception (and it logs the same failure
        // itself, so nothing is lost by not logging it twice here).
        $context['coupon_code'] = $this->couponGenerator->generate($ruleId, $prefix, campaignId: $campaignId);
    }

    public function getParamFields(): array
    {
        return [
            [
                'name' => 'rule_id',
                'label' => (string) __('Cart price rule'),
                'options' => OptionSourceFlattener::toMap($this->cartPriceRuleOptions),
            ],
            ['name' => 'prefix', 'label' => (string) __('Coupon code prefix')],
        ];
    }
}
