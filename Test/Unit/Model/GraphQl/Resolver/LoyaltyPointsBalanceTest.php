<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Api\LoyaltyPointsManagementInterface;
use Ordo\Automation\Model\GraphQl\Resolver\LoyaltyPointsBalance;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class LoyaltyPointsBalanceTest extends TestCase
{
    private LoyaltyPointsManagementInterface $loyaltyPointsManagement;

    protected function setUp(): void
    {
        $this->loyaltyPointsManagement = $this->createMock(LoyaltyPointsManagementInterface::class);
    }

    private function resolve(): mixed
    {
        $resolver = new LoyaltyPointsBalance($this->loyaltyPointsManagement);

        return $resolver->resolve(
            $this->createStub(Field::class),
            $this->createStub(ContextInterface::class),
            $this->createStub(ResolveInfo::class)
        );
    }

    public function testReturnsTheRealBalance(): void
    {
        $this->loyaltyPointsManagement->expects(self::once())->method('getMyBalance')->willReturn(1200);

        self::assertSame(1200, $this->resolve());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsGraphQlNoSuchEntityExceptionWhenUnauthenticated(): void
    {
        $this->loyaltyPointsManagement->method('getMyBalance')
            ->willThrowException(new NoSuchEntityException(__('No authenticated customer for this request.')));

        $this->expectException(GraphQlNoSuchEntityException::class);

        $this->resolve();
    }
}
