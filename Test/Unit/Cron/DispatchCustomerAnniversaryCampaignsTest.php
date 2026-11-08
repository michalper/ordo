<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Cron\DispatchCustomerAnniversaryCampaigns;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as TriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class DispatchCustomerAnniversaryCampaignsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    /**
     * @param int[] $matchingCustomerIds
     */
    private function makeConnection(array $matchingCustomerIds, int $alreadyFiredCount = 0): AdapterInterface
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $text, $value) => str_replace('?', "'{$value}'", $text)
        );
        $connection->method('fetchCol')->willReturn($matchingCustomerIds);
        // ReminderLogStore::claim(): the GET_LOCK acquisition succeeds, and its re-check counts
        // $alreadyFiredCount matching claim rows (0 = free to claim/not yet fired this year).
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 1 : $alreadyFiredCount
        );

        return $connection;
    }

    private function makeResourceConnection(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return $resourceConnection;
    }

    /**
     * @param CampaignTrigger[] $triggers
     */
    private function makeTriggerCollection(array $triggers): TriggerCollection
    {
        $collection = $this->createStub(TriggerCollection::class);
        $collection->method('addTriggerEventsFilter')->willReturnSelf();
        $collection->method('addEnabledCampaignFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($triggers));

        return $collection;
    }

    private function makeTrigger(int $entityId, int $campaignId, string $paramsJson): CampaignTrigger
    {
        $trigger = $this->createStub(CampaignTrigger::class);
        $trigger->method('getEntityId')->willReturn($entityId);
        $trigger->method('getCampaignId')->willReturn($campaignId);
        $trigger->method('getParams')->willReturn(json_decode($paramsJson, true));

        return $trigger;
    }

    private function makeCron(
        Config $config,
        ResourceConnection $resourceConnection,
        TriggerCollectionFactory $triggerCollectionFactory,
        ?CampaignDispatcher $dispatcher = null,
        ?LoggerInterface $logger = null
    ): DispatchCustomerAnniversaryCampaigns {
        return new DispatchCustomerAnniversaryCampaigns(
            $config,
            $resourceConnection,
            $triggerCollectionFactory,
            $dispatcher ?? $this->createStub(CampaignDispatcher::class),
            new ReminderLogStore($resourceConnection),
            $this->makeCronRunLogger($logger ?? $this->createStub(LoggerInterface::class))
        );
    }

    public function testExecuteSkipsEntirelyWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCustomerAnniversaryTriggerEnabled')->willReturn(false);

        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->expects(self::never())->method('create');

        $this->makeCron(
            $config,
            $this->createStub(ResourceConnection::class),
            $triggerCollectionFactory
        )->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDispatchesScheduledTriggerForEachMatchingCustomer(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCustomerAnniversaryTriggerEnabled')->willReturn(true);

        $trigger = $this->makeTrigger(1, 5, '{"date_source": "dob"}');
        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));

        $connection = $this->makeConnection([42]);
        $connection->expects(self::once())->method('insert');

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatchScheduledTrigger')
            ->with(5, [
                'customer_id' => 42,
                'trigger_event' => CampaignTriggerInterface::TRIGGER_CUSTOMER_ANNIVERSARY,
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 customer_anniversary'));

        $this->makeCron(
            $config,
            $this->makeResourceConnection($connection),
            $triggerCollectionFactory,
            $dispatcher,
            $logger
        )->execute();
    }

    /**
     * A trigger with no (or an unrecognized) date_source param must never scan or dispatch at
     * all - a misconfigured/incomplete campaign save should fail closed, not guess a source.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsATriggerWithNoDateSourceParam(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCustomerAnniversaryTriggerEnabled')->willReturn(true);

        $trigger = $this->makeTrigger(1, 5, '{}');
        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatchScheduledTrigger');

        $this->makeCron($config, $resourceConnection, $triggerCollectionFactory, $dispatcher)->execute();
    }

    /**
     * Already fired for this (customer, campaign) pair this calendar year - must not dispatch
     * again until next year.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNotRefireForTheSameCalendarYear(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCustomerAnniversaryTriggerEnabled')->willReturn(true);

        $trigger = $this->makeTrigger(1, 5, '{"date_source": "registration"}');
        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));

        $connection = $this->makeConnection([42], alreadyFiredCount: 1);
        $connection->expects(self::never())->method('insert');

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatchScheduledTrigger');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 customer_anniversary'));

        $this->makeCron(
            $config,
            $this->makeResourceConnection($connection),
            $triggerCollectionFactory,
            $dispatcher,
            $logger
        )->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRollsBackClaimAndLogsFailureWhenDispatchThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCustomerAnniversaryTriggerEnabled')->willReturn(true);

        $trigger = $this->makeTrigger(1, 5, '{"date_source": "dob"}');
        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));

        $connection = $this->makeConnection([42]);
        $connection->expects(self::once())->method('insert');
        $connection->expects(self::once())->method('delete');

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->method('dispatchScheduledTrigger')->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $this->makeCron(
            $config,
            $this->makeResourceConnection($connection),
            $triggerCollectionFactory,
            $dispatcher,
            $logger
        )->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteContinuesOtherTriggersWhenOneThrowsWhileScanning(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isCustomerAnniversaryTriggerEnabled')->willReturn(true);

        $brokenTrigger = $this->createStub(CampaignTrigger::class);
        $brokenTrigger->method('getEntityId')->willReturn(1);
        $brokenTrigger->method('getCampaignId')->willReturn(5);
        $brokenTrigger->method('getParams')->willThrowException(new \RuntimeException('corrupt params'));

        $goodTrigger = $this->makeTrigger(2, 6, '{"date_source": "dob"}');

        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn(
            $this->makeTriggerCollection([$brokenTrigger, $goodTrigger])
        );

        $connection = $this->makeConnection([42]);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatchScheduledTrigger')->with(6, self::isArray());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 customer_anniversary'));

        $this->makeCron(
            $config,
            $this->makeResourceConnection($connection),
            $triggerCollectionFactory,
            $dispatcher,
            $logger
        )->execute();
    }
}
