<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Frontend;

use Magento\Customer\Model\Session as CustomerSession;
use Ordo\Automation\Block\Frontend\LoyaltyWalletPassViewModel;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\LoyaltyPointsManager;
use Ordo\Automation\Model\LoyaltyTierCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class LoyaltyWalletPassViewModelTest extends TestCase
{
    private CustomerSession $customerSession;
    private LoyaltyPointsManager $loyaltyPointsManager;
    private LoyaltyTierCalculator $loyaltyTierCalculator;
    private CustomerScoreManager $customerScoreManager;
    private Config $config;

    protected function setUp(): void
    {
        $this->customerSession = $this->createStub(CustomerSession::class);
        $this->loyaltyPointsManager = $this->createMock(LoyaltyPointsManager::class);
        $this->loyaltyTierCalculator = $this->createMock(LoyaltyTierCalculator::class);
        $this->customerScoreManager = $this->createMock(CustomerScoreManager::class);
        $this->config = $this->createStub(Config::class);
    }

    private function makeViewModel(): LoyaltyWalletPassViewModel
    {
        return new LoyaltyWalletPassViewModel(
            $this->customerSession,
            $this->loyaltyPointsManager,
            $this->loyaltyTierCalculator,
            $this->customerScoreManager,
            $this->config
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsAvailableFalseWhenFeatureDisabled(): void
    {
        $this->config->method('isWalletPassEnabled')->willReturn(false);
        $this->customerSession->method('getCustomerId')->willReturn(42);

        self::assertFalse($this->makeViewModel()->isAvailable());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsAvailableFalseWhenNoCustomerLoggedIn(): void
    {
        $this->config->method('isWalletPassEnabled')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(null);

        self::assertFalse($this->makeViewModel()->isAvailable());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsAvailableTrueWhenEnabledAndLoggedIn(): void
    {
        $this->config->method('isWalletPassEnabled')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(42);

        self::assertTrue($this->makeViewModel()->isAvailable());
    }

    public function testGetPointsBalanceReadsTheRealLedgerForTheLoggedInCustomer(): void
    {
        $this->customerSession->method('getCustomerId')->willReturn(42);
        $this->loyaltyPointsManager->expects(self::once())->method('getBalance')->with(42)->willReturn(300);
        $this->loyaltyTierCalculator->expects(self::never())->method('getTierForScore');
        $this->customerScoreManager->expects(self::never())->method('getScore');

        self::assertSame(300, $this->makeViewModel()->getPointsBalance());
    }

    public function testGetTierLabelDerivesFromTheCustomersRealScore(): void
    {
        $this->customerSession->method('getCustomerId')->willReturn(42);
        $this->customerScoreManager->expects(self::once())->method('getScore')->with(42)->willReturn(600);
        $this->loyaltyTierCalculator->expects(self::once())->method('getTierForScore')->with(600)
            ->willReturn(LoyaltyTierCalculator::GOLD);
        $this->loyaltyTierCalculator->expects(self::once())->method('getTierLabel')
            ->with(LoyaltyTierCalculator::GOLD)->willReturn('Gold');
        $this->loyaltyPointsManager->expects(self::never())->method('getBalance');

        self::assertSame('Gold', $this->makeViewModel()->getTierLabel());
    }
}
