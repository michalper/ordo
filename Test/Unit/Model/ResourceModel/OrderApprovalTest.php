<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Magento\Framework\DB\Select;
use Ordo\Automation\Model\OrderApproval as OrderApprovalModel;
use Ordo\Automation\Model\ResourceModel\OrderApproval;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class OrderApprovalTest extends AbstractDbTestCase
{
    public function testInitializesWithOrderApprovalTableAndEntityIdField(): void
    {
        $resource = new OrderApproval($this->makeDbContext());

        self::assertSame('ordo_order_approval', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }

    public function testLoadByTokenDoesNothingWhenTokenNotFound(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(false);

        $resource = $this->getMockBuilder(OrderApproval::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection', 'load'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);
        $resource->expects(self::never())->method('load');

        $model = $this->createStub(\Ordo\Automation\Model\OrderApproval::class);
        $resource->loadByToken($model, 'no-such-token');
    }

    public function testLoadByTokenLoadsModelWhenTokenFound(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('7');

        $resource = $this->getMockBuilder(OrderApproval::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection', 'load'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createStub(\Ordo\Automation\Model\OrderApproval::class);
        $resource->expects(self::once())->method('load')->with($model, 7);

        $resource->loadByToken($model, 'real-token');
    }

    /**
     * Regression test for a real race-condition bug a code audit found: approveByToken()/
     * rejectByToken() used to load-then-save without a lock. This atomic conditional UPDATE must
     * only report success when it genuinely won the transition (affected rows > 0), and must
     * update the model's own status/decided_at to match.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testClaimPendingUpdatesModelAndReturnsTrueWhenStillPending(): void
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $sql, $value) => str_replace('?', (string) $value, $sql)
        );
        $connection->method('quote')->willReturnCallback(fn ($value) => "'" . $value . "'");
        $connection->expects(self::once())->method('update')
            ->with(
                'ordo_order_approval',
                self::callback(fn (array $bind) => $bind['status'] === OrderApprovalModel::STATUS_APPROVED
                    && isset($bind['decided_at'])),
                "entity_id = 7 AND status = 'pending'"
            )
            ->willReturn(1);

        $resource = $this->getMockBuilder(OrderApproval::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createMock(OrderApprovalModel::class);
        $model->method('getEntityId')->willReturn(7);
        $model->expects(self::exactly(2))->method('setData');

        self::assertTrue($resource->claimPending($model, OrderApprovalModel::STATUS_APPROVED));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testClaimPendingReturnsFalseAndDoesNotTouchTheModelWhenAlreadyDecided(): void
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $sql, $value) => str_replace('?', (string) $value, $sql)
        );
        $connection->method('quote')->willReturnCallback(fn ($value) => "'" . $value . "'");
        $connection->method('update')->willReturn(0);

        $resource = $this->getMockBuilder(OrderApproval::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createMock(OrderApprovalModel::class);
        $model->method('getEntityId')->willReturn(7);
        $model->expects(self::never())->method('setData');

        self::assertFalse($resource->claimPending($model, OrderApprovalModel::STATUS_REJECTED));
    }

    /**
     * Regression test for a real race-condition bug a code audit found:
     * Cron\EscalateStalePendingApprovals::claimRecipientEmail() used to load-then-save without a
     * lock, so two genuinely overlapping cron runs could both claim and send the same escalation.
     * A tier advance ($tierAdvanced = true) must reflect both reminders_sent and escalation_tier
     * into the model.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testClaimEscalationUpdatesBothFieldsAndReturnsTrueOnATierAdvance(): void
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $sql, $value) => str_replace('?', (string) $value, $sql)
        );
        $connection->expects(self::once())->method('update')
            ->with(
                'ordo_order_approval',
                ['reminders_sent' => 1, 'escalation_tier' => 1],
                'entity_id = 7 AND reminders_sent = 3 AND escalation_tier = 0'
            )
            ->willReturn(1);

        $resource = $this->getMockBuilder(OrderApproval::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createMock(OrderApprovalModel::class);
        $model->method('getEntityId')->willReturn(7);
        $setDataCalls = [];
        $model->method('setData')->willReturnCallback(function ($key, $value) use (&$setDataCalls) {
            $setDataCalls[] = [$key, $value];
        });

        self::assertTrue($resource->claimEscalation($model, 3, 0, 1, 1, true));
        self::assertSame([['reminders_sent', 1], ['escalation_tier', 1]], $setDataCalls);
    }

    /**
     * A same-tier reminder ($tierAdvanced = false) must only reflect reminders_sent into the
     * model - escalation_tier is untouched, matching the pre-atomic-claim code's own distinction
     * (Cron\EscalateStalePendingApprovals::execute()'s rollback only restores escalation_tier
     * when a claim actually advanced it).
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testClaimEscalationOnlyUpdatesRemindersSentOnASameTierReminder(): void
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $sql, $value) => str_replace('?', (string) $value, $sql)
        );
        $connection->expects(self::once())->method('update')
            ->with(
                'ordo_order_approval',
                ['reminders_sent' => 1, 'escalation_tier' => 0],
                'entity_id = 7 AND reminders_sent = 0 AND escalation_tier = 0'
            )
            ->willReturn(1);

        $resource = $this->getMockBuilder(OrderApproval::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createMock(OrderApprovalModel::class);
        $model->method('getEntityId')->willReturn(7);
        $setDataCalls = [];
        $model->method('setData')->willReturnCallback(function ($key, $value) use (&$setDataCalls) {
            $setDataCalls[] = [$key, $value];
        });

        self::assertTrue($resource->claimEscalation($model, 0, 0, 1, 0, false));
        self::assertSame([['reminders_sent', 1]], $setDataCalls);
    }

    /**
     * A genuinely overlapping cron run already claimed this approval first - the conditional
     * UPDATE's WHERE no longer matches (0 affected rows), and this call must not touch the model
     * at all.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testClaimEscalationReturnsFalseAndDoesNotTouchTheModelWhenAlreadyClaimedByAnotherRun(): void
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $sql, $value) => str_replace('?', (string) $value, $sql)
        );
        $connection->method('update')->willReturn(0);

        $resource = $this->getMockBuilder(OrderApproval::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createMock(OrderApprovalModel::class);
        $model->method('getEntityId')->willReturn(7);
        $model->expects(self::never())->method('setData');

        self::assertFalse($resource->claimEscalation($model, 0, 0, 1, 0, false));
    }

    /**
     * Regression test for a full-repo /code-review finding: claimEscalation() writes via a raw
     * UPDATE that never touches the model's own origData, so a plain setData()+save() rollback
     * would see the reverted values as already matching origData and write nothing at all. This
     * must issue its own unconditional UPDATE instead, regardless of what origData thinks
     * changed, and reflect both fields into the model either way.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testRollbackEscalationWritesBothFieldsUnconditionally(): void
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $sql, $value) => str_replace('?', (string) $value, $sql)
        );
        $connection->expects(self::once())->method('update')
            ->with('ordo_order_approval', ['reminders_sent' => 3, 'escalation_tier' => 0], 'entity_id = 7');

        $resource = $this->getMockBuilder(OrderApproval::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createMock(OrderApprovalModel::class);
        $model->method('getEntityId')->willReturn(7);
        $setDataCalls = [];
        $model->method('setData')->willReturnCallback(function ($key, $value) use (&$setDataCalls) {
            $setDataCalls[] = [$key, $value];
        });

        $resource->rollbackEscalation($model, 3, 0);

        self::assertSame([['reminders_sent', 3], ['escalation_tier', 0]], $setDataCalls);
    }
}
