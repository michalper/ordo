<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\FrequencyCapManager;
use Ordo\Automation\Model\ResourceModel\MessageLog\Collection as MessageLogCollection;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class FrequencyCapManagerTest extends TestCase
{
    private Config&\PHPUnit\Framework\MockObject\MockObject $config;
    private MessageLogCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $collectionFactory;
    private FrequencyCapManager $manager;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->collectionFactory = $this->createMock(MessageLogCollectionFactory::class);
        $this->manager = new FrequencyCapManager($this->config, $this->collectionFactory);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasCapacityIsAlwaysTrueWhenCapDisabled(): void
    {
        $this->config->method('isFrequencyCapEnabled')->willReturn(false);

        $this->collectionFactory->expects(self::never())->method('create');

        self::assertTrue($this->manager->hasCapacity(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasCapacityIsTrueWhenMaxMessagesIsZeroOrNegative(): void
    {
        $this->config->method('isFrequencyCapEnabled')->willReturn(true);
        $this->config->method('getFrequencyCapMaxMessages')->willReturn(0);

        $this->collectionFactory->expects(self::never())->method('create');

        self::assertTrue($this->manager->hasCapacity(42));
    }

    private function stubCollection(int $size): MessageLogCollection
    {
        $collection = $this->createMock(MessageLogCollection::class);
        $collection->method('addCustomerFilter')->willReturnSelf();
        $collection->method('addSentSinceFilter')->willReturnSelf();
        $collection->method('addRealSendAttemptFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn($size);

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasCapacityIsTrueWhenUnderTheCap(): void
    {
        $this->config->method('isFrequencyCapEnabled')->willReturn(true);
        $this->config->method('getFrequencyCapMaxMessages')->willReturn(5);
        $this->config->method('getFrequencyCapWindowHours')->willReturn(24);

        $collection = $this->stubCollection(4);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertTrue($this->manager->hasCapacity(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasCapacityIsFalseWhenAtTheCap(): void
    {
        $this->config->method('isFrequencyCapEnabled')->willReturn(true);
        $this->config->method('getFrequencyCapMaxMessages')->willReturn(5);
        $this->config->method('getFrequencyCapWindowHours')->willReturn(24);

        $collection = $this->stubCollection(5);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertFalse($this->manager->hasCapacity(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasCapacityFiltersByCustomerAndWindowAndExcludesNonSendAttempts(): void
    {
        $this->config->method('isFrequencyCapEnabled')->willReturn(true);
        $this->config->method('getFrequencyCapMaxMessages')->willReturn(5);
        $this->config->method('getFrequencyCapWindowHours')->willReturn(24);

        $collection = $this->createMock(MessageLogCollection::class);
        $collection->expects(self::once())->method('addCustomerFilter')->with(42)->willReturnSelf();
        $collection->expects(self::once())->method('addSentSinceFilter')->willReturnSelf();
        $collection->expects(self::once())->method('addRealSendAttemptFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(0);
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->manager->hasCapacity(42);
    }
}
