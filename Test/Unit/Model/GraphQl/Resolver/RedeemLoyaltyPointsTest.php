<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Api\LoyaltyPointsManagementInterface;
use Ordo\Automation\Model\GraphQl\Resolver\RedeemLoyaltyPoints;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class RedeemLoyaltyPointsTest extends TestCase
{
    private LoyaltyPointsManagementInterface $loyaltyPointsManagement;

    protected function setUp(): void
    {
        $this->loyaltyPointsManagement = $this->createMock(LoyaltyPointsManagementInterface::class);
    }

    private function resolve(): mixed
    {
        $resolver = new RedeemLoyaltyPoints($this->loyaltyPointsManagement);

        return $resolver->resolve(
            $this->createStub(Field::class),
            $this->createStub(ContextInterface::class),
            $this->createStub(ResolveInfo::class)
        );
    }

    public function testReturnsOkTrueWithTheGeneratedCouponCode(): void
    {
        $this->loyaltyPointsManagement->expects(self::once())->method('selfRedeem')->willReturn('LOYALTY-ABC123');

        self::assertSame(['ok' => true, 'couponCode' => 'LOYALTY-ABC123'], $this->resolve());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsOkFalseWithReasonWhenNotEnoughPoints(): void
    {
        $this->loyaltyPointsManagement->method('selfRedeem')
            ->willThrowException(new LocalizedException(__('Not enough points to redeem.')));

        self::assertSame(['ok' => false, 'reason' => 'Not enough points to redeem.'], $this->resolve());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsGraphQlNoSuchEntityExceptionWhenUnauthenticated(): void
    {
        $this->loyaltyPointsManagement->method('selfRedeem')
            ->willThrowException(new NoSuchEntityException(__('No authenticated customer for this request.')));

        $this->expectException(GraphQlNoSuchEntityException::class);

        $this->resolve();
    }
}
