<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Math\Random;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;

/**
 * Generates a single-use coupon code tied to an existing SalesRule (the admin creates the
 * discount rule once — e.g. "10% off", coupon type "Specific Coupon" — and this just mints
 * a fresh code against it per customer/event, instead of every customer sharing one code).
 */
class CouponGenerator
{
    public function __construct(
        private readonly CouponFactory $couponFactory,
        private readonly CouponResource $couponResource,
        private readonly Random $random
    ) {
    }

    public function generate(int $ruleId, string $prefix = 'ORDO', int $usageLimit = 1): string
    {
        $code = $prefix . '-' . strtoupper($this->random->getRandomString(10));

        $coupon = $this->couponFactory->create();
        $coupon->setRuleId($ruleId);
        $coupon->setCode($code);
        // Rule::COUPON_TYPE_SPECIFIC — this is a specific-code coupon tied to the rule,
        // not the rule's single "no coupon" mode or the admin mass-generator's own type.
        $coupon->setType(\Magento\SalesRule\Model\Rule::COUPON_TYPE_SPECIFIC);
        $coupon->setUsageLimit($usageLimit);
        $coupon->setUsagePerCustomer(1);
        // Deliberately NOT calling setIsPrimary() at all — leave the column NULL. The admin's
        // own "Manage Coupon Codes" grid (Magento\SalesRule\Model\ResourceModel\Coupon\
        // Collection::addGeneratedCouponsFilter()) filters is_primary with an explicit
        // ['null' => 1] condition, not "is_primary = 0" — a coupon saved with is_primary=false
        // (which persists as 0, not NULL) is silently excluded from that query. Confirmed via a
        // real CI run: the coupon generates successfully every time (logged, code returned) but
        // never appeared in the grid even after a full 60s poll, with salesrule_coupon
        // unreachable post-hoc since the test's own cleanup deletes the rule (cascades its
        // coupons) before any diagnostic dump could run — this filter mismatch is the actual
        // root cause, not a display/timing race.

        $this->couponResource->save($coupon);

        return $code;
    }
}
