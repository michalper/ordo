<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
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
        private readonly LoggerInterface $logger
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

        try {
            $context['coupon_code'] = $this->couponGenerator->generate($ruleId, $prefix);
            // TEMPORARY diagnostic logging — AdminCampaignScenarioEndToEndTest's coupon still
            // never appears in the grid even after switching the assertion to <waitForText> (a
            // full 60s poll, not a one-shot check), and the workflow's own post-test DB dump is
            // unreliable here: the test's own <after> block deletes the sales rule, which likely
            // cascades its coupons before that dump ever runs, so an empty dump doesn't actually
            // prove generation failed. A log line survives that cleanup untouched. Remove once
            // confirmed either way.
            $this->logger->info(sprintf(
                'ORDO_DEBUG generate_coupon succeeded: ruleId=%d code=%s',
                $ruleId,
                $context['coupon_code']
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: failed to generate coupon for rule #%d: %s',
                $ruleId,
                $e->getMessage()
            ));
        }
    }
}
