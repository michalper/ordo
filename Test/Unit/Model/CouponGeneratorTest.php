<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Math\Random;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\Rule;
use Ordo\Automation\Model\CouponGenerator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CouponGeneratorTest extends TestCase
{
    public function testGenerateBuildsAndSavesCoupon(): void
    {
        $couponFactory = $this->createStub(CouponFactory::class);
        $couponResource = $this->createMock(CouponResource::class);
        $random = $this->createMock(Random::class);
        $random->method('getRandomString')->with(10)->willReturn('abcdef1234');

        $coupon = $this->createMock(Coupon::class);
        $coupon->expects(self::once())->method('setRuleId')->with(7);
        $coupon->expects(self::once())->method('setCode')->with('ORDO-ABCDEF1234');
        $coupon->expects(self::once())->method('setType')->with(Rule::COUPON_TYPE_SPECIFIC);
        $coupon->expects(self::once())->method('setUsageLimit')->with(1);
        $coupon->expects(self::once())->method('setUsagePerCustomer')->with(1);
        // Deliberately never called — see CouponGenerator's own comment: leaving is_primary
        // unset keeps the column NULL, which is what the admin's coupon codes grid filters on.
        $coupon->expects(self::never())->method('setIsPrimary');
        $couponFactory->method('create')->willReturn($coupon);

        $couponResource->expects(self::once())->method('save')->with($coupon);

        $generator = new CouponGenerator($couponFactory, $couponResource, $random);

        self::assertSame('ORDO-ABCDEF1234', $generator->generate(7));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGenerateUsesCustomPrefixAndUsageLimit(): void
    {
        $couponFactory = $this->createStub(CouponFactory::class);
        $couponResource = $this->createStub(CouponResource::class);
        $random = $this->createMock(Random::class);
        $random->method('getRandomString')->willReturn('xyz');

        $coupon = $this->createMock(Coupon::class);
        $coupon->expects(self::once())->method('setCode')->with('WELCOME-XYZ');
        $coupon->expects(self::once())->method('setUsageLimit')->with(5);
        $couponFactory->method('create')->willReturn($coupon);

        $generator = new CouponGenerator($couponFactory, $couponResource, $random);

        self::assertSame('WELCOME-XYZ', $generator->generate(1, 'WELCOME', 5));
    }
}
