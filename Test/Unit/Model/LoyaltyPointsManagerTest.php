<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CouponGenerator;
use Ordo\Automation\Model\LoyaltyPointsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LoyaltyPointsManagerTest extends TestCase
{
    private AdapterInterface $connection;
    private ResourceConnection $resourceConnection;
    private Config $config;
    private CouponGenerator $couponGenerator;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);

        $this->resourceConnection = $this->createStub(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->config = $this->createStub(Config::class);
        $this->couponGenerator = $this->createMock(CouponGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeManager(): LoyaltyPointsManager
    {
        return new LoyaltyPointsManager(
            $this->resourceConnection,
            $this->config,
            $this->couponGenerator,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetBalanceSumsThePointsDelta(): void
    {
        $this->connection->method('fetchOne')->willReturn('750');

        self::assertSame(750, $this->makeManager()->getBalance(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetBalanceReturnsZeroWhenTheCustomerHasNoRows(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        self::assertSame(0, $this->makeManager()->getBalance(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEarnForOrderDoesNothingWhenDisabled(): void
    {
        $this->config->method('isLoyaltyPointsEnabled')->willReturn(false);

        $this->connection->expects(self::never())->method('insert');

        $this->makeManager()->earnForOrder(42, 100, 99.95);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEarnForOrderDoesNothingWhenAlreadyEarnedForThisOrder(): void
    {
        $this->config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $this->connection->method('fetchOne')->willReturn('1'); // hasEarnedForOrder() -> true

        $this->connection->expects(self::never())->method('insert');

        $this->makeManager()->earnForOrder(42, 100, 99.95);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEarnForOrderDoesNothingWhenTheComputedPointsAreZero(): void
    {
        $this->config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $this->config->method('getLoyaltyPointsPerDollar')->willReturn(1);
        $this->connection->method('fetchOne')->willReturn('0'); // hasEarnedForOrder() -> false

        $this->connection->expects(self::never())->method('insert');

        $this->makeManager()->earnForOrder(42, 100, 0.40);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEarnForOrderInsertsAnEarnRowForTheComputedPoints(): void
    {
        $this->config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $this->config->method('getLoyaltyPointsPerDollar')->willReturn(2);
        $this->connection->method('fetchOne')->willReturn('0'); // hasEarnedForOrder() -> false

        $this->connection->expects(self::once())->method('insert')
            ->with('ordo_loyalty_points_ledger', self::callback(static function (array $row): bool {
                return $row['customer_id'] === 42
                    && $row['type'] === LoyaltyPointsManager::TYPE_EARN
                    && $row['points_delta'] === 199
                    && $row['order_id'] === 100;
            }));

        $this->makeManager()->earnForOrder(42, 100, 99.95);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRedeemThrowsWhenDisabled(): void
    {
        $this->config->method('isLoyaltyPointsEnabled')->willReturn(false);

        $this->expectException(LocalizedException::class);

        $this->makeManager()->redeem(42);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRedeemThrowsWhenNotConfigured(): void
    {
        $this->config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $this->config->method('getLoyaltyPointsRedemptionCost')->willReturn(500);
        $this->config->method('getLoyaltyPointsRedemptionRuleId')->willReturn(0);

        $this->expectException(LocalizedException::class);

        $this->makeManager()->redeem(42);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRedeemThrowsWhenBalanceIsBelowTheCost(): void
    {
        $this->config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $this->config->method('getLoyaltyPointsRedemptionCost')->willReturn(500);
        $this->config->method('getLoyaltyPointsRedemptionRuleId')->willReturn(7);
        $this->connection->method('fetchOne')->willReturn('100');

        $this->couponGenerator->expects(self::never())->method('generate');
        $this->expectException(LocalizedException::class);

        $this->makeManager()->redeem(42);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRedeemGeneratesACouponAndInsertsARedeemRow(): void
    {
        $this->config->method('isLoyaltyPointsEnabled')->willReturn(true);
        $this->config->method('getLoyaltyPointsRedemptionCost')->willReturn(500);
        $this->config->method('getLoyaltyPointsRedemptionRuleId')->willReturn(7);
        $this->connection->method('fetchOne')->willReturn('600');
        $this->couponGenerator->expects(self::once())->method('generate')->with(7, 'LOYALTY')
            ->willReturn('LOYALTY-ABC123');

        $this->connection->expects(self::once())->method('insert')
            ->with('ordo_loyalty_points_ledger', self::callback(static function (array $row): bool {
                return $row['customer_id'] === 42
                    && $row['type'] === LoyaltyPointsManager::TYPE_REDEEM
                    && $row['points_delta'] === -500
                    && $row['coupon_code'] === 'LOYALTY-ABC123';
            }));

        self::assertSame('LOYALTY-ABC123', $this->makeManager()->redeem(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExpireOldEarnedPointsSkipsInsertingWhenBalanceIsAlreadyZero(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => 42, 'points_delta' => 100, 'created_at' => '2026-01-01 00:00:00'],
        ]);
        $this->connection->method('fetchOne')->willReturn('0'); // getBalance() -> 0

        $this->connection->expects(self::once())->method('update')
            ->with('ordo_loyalty_points_ledger', self::isArray(), ['entity_id = ?' => 1]);

        $insertedTypes = [];
        $this->connection->method('insert')->willReturnCallback(function (string $table, array $row) use (&$insertedTypes) {
            $insertedTypes[] = $row['type'];
        });

        $this->makeManager()->expireOldEarnedPoints(365);

        self::assertSame([], $insertedTypes);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExpireOldEarnedPointsExpiresAtMostTheCurrentBalance(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => 42, 'points_delta' => 100, 'created_at' => '2026-01-01 00:00:00'],
        ]);
        $this->connection->method('fetchOne')->willReturn('30'); // getBalance() -> 30, below the row's own 100

        $this->connection->expects(self::once())->method('insert')
            ->with('ordo_loyalty_points_ledger', self::callback(static function (array $row): bool {
                return $row['type'] === LoyaltyPointsManager::TYPE_EXPIRE && $row['points_delta'] === -30;
            }));
        $this->connection->expects(self::once())->method('update')
            ->with('ordo_loyalty_points_ledger', self::isArray(), ['entity_id = ?' => 1]);

        $this->makeManager()->expireOldEarnedPoints(365);
    }
}
