<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\OrderApproval;

class OrderApprovalTest extends AbstractModelTestCase
{
    private function makeModel(): OrderApproval
    {
        return new OrderApproval($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testIsPendingWhenStatusPending(): void
    {
        $model = $this->makeModel();
        $model->setData('status', OrderApproval::STATUS_PENDING);

        self::assertTrue($model->isPending());
    }

    public function testIsNotPendingWhenStatusApproved(): void
    {
        $model = $this->makeModel();
        $model->setData('status', OrderApproval::STATUS_APPROVED);

        self::assertFalse($model->isPending());
    }

    public function testIsNotPendingWhenStatusRejected(): void
    {
        $model = $this->makeModel();
        $model->setData('status', OrderApproval::STATUS_REJECTED);

        self::assertFalse($model->isPending());
    }

    public function testEntityIdRoundTrip(): void
    {
        $model = $this->makeModel();
        self::assertNull($model->getEntityId());

        $model->setData('entity_id', '5');
        self::assertSame(5, $model->getEntityId());
    }

    public function testOrderIdReadFromData(): void
    {
        $model = $this->makeModel();
        $model->setData('order_id', '7');

        self::assertSame(7, $model->getOrderId());
    }

    public function testAdminEmailReadFromData(): void
    {
        $model = $this->makeModel();
        $model->setData('admin_email', 'admin@example.com');

        self::assertSame('admin@example.com', $model->getAdminEmail());
    }

    public function testStatusReadFromData(): void
    {
        $model = $this->makeModel();
        $model->setData('status', OrderApproval::STATUS_APPROVED);

        self::assertSame(OrderApproval::STATUS_APPROVED, $model->getStatus());
    }

    public function testRemindersSentReadFromData(): void
    {
        $model = $this->makeModel();
        $model->setData('reminders_sent', '2');

        self::assertSame(2, $model->getRemindersSent());
    }

    public function testTimestampsReadFromData(): void
    {
        $model = $this->makeModel();
        $model->setData('created_at', '2026-01-01 00:00:00');
        $model->setData('decided_at', '2026-01-02 00:00:00');

        self::assertSame('2026-01-01 00:00:00', $model->getCreatedAt());
        self::assertSame('2026-01-02 00:00:00', $model->getDecidedAt());
    }
}
