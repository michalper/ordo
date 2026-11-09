<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Model\LoyaltyPointsManagement;
use Ordo\Automation\Model\LoyaltyPointsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class LoyaltyPointsManagementTest extends TestCase
{
    private LoyaltyPointsManager $loyaltyPointsManager;
    private UserContextInterface $userContext;

    protected function setUp(): void
    {
        $this->loyaltyPointsManager = $this->createMock(LoyaltyPointsManager::class);
        $this->userContext = $this->createStub(UserContextInterface::class);
    }

    private function makeManagement(): LoyaltyPointsManagement
    {
        return new LoyaltyPointsManagement($this->loyaltyPointsManager, $this->userContext);
    }

    public function testGetMyBalanceResolvesTheCurrentCustomerFromUserContext(): void
    {
        $this->userContext->method('getUserId')->willReturn(42);
        $this->loyaltyPointsManager->expects(self::once())->method('getBalance')->with(42)->willReturn(500);

        self::assertSame(500, $this->makeManagement()->getMyBalance());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetMyBalanceThrowsWhenUnauthenticated(): void
    {
        $this->userContext->method('getUserId')->willReturn(null);

        $this->expectException(NoSuchEntityException::class);

        $this->makeManagement()->getMyBalance();
    }

    /**
     * Regression: confirmed live against a real GraphQL request - UserContextInterface
     * returns 0 for a guest, not null, same trap Resolver\CreditLimit's own docblock documents.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGetMyBalanceThrowsWhenUserContextReturnsZero(): void
    {
        $this->userContext->method('getUserId')->willReturn(0);

        $this->expectException(NoSuchEntityException::class);

        $this->makeManagement()->getMyBalance();
    }

    public function testSelfRedeemResolvesTheCurrentCustomerFromUserContext(): void
    {
        $this->userContext->method('getUserId')->willReturn(42);
        $this->loyaltyPointsManager->expects(self::once())->method('redeem')->with(42)->willReturn('LOYALTY-ABC');

        self::assertSame('LOYALTY-ABC', $this->makeManagement()->selfRedeem());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSelfRedeemThrowsWhenUnauthenticated(): void
    {
        $this->userContext->method('getUserId')->willReturn(null);

        $this->expectException(NoSuchEntityException::class);

        $this->makeManagement()->selfRedeem();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSelfRedeemThrowsWhenUserContextReturnsZero(): void
    {
        $this->userContext->method('getUserId')->willReturn(0);

        $this->expectException(NoSuchEntityException::class);

        $this->makeManagement()->selfRedeem();
    }
}
