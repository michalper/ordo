<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\VisitorAggregator;
use Ordo\Automation\Model\VisitorTagManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class VisitorAggregatorTest extends TestCase
{
    private function makeAggregator(
        Config $config,
        ResourceConnection $resourceConnection,
        ?CustomerTagManager $customerTagManager = null,
        ?VisitorTagManager $visitorTagManager = null
    ): VisitorAggregator {
        return new VisitorAggregator(
            $config,
            $resourceConnection,
            $customerTagManager ?? $this->createStub(CustomerTagManager::class),
            $visitorTagManager ?? $this->createStub(VisitorTagManager::class)
        );
    }

    public function testAggregateForCustomerDoesNothingWhenTrackingDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrackingEnabled')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('addTag');

        $aggregator = $this->makeAggregator($config, $resourceConnection, $tagManager);
        $aggregator->aggregateForCustomer(42);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAggregateForCustomerTagsWhenThresholdMet(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrackingEnabled')->willReturn(true);
        $config->method('getTrackingViewThreshold')->willReturn(3);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('having')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['event_type' => 'view_category', 'event_key' => '15', 'occurrences' => 3],
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::once())->method('addTag')->with(42, 'viewed_view_category_15');

        $aggregator = $this->makeAggregator($config, $resourceConnection, $tagManager);
        $aggregator->aggregateForCustomer(42);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAggregateForCustomerTagsElementClickedAsClickedNotViewed(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrackingEnabled')->willReturn(true);
        $config->method('getTrackingViewThreshold')->willReturn(3);
        $config->method('getTrackingClickThreshold')->willReturn(1);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['event_type' => 'element_clicked', 'event_key' => 'newsletter-signup', 'occurrences' => 1],
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::once())->method('addTag')->with(42, 'clicked_newsletter-signup');

        $aggregator = $this->makeAggregator($config, $resourceConnection, $tagManager);
        $aggregator->aggregateForCustomer(42);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAggregateForCustomerSkipsElementClickedBelowClickThreshold(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrackingEnabled')->willReturn(true);
        $config->method('getTrackingClickThreshold')->willReturn(2);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['event_type' => 'element_clicked', 'event_key' => 'newsletter-signup', 'occurrences' => 1],
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::never())->method('addTag');

        $aggregator = $this->makeAggregator($config, $resourceConnection, $tagManager);
        $aggregator->aggregateForCustomer(42);
    }

    public function testAggregateForVisitorDoesNothingWhenTrackingDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrackingEnabled')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $visitorTagManager = $this->createMock(VisitorTagManager::class);
        $visitorTagManager->expects(self::never())->method('addTag');

        $aggregator = $this->makeAggregator($config, $resourceConnection, null, $visitorTagManager);
        $aggregator->aggregateForVisitor('v1');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAggregateForVisitorTagsWhenThresholdMet(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrackingEnabled')->willReturn(true);
        $config->method('getTrackingViewThreshold')->willReturn(3);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('having')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['event_type' => 'view_category', 'event_key' => '15', 'occurrences' => 3],
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $visitorTagManager = $this->createMock(VisitorTagManager::class);
        $visitorTagManager->expects(self::once())->method('addTag')->with('v1', 'viewed_view_category_15');

        $aggregator = $this->makeAggregator($config, $resourceConnection, null, $visitorTagManager);
        $aggregator->aggregateForVisitor('v1');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAggregateForVisitorSkipsRowBelowViewThreshold(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrackingEnabled')->willReturn(true);
        $config->method('getTrackingViewThreshold')->willReturn(3);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['event_type' => 'view_category', 'event_key' => '15', 'occurrences' => 2],
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $visitorTagManager = $this->createMock(VisitorTagManager::class);
        $visitorTagManager->expects(self::never())->method('addTag');

        $aggregator = $this->makeAggregator($config, $resourceConnection, null, $visitorTagManager);
        $aggregator->aggregateForVisitor('v1');
    }
}
