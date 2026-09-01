<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\CreditLimitCalculator;
use Ordo\Automation\Model\CreditLimitManagement;
use Ordo\Automation\Model\CreditLimitStatus;
use Ordo\Automation\Model\CreditLimitStatusFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CreditLimitManagementTest extends TestCase
{
    private CreditLimitCalculator $calculator;
    private UserContextInterface $userContext;
    private CreditLimitManagement $management;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(CreditLimitCalculator::class);
        $statusFactory = $this->createStub(CreditLimitStatusFactory::class);
        $statusFactory->method('create')->willReturnCallback(fn () => new CreditLimitStatus());
        $this->userContext = $this->createStub(UserContextInterface::class);

        $this->management = new CreditLimitManagement($this->calculator, $statusFactory, $this->userContext);
    }

    public function testGetMyStatusUsesAuthenticatedCustomerId(): void
    {
        $this->userContext->method('getUserId')->willReturn(7);
        $this->calculator->method('getCreditLimit')->with(7)->willReturn(1000.0);
        $this->calculator->method('getUsedCredit')->with(7)->willReturn(300.0);
        $this->calculator->method('getUtilizationPercent')->with(7)->willReturn(30.0);

        $status = $this->management->getMyStatus();

        self::assertSame(1000.0, $status->getCreditLimit());
        self::assertSame(300.0, $status->getUsedCredit());
        self::assertSame(700.0, $status->getAvailableCredit());
        self::assertSame(30.0, $status->getUtilizationPercent());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetMyStatusThrowsWhenNoAuthenticatedCustomer(): void
    {
        $this->userContext->method('getUserId')->willReturn(null);

        $this->expectException(NoSuchEntityException::class);
        $this->management->getMyStatus();
    }

    public function testGetStatusForCustomerAvailableCreditCanBeNegative(): void
    {
        $this->calculator->method('getCreditLimit')->with(9)->willReturn(500.0);
        $this->calculator->method('getUsedCredit')->with(9)->willReturn(750.0);
        $this->calculator->method('getUtilizationPercent')->with(9)->willReturn(150.0);

        $status = $this->management->getStatusForCustomer(9);

        self::assertSame(-250.0, $status->getAvailableCredit());
        self::assertSame(150.0, $status->getUtilizationPercent());
    }
}
