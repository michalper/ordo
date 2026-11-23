<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\Referral as ReferralModel;
use Ordo\Automation\Model\ResourceModel\Referral;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ReferralTest extends AbstractDbTestCase
{
    public function testInitializesWithReferralTableAndEntityIdField(): void
    {
        $resource = new Referral($this->makeDbContext());

        self::assertSame('ordo_referral', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }

    /**
     * Regression test for a real race-condition bug a code audit found:
     * ReferralManager::markConvertedAndGetReferrer() used to load-then-save without a lock. This
     * atomic conditional UPDATE must only report success when it genuinely won the transition
     * (affected rows > 0), and must update the model's own status/converted_at to match - same
     * pattern Model\ResourceModel\OrderApproval::claimPending() already uses for its own
     * equivalent race.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testClaimConvertedUpdatesModelAndReturnsTrueWhenStillPending(): void
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $sql, $value) => str_replace('?', (string) $value, $sql)
        );
        $connection->method('quote')->willReturnCallback(fn ($value) => "'" . $value . "'");
        $connection->expects(self::once())->method('update')
            ->with(
                'ordo_referral',
                self::callback(fn (array $bind) => $bind['status'] === ReferralModel::STATUS_CONVERTED
                    && isset($bind['converted_at'])),
                "entity_id = 9 AND status = 'pending'"
            )
            ->willReturn(1);

        $resource = $this->getMockBuilder(Referral::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createMock(ReferralModel::class);
        $model->method('getEntityId')->willReturn(9);
        $model->expects(self::exactly(2))->method('setData');

        self::assertTrue($resource->claimConverted($model));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testClaimConvertedReturnsFalseAndDoesNotTouchTheModelWhenAlreadyConverted(): void
    {
        $connection = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $sql, $value) => str_replace('?', (string) $value, $sql)
        );
        $connection->method('quote')->willReturnCallback(fn ($value) => "'" . $value . "'");
        $connection->method('update')->willReturn(0);

        $resource = $this->getMockBuilder(Referral::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createMock(ReferralModel::class);
        $model->method('getEntityId')->willReturn(9);
        $model->expects(self::never())->method('setData');

        self::assertFalse($resource->claimConverted($model));
    }
}
