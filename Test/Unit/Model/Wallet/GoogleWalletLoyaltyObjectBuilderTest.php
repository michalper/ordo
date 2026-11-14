<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Wallet;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\LoyaltyPointsManager;
use Ordo\Automation\Model\LoyaltyTierCalculator;
use Ordo\Automation\Model\Wallet\GoogleWalletLoyaltyObjectBuilder;
use PHPUnit\Framework\TestCase;

class GoogleWalletLoyaltyObjectBuilderTest extends TestCase
{
    private LoyaltyPointsManager $loyaltyPointsManager;
    private LoyaltyTierCalculator $loyaltyTierCalculator;
    private CustomerScoreManager $customerScoreManager;
    private Config $config;
    private GoogleWalletLoyaltyObjectBuilder $builder;

    protected function setUp(): void
    {
        $this->loyaltyPointsManager = $this->createMock(LoyaltyPointsManager::class);
        $this->loyaltyTierCalculator = $this->createMock(LoyaltyTierCalculator::class);
        $this->customerScoreManager = $this->createMock(CustomerScoreManager::class);
        $this->config = $this->createStub(Config::class);

        $this->config->method('getWalletPassGoogleIssuerId')->willReturn('3388000000022199568');
        $this->config->method('getWalletPassGoogleClassId')->willReturn('rewards_class');

        $this->builder = new GoogleWalletLoyaltyObjectBuilder(
            $this->loyaltyPointsManager,
            $this->loyaltyTierCalculator,
            $this->customerScoreManager,
            $this->config
        );
    }

    public function testBuildsTheLoyaltyObjectShapeGoogleWalletExpects(): void
    {
        $this->loyaltyPointsManager->expects(self::once())->method('getBalance')->with(42)->willReturn(750);
        $this->customerScoreManager->expects(self::once())->method('getScore')->with(42)->willReturn(600);
        $this->loyaltyTierCalculator->expects(self::once())->method('getTierForScore')->with(600)
            ->willReturn(LoyaltyTierCalculator::GOLD);
        $this->loyaltyTierCalculator->expects(self::once())->method('getTierLabel')->with(LoyaltyTierCalculator::GOLD)
            ->willReturn('Gold');

        $object = $this->builder->build(42);

        self::assertSame([
            'id' => '3388000000022199568.42',
            'classId' => '3388000000022199568.rewards_class',
            'state' => 'ACTIVE',
            'accountId' => '42',
            'loyaltyPoints' => [
                'label' => 'Points',
                'balance' => ['int' => 750],
            ],
            'secondaryLoyaltyPoints' => [
                'label' => 'Tier',
                'balance' => ['string' => 'Gold'],
            ],
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => '42',
            ],
        ], $object);
    }

    public function testUsesTheRealTierDerivationForALowerScore(): void
    {
        $this->loyaltyPointsManager->expects(self::once())->method('getBalance')->with(7)->willReturn(0);
        $this->customerScoreManager->expects(self::once())->method('getScore')->with(7)->willReturn(10);
        $this->loyaltyTierCalculator->expects(self::once())->method('getTierForScore')->with(10)
            ->willReturn(LoyaltyTierCalculator::BRONZE);
        $this->loyaltyTierCalculator->expects(self::once())->method('getTierLabel')
            ->with(LoyaltyTierCalculator::BRONZE)->willReturn('Bronze');

        $object = $this->builder->build(7);

        self::assertSame(0, $object['loyaltyPoints']['balance']['int']);
        self::assertSame('Bronze', $object['secondaryLoyaltyPoints']['balance']['string']);
        self::assertSame('7', $object['accountId']);
    }
}
