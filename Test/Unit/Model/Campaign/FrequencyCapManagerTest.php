<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\AttributeInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
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
    private CustomerRepositoryInterface $customerRepository;
    private FrequencyCapManager $manager;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->collectionFactory = $this->createMock(MessageLogCollectionFactory::class);
        $this->customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $this->manager = new FrequencyCapManager(
            $this->config,
            $this->collectionFactory,
            $this->customerRepository
        );
    }

    /**
     * Rebuilds $this->customerRepository (and $this->manager) from scratch rather than
     * reconfiguring the existing stub - PHPUnit stubs keep every ->method() call as a separate
     * matcher, so a second call to stub the same method on the same object doesn't replace the
     * first one, it races it.
     */
    private function stubCustomerOwnMax(?int $max): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        if ($max === null) {
            $customer->method('getCustomAttribute')->willReturn(null);
        } else {
            $attribute = $this->createStub(AttributeInterface::class);
            $attribute->method('getValue')->willReturn((string) $max);
            $customer->method('getCustomAttribute')->willReturn($attribute);
        }

        $this->customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $this->customerRepository->method('getById')->willReturn($customer);
        $this->manager = new FrequencyCapManager(
            $this->config,
            $this->collectionFactory,
            $this->customerRepository
        );
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
        $this->stubCustomerOwnMax(null);

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
        $this->stubCustomerOwnMax(null);

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
        $this->stubCustomerOwnMax(null);

        $collection = $this->createMock(MessageLogCollection::class);
        $collection->expects(self::once())->method('addCustomerFilter')->with(42)->willReturnSelf();
        $collection->expects(self::once())->method('addSentSinceFilter')->willReturnSelf();
        $collection->expects(self::once())->method('addRealSendAttemptFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(0);
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->manager->hasCapacity(42);
    }

    /**
     * The customer's own preference (3) is tighter than the admin cap (5) - 3 already-sent
     * messages must exhaust it, even though the admin cap alone would still allow 2 more.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testHasCapacityUsesTheCustomersOwnTighterPreferenceOverTheAdminCap(): void
    {
        $this->config->method('isFrequencyCapEnabled')->willReturn(true);
        $this->config->method('getFrequencyCapMaxMessages')->willReturn(5);
        $this->config->method('getFrequencyCapWindowHours')->willReturn(24);
        $this->stubCustomerOwnMax(3);

        $collection = $this->stubCollection(3);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertFalse($this->manager->hasCapacity(42));
    }

    /**
     * A customer's own preference can only restrict contact, never expand beyond the admin cap -
     * a looser customer value (10) must never let more than the admin's own 5 through.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testHasCapacityNeverLetsALooserCustomerPreferenceExpandTheAdminCap(): void
    {
        $this->config->method('isFrequencyCapEnabled')->willReturn(true);
        $this->config->method('getFrequencyCapMaxMessages')->willReturn(5);
        $this->config->method('getFrequencyCapWindowHours')->willReturn(24);
        $this->stubCustomerOwnMax(10);

        $collection = $this->stubCollection(5);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertFalse($this->manager->hasCapacity(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasCapacityIgnoresAZeroOrNegativeCustomerPreference(): void
    {
        $this->config->method('isFrequencyCapEnabled')->willReturn(true);
        $this->config->method('getFrequencyCapMaxMessages')->willReturn(5);
        $this->config->method('getFrequencyCapWindowHours')->willReturn(24);
        $this->stubCustomerOwnMax(0);

        $collection = $this->stubCollection(4);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertTrue($this->manager->hasCapacity(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasCapacityFallsBackToAdminCapWhenCustomerCannotBeLoaded(): void
    {
        $this->config->method('isFrequencyCapEnabled')->willReturn(true);
        $this->config->method('getFrequencyCapMaxMessages')->willReturn(5);
        $this->config->method('getFrequencyCapWindowHours')->willReturn(24);
        $this->customerRepository = $this->createStub(CustomerRepositoryInterface::class);
        $this->customerRepository->method('getById')->willThrowException(
            new NoSuchEntityException(__('No such entity.'))
        );
        $this->manager = new FrequencyCapManager(
            $this->config,
            $this->collectionFactory,
            $this->customerRepository
        );

        $collection = $this->stubCollection(4);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertTrue($this->manager->hasCapacity(42));
    }
}
