<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Model\MessageSendRetry;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry as MessageSendRetryResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MessageSendRetryTest extends AbstractDbTestCase
{
    public function testInitializesWithMessageSendRetryTableAndEntityIdField(): void
    {
        $resource = new MessageSendRetryResource($this->makeDbContext());

        self::assertSame('ordo_message_send_retry', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }

    /**
     * Same atomic-claim-before-execute reasoning as ResourceModel\CampaignActionRetry::claim() -
     * bumping next_retry_at forward is itself the claim, taking the row out of any concurrent
     * cron run's "due" filter the instant this UPDATE commits.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testClaimUpdatesModelAndReturnsTrueWhenRowWasStillDue(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $sql, $value) => str_replace('?', (string) $value, $sql)
        );
        $connection->expects(self::once())->method('update')
            ->with(
                'ordo_message_send_retry',
                ['next_retry_at' => '2026-01-01 00:05:00'],
                'entity_id = 5 AND next_retry_at <= 2026-01-01 00:00:00'
            )
            ->willReturn(1);

        $resource = $this->getMockBuilder(MessageSendRetryResource::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createMock(MessageSendRetry::class);
        $model->method('getEntityId')->willReturn(5);
        $model->expects(self::once())->method('setNextRetryAt')->with('2026-01-01 00:05:00');

        self::assertTrue($resource->claim($model, '2026-01-01 00:00:00', '2026-01-01 00:05:00'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testClaimReturnsFalseAndDoesNotTouchTheModelWhenAlreadyClaimed(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $sql, $value) => str_replace('?', (string) $value, $sql)
        );
        $connection->method('update')->willReturn(0);

        $resource = $this->getMockBuilder(MessageSendRetryResource::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createMock(MessageSendRetry::class);
        $model->method('getEntityId')->willReturn(5);
        $model->expects(self::never())->method('setNextRetryAt');

        self::assertFalse($resource->claim($model, '2026-01-01 00:00:00', '2026-01-01 00:05:00'));
    }
}
