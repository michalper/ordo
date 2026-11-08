<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Api\CreditLimitManagementInterface;
use Ordo\Automation\Api\Data\CreditLimitStatusInterface;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\CreditLimit;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CreditLimitTest extends TestCase
{
    private CreditLimitManagementInterface $creditLimitManagement;

    protected function setUp(): void
    {
        $this->creditLimitManagement = $this->createMock(CreditLimitManagementInterface::class);
    }

    private function resolve(int $userId): mixed
    {
        $resolver = new CreditLimit($this->creditLimitManagement, new CustomerIdResolver());

        $context = $this->createStub(ContextInterface::class);
        $context->method('getUserId')->willReturn($userId);

        return $resolver->resolve(
            $this->createStub(Field::class),
            $context,
            $this->createStub(ResolveInfo::class),
            null,
            null
        );
    }

    public function testReturnsTheCustomersStatus(): void
    {
        $status = $this->createStub(CreditLimitStatusInterface::class);
        $status->method('getCreditLimit')->willReturn(1000.0);
        $status->method('getUsedCredit')->willReturn(250.0);
        $status->method('getAvailableCredit')->willReturn(750.0);
        $status->method('getUtilizationPercent')->willReturn(25.0);

        $this->creditLimitManagement->expects(self::once())->method('getMyStatus')->willReturn($status);

        self::assertSame(
            [
                'credit_limit' => 1000.0,
                'used_credit' => 250.0,
                'available_credit' => 750.0,
                'utilization_percent' => 25.0,
            ],
            $this->resolve(42)
        );
    }

    public function testThrowsGraphQlAuthorizationExceptionForAGuestWithoutEverCallingTheService(): void
    {
        $this->creditLimitManagement->expects(self::never())->method('getMyStatus');

        $this->expectException(GraphQlAuthorizationException::class);

        $this->resolve(0);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testWrapsAnUnexpectedNoSuchEntityExceptionFromTheService(): void
    {
        $this->creditLimitManagement->method('getMyStatus')
            ->willThrowException(new NoSuchEntityException(__('No authenticated customer for this request.')));

        $this->expectException(GraphQlNoSuchEntityException::class);

        $this->resolve(42);
    }
}
