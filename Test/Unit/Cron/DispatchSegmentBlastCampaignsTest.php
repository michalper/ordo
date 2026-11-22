<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Cron\DispatchSegmentBlastCampaigns;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as TriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class DispatchSegmentBlastCampaignsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    /**
     * @param int $alreadyClaimedCount 0 = free to claim, >0 = already claimed (never re-fires)
     */
    private function makeConnection(int $alreadyClaimedCount = 0): AdapterInterface
    {
        $select = $this->createStub(\Magento\Framework\DB\Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnCallback(fn (string $s) => "`{$s}`");
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $text, $value) => str_replace('?', "'{$value}'", $text)
        );
        $connection->method('fetchOne')->willReturnCallback(
            fn ($query) => is_string($query) && str_contains($query, 'GET_LOCK') ? 1 : $alreadyClaimedCount
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
        ?SegmentMemberResolver $segmentMemberResolver = null,
        ?CampaignDispatcher $dispatcher = null,
        ?LoggerInterface $logger = null
    ): DispatchSegmentBlastCampaigns {
        return new DispatchSegmentBlastCampaigns(
            $config,
            $triggerCollectionFactory,
            $segmentMemberResolver ?? $this->createStub(SegmentMemberResolver::class),
            $dispatcher ?? $this->createStub(CampaignDispatcher::class),
            new ReminderLogStore($resourceConnection),
            $this->makeCronRunLogger($logger ?? $this->createStub(LoggerInterface::class))
        );
    }

    public function testExecuteSkipsEntirelyWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSegmentBlastTriggerEnabled')->willReturn(false);

        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->expects(self::never())->method('create');

        $this->makeCron(
            $config,
            $this->createStub(ResourceConnection::class),
            $triggerCollectionFactory
        )->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDispatchesScheduledTriggerForEverySegmentMember(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSegmentBlastTriggerEnabled')->willReturn(true);

        $trigger = $this->makeTrigger(1, 5, '{"segment_id": "3"}');
        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));

        $resolver = $this->createMock(SegmentMemberResolver::class);
        $resolver->expects(self::once())->method('getMatchingCustomerIds')->with(3)->willReturn([42]);

        $connection = $this->makeConnection();
        $connection->expects(self::once())->method('insert');

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatchScheduledTrigger')
            ->with(5, [
                'customer_id' => 42,
                'trigger_event' => CampaignTriggerInterface::TRIGGER_SEGMENT_BLAST,
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 segment_blast'));

        $this->makeCron(
            $config,
            $this->makeResourceConnection($connection),
            $triggerCollectionFactory,
            $resolver,
            $dispatcher,
            $logger
        )->execute();
    }

    /**
     * No (or a non-numeric) segment_id must never resolve/dispatch - a misconfigured/incomplete
     * campaign save should fail closed.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsATriggerWithNoSegmentIdParam(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSegmentBlastTriggerEnabled')->willReturn(true);

        $trigger = $this->makeTrigger(1, 5, '{}');
        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));

        $resolver = $this->createMock(SegmentMemberResolver::class);
        $resolver->expects(self::never())->method('getMatchingCustomerIds');

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatchScheduledTrigger');

        $this->makeCron(
            $config,
            $this->createStub(ResourceConnection::class),
            $triggerCollectionFactory,
            $resolver,
            $dispatcher
        )->execute();
    }

    /**
     * scheduled_at in the future must not dispatch yet.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsATriggerNotYetDue(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSegmentBlastTriggerEnabled')->willReturn(true);

        $future = date('Y-m-d H:i:s', time() + 3600);
        $trigger = $this->makeTrigger(1, 5, json_encode(['segment_id' => '3', 'scheduled_at' => $future]));
        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));

        $resolver = $this->createMock(SegmentMemberResolver::class);
        $resolver->expects(self::never())->method('getMatchingCustomerIds');

        $this->makeCron(
            $config,
            $this->createStub(ResourceConnection::class),
            $triggerCollectionFactory,
            $resolver
        )->execute();
    }

    /**
     * A customer already claimed (dispatched before, on an earlier scan) must never dispatch
     * again - the whole point of the one-dispatch-per-pair-forever claim.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNotRedispatchAnAlreadyClaimedCustomer(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSegmentBlastTriggerEnabled')->willReturn(true);

        $trigger = $this->makeTrigger(1, 5, '{"segment_id": "3"}');
        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));

        $resolver = $this->createMock(SegmentMemberResolver::class);
        $resolver->method('getMatchingCustomerIds')->willReturn([42]);

        $connection = $this->makeConnection(alreadyClaimedCount: 1);
        $connection->expects(self::never())->method('insert');

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatchScheduledTrigger');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 segment_blast'));

        $this->makeCron(
            $config,
            $this->makeResourceConnection($connection),
            $triggerCollectionFactory,
            $resolver,
            $dispatcher,
            $logger
        )->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRollsBackClaimAndLogsFailureWhenDispatchThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSegmentBlastTriggerEnabled')->willReturn(true);

        $trigger = $this->makeTrigger(1, 5, '{"segment_id": "3"}');
        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([$trigger]));

        $resolver = $this->createMock(SegmentMemberResolver::class);
        $resolver->method('getMatchingCustomerIds')->willReturn([42]);

        $connection = $this->makeConnection();
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
            $resolver,
            $dispatcher,
            $logger
        )->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteContinuesOtherTriggersWhenOneThrowsWhileScanning(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isSegmentBlastTriggerEnabled')->willReturn(true);

        $brokenTrigger = $this->createStub(CampaignTrigger::class);
        $brokenTrigger->method('getEntityId')->willReturn(1);
        $brokenTrigger->method('getCampaignId')->willReturn(5);
        $brokenTrigger->method('getParams')->willThrowException(new \RuntimeException('corrupt params'));

        $goodTrigger = $this->makeTrigger(2, 6, '{"segment_id": "3"}');

        $triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn(
            $this->makeTriggerCollection([$brokenTrigger, $goodTrigger])
        );

        $resolver = $this->createMock(SegmentMemberResolver::class);
        $resolver->method('getMatchingCustomerIds')->willReturn([42]);

        $connection = $this->makeConnection();

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatchScheduledTrigger')->with(6, self::isArray());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 segment_blast'));

        $this->makeCron(
            $config,
            $this->makeResourceConnection($connection),
            $triggerCollectionFactory,
            $resolver,
            $dispatcher,
            $logger
        )->execute();
    }
}
